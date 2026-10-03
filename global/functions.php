<?php
/**
 * Core helpers for the tracker plugin. Three groups of functions live here:
 *
 * 1. Settings (tr_get_settings/tr_save_setting/tr_get_default_settings/
 *    tr_updateOrCreateTable) - same shape as plugins/paperboy's own
 *    pb_*() equivalents.
 * 2. Capture path (tr_capture_hit + everything it calls directly: bot
 *    filtering, GeoIP, visitor hashing) - runs on (almost) every frontend
 *    request via hooks-frontend/register.php, so this half is deliberately
 *    kept cheap. See install/schema-config.php for why the raw IP is never
 *    persisted.
 * 3. Aggregation (tr_aggregate_pending + tr_recompute_day + the UA/referrer/
 *    campaign classification helpers) - runs lazily, at most every few
 *    minutes, triggered from backend/start.php. This is where the
 *    "expensive" interpretation work happens, never on the visitor's own
 *    request.
 */

/* -------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------- */

function tr_get_settings(): array {
    global $tracker_db;

    if (!isset($tracker_db)) {
        return [];
    }
    $result = $tracker_db->select('settings', ['key', 'value']);
    return array_column($result, 'value', 'key');
}

function tr_save_setting(string $key, $value): bool {
    global $tracker_db;

    $exists = $tracker_db->has('settings', ['key' => $key]);

    if ($exists) {
        $result = $tracker_db->update('settings', [
            'value' => (string) $value
        ], ['key' => $key]);
        return $result->rowCount() > 0;
    }

    return (bool) $tracker_db->insert('settings', [
        'key' => $key,
        'value' => (string) $value
    ]);
}

function tr_get_default_settings(): array {
    return [
        // Per-site secret, auto-generated once on install - mixed into the
        // daily visitor hash (see tr_visitor_hash()) so it can't be
        // reproduced/rainbow-tabled by anyone outside this installation.
        'salt' => bin2hex(random_bytes(16)),
        // Auto-detected on install, editable under Addons > Tracker >
        // Einstellungen - used to exclude the site's own internal
        // navigation from the "Referrer" breakdown (see tr_referrer_domain()).
        'site_host' => preg_replace('/^www\./i', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))),
        // How long raw_hits rows survive before tr_purge_old_raw_hits()
        // deletes them (0 = keep forever) - see backend/settings.php.
        'retention_days' => 90,
        'bot_filter_enabled' => 1,
        'bot_filter_patterns' => tr_default_bot_patterns(),
        // Off by default - no GeoIP data is bundled until an admin uploads
        // an IP-range CSV (see backend/settings.php).
        'geoip_enabled' => 0,
        // Watermark for tr_aggregate_pending(): highest raw_hits.id already
        // folded into daily_totals/daily_pageviews/daily_breakdown.
        'agg_watermark_id' => 0,
        // Throttle for tr_maybe_aggregate() - skips re-running aggregation
        // if it last ran less than a few minutes ago, so opening the stats
        // page repeatedly doesn't repeatedly redo the same work.
        'last_aggregated_at' => 0,
    ];
}

function tr_default_bot_patterns(): string {
    return implode('|', [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners',
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'discordbot',
        'preview', 'headless', 'curl', 'wget', 'python-requests',
        'go-http-client', 'okhttp', 'axios', 'scrapy', 'ahrefsbot',
        'semrushbot', 'mj12bot', 'dotbot', 'petalbot', 'bingpreview',
        'uptimerobot', 'pingdom', 'gtmetrix',
    ]);
}

/**
 * Ensure table exists and matches current schema.
 */
function tr_updateOrCreateTable(string $table_name, array $expected_columns): void {
    global $tracker_db;

    $tables = $tracker_db->query("
    SELECT name FROM sqlite_master
    WHERE type='table' AND name = '$table_name' ")->fetchAll();

    if (empty($tables)) {
        $col_definitions = [];
        foreach ($expected_columns as $col_name => $col_type) {
            $col_definitions[] = "$col_name $col_type";
        }
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (" . implode(', ', $col_definitions) . ")";
        $tracker_db->query($sql);
        echo "Created table $table_name<br>";
        return;
    }

    $tableInfo = $tracker_db->query("PRAGMA table_info($table_name)")->fetchAll();
    $existing_columns = array_column($tableInfo, 'name');

    foreach ($expected_columns as $col_name => $col_type) {
        if (!in_array($col_name, $existing_columns, true)) {
            $sql = "ALTER TABLE $table_name ADD COLUMN $col_name $col_type";
            $result = $tracker_db->query($sql);
            if ($result !== false) {
                echo "Added column $col_name to $table_name<br>";
            }
        }
    }
}

function tr_hint_icon(string $hint): string {
    if ($hint === '') {
        return '';
    }

    return ' <a href="#" onclick="return false;" tabindex="0" class="text-muted"'
        .' data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-content="'.htmlspecialchars($hint, ENT_QUOTES).'">'
        .'<i class="bi bi-question-circle"></i></a>';
}

/* -------------------------------------------------------------------
 * Capture path - runs on (almost) every frontend request. Keep this half
 * cheap: no UA parsing, no referrer/campaign classification here, see the
 * Aggregation section below for where that actually happens.
 * ---------------------------------------------------------------- */

/**
 * Registered on the core 'page.display.after' hook by
 * hooks-frontend/register.php. Fires once per normal frontend page render,
 * AFTER the page's HTML has already been handed to $smarty->display() -
 * see app/app.php. Deliberately server-side only, no frontend JavaScript
 * at all (see the plugin's project memory for why this replaced an earlier
 * sendBeacon()-based design).
 */
function tr_capture_hit(array $context = []): void {
    global $tracker_db;

    // Nothing to do if the plugin's database hasn't been created yet (see
    // global/bootstrap.php - only ever auto-created from a real ACP
    // request, never as a side effect of a random frontend visitor).
    if (!isset($tracker_db)) {
        return;
    }

    // Hand the response back to the visitor immediately - everything below
    // this line runs after PHP-FPM has already closed the connection, so
    // none of it can add to the page's own load time. Silently a no-op
    // under SAPIs without FPM (e.g. the PHP built-in server), where the
    // write below then happens synchronously instead - still correct, just
    // not latency-free in that environment.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Admins browsing/editing their own site should never show up in their
    // own stats. $_SESSION is already populated at this point - app.php's
    // own preview-access check (app/app.php) relies on the same value.
    if (($_SESSION['user_class'] ?? '') === 'administrator') {
        return;
    }

    $settings = tr_get_settings();
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (!empty($settings['bot_filter_enabled']) && tr_is_bot($ua, (string) ($settings['bot_filter_patterns'] ?? ''))) {
        return;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $parts = explode('?', $uri, 2);
    $url = $parts[0] ?: '/';
    $query_string = $parts[1] ?? '';

    // GeoIP has to run here, inline, rather than being deferred to
    // aggregation like everything else - it needs the raw IP as input, and
    // the raw IP is deliberately never written to disk (see
    // install/schema-config.php). It goes out of scope at the end of this
    // function without ever being persisted; only the resulting
    // non-identifying two-letter country code is stored.
    $country_code = null;
    if (!empty($settings['geoip_enabled']) && $ip !== '') {
        $country_code = tr_geoip_lookup($ip);
    }

    $tracker_db->insert('raw_hits', [
        'url' => mb_substr($url, 0, 1000),
        'query_string' => mb_substr($query_string, 0, 1000),
        'referrer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
        'user_agent' => mb_substr($ua, 0, 500),
        'accept_language' => mb_substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 100),
        'country_code' => $country_code,
        'visitor_hash' => tr_visitor_hash($ip, $ua, (string) ($settings['salt'] ?? '')),
    ]);
}

/**
 * Daily-rotating, per-site-salted hash - no cookie, no persistent
 * identifier. Two hits from the same IP+UA on the same day hash identically
 * (so aggregation can count unique visitors and attribute a campaign source
 * to only the first hit of a same-day visit); the next day, the same
 * visitor gets a different hash.
 */
function tr_visitor_hash(string $ip, string $ua, string $salt): string {
    return hash('sha256', $ip.'|'.$ua.'|'.date('Y-m-d').'|'.$salt);
}

/**
 * @return string|null Two-letter country code, or null if GeoIP data isn't
 *                      loaded, the address is IPv6 (not supported by the
 *                      bundled IPv4 range table in v1), or no range matches.
 */
function tr_geoip_lookup(string $ip): ?string {
    global $tracker_db;

    $long = tr_ipv4_to_int($ip);
    if ($long === null) {
        return null;
    }

    // Deliberately NOT "range_start <= X AND range_end >= X" as a single
    // WHERE (measured ~4.5ms/lookup against the real 357k-row dataset,
    // called synchronously in the capture path - too slow at any real
    // traffic volume): with two independent range conditions and only an
    // index on range_start, SQLite can't seek straight to the answer and
    // falls back to scanning every row matching range_start <= X, checking
    // range_end afterward.
    //
    // Since ip_ranges rows are non-overlapping (each import produces
    // contiguous, non-overlapping ranges - see global/geoip-import.php),
    // the enclosing range for X, if any, is always the one with the
    // largest range_start still <= X. "ORDER BY range_start DESC LIMIT 1"
    // lets SQLite use the index on range_start as a direct backward seek
    // for that single row (measured ~0.02ms/lookup, 200x faster) instead of
    // a range scan. range_end is still checked in PHP afterward, in case X
    // falls in a gap the imported data doesn't cover at all (e.g. reserved
    // ranges) - without that check, an uncovered address just past a real
    // range would incorrectly inherit that range's country.
    $row = $tracker_db->get('ip_ranges', ['range_end', 'country_code'], [
        'range_start[<=]' => $long,
        'ORDER' => ['range_start' => 'DESC'],
    ]);

    if (!$row || (int) $row['range_end'] < $long) {
        return null;
    }

    return $row['country_code'];
}

/**
 * IPv4 address -> unsigned 32-bit integer (0..4294967295), or null for
 * anything that isn't a plain IPv4 address (IPv6, malformed input).
 *
 * Deliberately NOT ip2long()+sprintf('%u', ...): ip2long() can return a
 * negative signed int for addresses >= 128.0.0.0, and %u's "make it
 * unsigned" behaviour is platform-int-size-dependent (wrong 64-bit-wide
 * result on a 64-bit build, only correct as a 32-bit wraparound). unpack('N')
 * on inet_pton()'s raw 4-byte form gives the same 0..4294967295 value
 * portably on any platform PHP actually runs on today (64-bit) - this must
 * match whatever encoding install/geoip-import.php uses to build
 * ip_ranges.range_start/range_end, or lookups silently never match.
 */
function tr_ipv4_to_int(string $ip): ?int {
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 4) {
        return null;
    }
    return unpack('N', $packed)[1];
}

function tr_is_bot(string $ua, string $patterns): bool {
    if (trim($ua) === '') {
        // No User-Agent at all is itself a strong bot/script signal - real
        // browsers always send one.
        return true;
    }
    if (trim($patterns) === '') {
        return false;
    }
    // Admin-editable (backend/settings.php) - a malformed pattern shouldn't
    // ever be able to break capture for every visitor, so a bad regex is
    // treated as "no match" rather than fatal.
    return (bool) @preg_match('/'.$patterns.'/i', $ua);
}

/* -------------------------------------------------------------------
 * Aggregation - lazy, throttled, never runs on a visitor's own request.
 * ---------------------------------------------------------------- */

/**
 * Entry point called from backend/start.php on every stats page load.
 * Actually does work at most once per $min_interval_seconds - cheap to call
 * unconditionally, since the common case is just one settings read.
 */
function tr_maybe_aggregate(int $min_interval_seconds = 300): void {
    global $tracker_db;
    if (!isset($tracker_db)) {
        return;
    }

    $settings = tr_get_settings();
    $last = (int) ($settings['last_aggregated_at'] ?? 0);
    if ((time() - $last) < $min_interval_seconds) {
        return;
    }

    tr_aggregate_pending();
}

/**
 * Folds every raw_hits row written since the last run into
 * daily_totals/daily_pageviews/daily_breakdown, then purges raw_hits rows
 * older than the configured retention window.
 *
 * Implementation note: rather than incrementally merging counts into the
 * existing daily rows (which would need a running per-day set of visitor
 * hashes to get unique-visitor counts right across multiple runs on the
 * same day), every date touched by new rows is fully recomputed from
 * scratch and its existing daily_* rows replaced wholesale - see
 * tr_recompute_day(). Simpler and correct by construction; the tradeoff is
 * re-reading the day's raw_hits on every run instead of just the new
 * increment, acceptable for a lazily/infrequently triggered job.
 */
function tr_aggregate_pending(): void {
    global $tracker_db;
    if (!isset($tracker_db)) {
        return;
    }

    $settings = tr_get_settings();
    $watermark = (int) ($settings['agg_watermark_id'] ?? 0);

    $rows = $tracker_db->select('raw_hits', ['id', 'ts'], ['id[>]' => $watermark]);

    if ($rows) {
        $max_id = $watermark;
        $dates = [];
        foreach ($rows as $r) {
            $max_id = max($max_id, (int) $r['id']);
            $dates[substr((string) $r['ts'], 0, 10)] = true;
        }

        foreach (array_keys($dates) as $date) {
            tr_recompute_day($date);
        }

        tr_save_setting('agg_watermark_id', $max_id);
    }

    tr_purge_old_raw_hits((int) ($settings['retention_days'] ?? 90));
    tr_save_setting('last_aggregated_at', time());
}

/**
 * Fully recomputes and replaces daily_totals/daily_pageviews/daily_breakdown
 * for one calendar date from that date's raw_hits rows. See
 * tr_aggregate_pending()'s comment for why this replaces rather than
 * increments.
 */
function tr_recompute_day(string $date): void {
    global $tracker_db;

    $rows = $tracker_db->select('raw_hits', '*', [
        'ts[~]' => $date.'%',
        'ORDER' => ['id' => 'ASC'],
    ]);

    $tracker_db->delete('daily_totals', ['date' => $date]);
    $tracker_db->delete('daily_pageviews', ['date' => $date]);
    $tracker_db->delete('daily_breakdown', ['date' => $date]);

    if (!$rows) {
        return;
    }

    $settings = tr_get_settings();
    $site_host = (string) ($settings['site_host'] ?? '');

    $pageviews = 0;
    $visitors = [];
    $per_url = [];   // url => ['views'=>n, 'visitors'=>[hash=>true]]
    $breakdown = []; // "dimension\0value" => count
    $seen_visitor_today = [];

    foreach ($rows as $hit) {
        $pageviews++;
        $vh = (string) $hit['visitor_hash'];
        $visitors[$vh] = true;

        $url = (string) $hit['url'];
        $per_url[$url]['views'] = ($per_url[$url]['views'] ?? 0) + 1;
        $per_url[$url]['visitors'][$vh] = true;

        $ua = tr_parse_user_agent((string) $hit['user_agent']);
        tr_bump_breakdown($breakdown, 'browser', $ua['browser']);
        tr_bump_breakdown($breakdown, 'os', $ua['os']);
        tr_bump_breakdown($breakdown, 'device', $ua['device']);

        if (!empty($hit['country_code'])) {
            tr_bump_breakdown($breakdown, 'country', (string) $hit['country_code']);
        }

        $ref_domain = tr_referrer_domain((string) $hit['referrer'], $site_host);
        if ($ref_domain !== '') {
            tr_bump_breakdown($breakdown, 'referrer', $ref_domain);
        }

        // Source/campaign attribution only for the chronologically first
        // hit of this visitor on this day - see the plugin's project
        // memory ("Attribution granularity"): keeps a multi-page visit from
        // diluting/multiplying its own campaign count. $rows is ordered by
        // id ASC above, so "first seen in this loop" is chronological.
        if (!isset($seen_visitor_today[$vh])) {
            $seen_visitor_today[$vh] = true;
            $source = tr_classify_source((string) $hit['referrer'], (string) $hit['query_string']);
            tr_bump_breakdown($breakdown, 'source', $source);
        }
    }

    $tracker_db->insert('daily_totals', [
        'date' => $date,
        'pageviews' => $pageviews,
        'visitors' => count($visitors),
    ]);

    foreach ($per_url as $url => $agg) {
        $tracker_db->insert('daily_pageviews', [
            'date' => $date,
            'url' => $url,
            'views' => $agg['views'],
            'visitors' => count($agg['visitors']),
        ]);
    }

    foreach ($breakdown as $key => $count) {
        [$dimension, $value] = explode("\0", $key, 2);
        $tracker_db->insert('daily_breakdown', [
            'date' => $date,
            'dimension' => $dimension,
            'value' => $value,
            'count' => $count,
        ]);
    }
}

function tr_bump_breakdown(array &$acc, string $dimension, string $value): void {
    $value = $value !== '' ? $value : 'Unbekannt';
    $key = $dimension."\0".$value;
    $acc[$key] = ($acc[$key] ?? 0) + 1;
}

/**
 * retention_days <= 0 means "keep forever" - an explicit admin choice
 * (backend/settings.php), not just an unset value.
 */
function tr_purge_old_raw_hits(int $retention_days): void {
    global $tracker_db;
    if ($retention_days <= 0) {
        return;
    }
    $cutoff = date('Y-m-d H:i:s', strtotime('-'.$retention_days.' days'));
    $tracker_db->delete('raw_hits', ['ts[<]' => $cutoff]);
}

/* -------------------------------------------------------------------
 * Lightweight user-agent / referrer / campaign classification. Simple,
 * regex-based, "good enough for an overview dashboard" - not a full
 * device-detection library. Documented as a known v1 simplification.
 * ---------------------------------------------------------------- */

function tr_parse_user_agent(string $ua): array {
    return [
        'browser' => tr_detect_browser($ua),
        'os' => tr_detect_os($ua),
        'device' => tr_detect_device($ua),
    ];
}

function tr_detect_browser(string $ua): string {
    if ($ua === '') return 'Unbekannt';
    if (preg_match('/Edg\//i', $ua)) return 'Edge';
    if (preg_match('/OPR\/|Opera/i', $ua)) return 'Opera';
    if (preg_match('/SamsungBrowser/i', $ua)) return 'Samsung Internet';
    if (preg_match('/FxiOS/i', $ua)) return 'Firefox'; // Firefox on iOS
    if (preg_match('/Firefox\//i', $ua)) return 'Firefox';
    if (preg_match('/CriOS/i', $ua)) return 'Chrome'; // Chrome on iOS
    if (preg_match('/Chrome\//i', $ua)) return 'Chrome';
    if (preg_match('/Version\/.*Safari\//i', $ua)) return 'Safari';
    if (preg_match('/MSIE|Trident/i', $ua)) return 'Internet Explorer';
    return 'Sonstige';
}

function tr_detect_os(string $ua): string {
    if ($ua === '') return 'Unbekannt';
    if (preg_match('/Windows NT/i', $ua)) return 'Windows';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'iOS';
    if (preg_match('/Mac OS X/i', $ua)) return 'macOS';
    if (preg_match('/Android/i', $ua)) return 'Android';
    if (preg_match('/CrOS/i', $ua)) return 'ChromeOS';
    if (preg_match('/Linux/i', $ua)) return 'Linux';
    return 'Sonstige';
}

function tr_detect_device(string $ua): string {
    if ($ua === '') return 'Unbekannt';
    if (preg_match('/iPad|(?<!Mobile )Tablet/i', $ua)) return 'Tablet';
    if (preg_match('/Mobi|iPhone|Android.*Mobile/i', $ua)) return 'Mobile';
    return 'Desktop';
}

/**
 * Priority: Google/Microsoft/Meta Ads click IDs first (these can carry the
 * same referrer as an organic search/social visit, so they have to be
 * checked before falling back to referrer-based classification) -> generic
 * UTM campaign params -> referrer domain (known search engines / social
 * networks / plain referral) -> "Direkt" if there's no referrer at all.
 */
function tr_classify_source(string $referrer, string $query_string): string {
    parse_str($query_string, $params);

    if (isset($params['gclid']) || isset($params['gbraid']) || isset($params['wbraid'])) {
        return 'Google Ads';
    }
    if (isset($params['msclkid'])) {
        return 'Microsoft Ads';
    }
    if (isset($params['fbclid'])) {
        return 'Meta Ads';
    }
    if (!empty($params['utm_source'])) {
        $source = (string) $params['utm_source'];
        $medium = strtolower((string) ($params['utm_medium'] ?? ''));
        if (in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'display'], true)) {
            return 'Bezahlt: '.$source;
        }
        $campaign = (string) ($params['utm_campaign'] ?? '');
        return 'Kampagne: '.$source.($campaign !== '' ? ' ('.$campaign.')' : '');
    }

    $host = tr_host_from_url($referrer);
    if ($host === '') {
        return 'Direkt';
    }

    foreach (tr_known_search_engines() as $needle => $label) {
        if (str_contains($host, $needle)) {
            return 'Suche: '.$label;
        }
    }
    foreach (tr_known_social_domains() as $needle => $label) {
        if (str_contains($host, $needle)) {
            return 'Social: '.$label;
        }
    }

    return 'Referral: '.$host;
}

/**
 * @return string '' for an empty referrer, an internal (same-site)
 *                referrer, or an unparseable URL - the caller treats an
 *                empty result as "don't count this in the breakdown".
 */
function tr_referrer_domain(string $referrer, string $site_host = ''): string {
    $host = tr_host_from_url($referrer);
    if ($host === '') {
        return '';
    }
    $site_host = preg_replace('/^www\./i', '', strtolower($site_host));
    if ($site_host !== '' && $host === $site_host) {
        return '';
    }
    return $host;
}

function tr_host_from_url(string $url): string {
    if (trim($url) === '') {
        return '';
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return '';
    }
    return preg_replace('/^www\./i', '', strtolower($host));
}

function tr_known_search_engines(): array {
    return [
        'google.' => 'Google',
        'bing.' => 'Bing',
        'duckduckgo.' => 'DuckDuckGo',
        'yahoo.' => 'Yahoo',
        'ecosia.' => 'Ecosia',
        'startpage.' => 'Startpage',
        'yandex.' => 'Yandex',
        'baidu.' => 'Baidu',
    ];
}

function tr_known_social_domains(): array {
    return [
        'facebook.' => 'Facebook',
        'instagram.' => 'Instagram',
        't.co' => 'Twitter/X',
        'twitter.' => 'Twitter/X',
        'x.com' => 'Twitter/X',
        'linkedin.' => 'LinkedIn',
        'pinterest.' => 'Pinterest',
        'tiktok.' => 'TikTok',
        'reddit.' => 'Reddit',
        'youtube.' => 'YouTube',
        'threads.' => 'Threads',
        'mastodon.' => 'Mastodon',
    ];
}
