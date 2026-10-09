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

/**
 * Passed as Medoo's 'command' option (global/bootstrap.php,
 * install/installer.php): wait up to 5s for a competing writer (e.g. a
 * long bot reclassification) instead of failing right away with "database
 * is locked" - frontend hits would otherwise be lost.
 *
 * Only pragmas that never touch the file belong here - Medoo runs these in
 * its constructor and throws if one fails, see tr_configure_db().
 */
function tr_db_pragmas(): array {
    return [
        'PRAGMA busy_timeout = 5000',
    ];
}

/**
 * Connection settings that have to read the database file and can
 * therefore hit a lock - kept out of Medoo's constructor (tr_db_pragmas())
 * so a lock leaves the old setting in place instead of failing the whole
 * connection. Retried on every connection.
 *
 * - journal_mode = WAL: frontend hits keep writing while the backend reads
 *   or aggregates, instead of queueing behind each other. Stored in the
 *   file itself, so once set this is a cheap no-op - checking every time
 *   also switches back a database that was restored from an older copy.
 *   Requires data/ to be writable for the -wal/-shm files next to it.
 * - synchronous = NORMAL: safe in WAL mode (a crash can lose the last
 *   commits, never corrupt the file), saves an fsync per hit. Not stored
 *   in the file, hence per connection.
 */
function tr_configure_db(\Medoo\Medoo $db): void {
    try {
        $mode = $db->pdo->query('PRAGMA journal_mode')->fetchColumn();
        if (strtolower((string) $mode) !== 'wal') {
            $db->pdo->exec('PRAGMA journal_mode = WAL');
        }
        $db->pdo->exec('PRAGMA synchronous = NORMAL');
    } catch (\PDOException $e) {
        // Locked right now - defaults stay in place until the next try.
    }
}

function tr_connect(string $file): \Medoo\Medoo {
    $db = new \Medoo\Medoo([
        'type' => 'sqlite',
        'database' => $file,
        'command' => tr_db_pragmas(),
    ]);
    tr_configure_db($db);
    return $db;
}

function tr_data_dir(): string {
    return dirname(__DIR__).'/data/';
}

function tr_table_exists(\Medoo\Medoo $db, string $table): bool {
    $stmt = $db->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t", [':t' => $table]
    );
    $exists = (bool) $stmt->fetchColumn();
    // Medoo keeps the last statement alive - left half-read, it counts as
    // "in progress" and blocks a later VACUUM (tr_migrate_raw_hits()).
    $stmt->closeCursor();
    return $exists;
}

/* -------------------------------------------------------------------
 * Two database files (since 1.1.2):
 *
 * - tracker.sqlite3: settings, daily_* aggregates, ip_ranges. Small,
 *   written only during aggregation - and the part that can't be rebuilt
 *   once raw_hits has been purged, so it gets a daily snapshot
 *   (tr_backup_main_db()).
 * - tracker-raw.sqlite3: raw_hits only. Large, written on every frontend
 *   hit. Losing it costs at most the hits not aggregated yet.
 *
 * $tracker_db / $tracker_raw_db, both set up in global/bootstrap.php.
 * ---------------------------------------------------------------- */

/**
 * Connection for raw_hits. Returns the main connection itself while the
 * old single-file layout hasn't been migrated yet (backend not opened
 * since the update, see tr_migrate_raw_hits()) - hits keep going to the
 * old table until then. Recreates a missing raw file otherwise (deleted
 * or lost), so capture just continues; tr_aggregate_pending() notices
 * the restarted ids. null if even that fails (e.g. data/ not writable).
 */
function tr_open_raw_db(\Medoo\Medoo $main, string $raw_file): ?\Medoo\Medoo {
    try {
        if (is_file($raw_file)) {
            return tr_connect($raw_file);
        }
        if (tr_table_exists($main, 'raw_hits')) {
            return $main;
        }
        tr_create_raw_db($raw_file);
        return tr_connect($raw_file);
    } catch (\Throwable $e) {
        error_log('tracker: raw database unavailable: '.$e->getMessage());
        return null;
    }
}

/**
 * Builds an empty raw database under a temporary name and renames it into
 * place, so a concurrent request never sees a file without its table.
 */
function tr_create_raw_db(string $raw_file): void {
    require_once dirname(__DIR__).'/install/schema.php';

    $tmp = $raw_file.'.'.bin2hex(random_bytes(4)).'.tmp';
    $pdo = new \PDO('sqlite:'.$tmp);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    foreach (tr_raw_table_sql() as $sql) {
        $pdo->exec($sql);
    }
    $pdo = null;

    if (!rename($tmp, $raw_file)) {
        @unlink($tmp);
        throw new \RuntimeException('could not create '.$raw_file);
    }
}

/**
 * CREATE statements for the raw database, with an optional schema prefix
 * ("raw.") for use on an ATTACHed file.
 *
 * @return string[]
 */
function tr_raw_table_sql(string $schema = ''): array {
    $sql = [];
    foreach (TrackerSchema::getRawTables() as $table => $columns) {
        $defs = [];
        foreach ($columns as $col => $type) {
            $defs[] = "$col $type";
        }
        $sql[] = "CREATE TABLE IF NOT EXISTS {$schema}{$table} (".implode(', ', $defs).')';
    }
    $sql[] = "CREATE INDEX IF NOT EXISTS {$schema}idx_raw_hits_ts ON raw_hits (ts)";
    return $sql;
}

/**
 * One-time move of raw_hits out of tracker.sqlite3 (1.1.1 and older) into
 * tracker-raw.sqlite3. Backend only - can take a few seconds on a large
 * table. Does nothing once the table is gone from the main file.
 *
 * A second connection holds the main file's write lock throughout, so
 * frontend hits still writing to the old table wait (busy_timeout) instead
 * of landing after the copy was taken. Those few that wait past the drop
 * find the table gone and are lost - a one-time handful. The finished
 * copy is renamed into place before the old table is dropped, so a hit
 * arriving in between always finds one or the other.
 */
function tr_migrate_raw_hits(\Medoo\Medoo $main, string $main_file, string $raw_file): void {
    if (!tr_table_exists($main, 'raw_hits')) {
        return;
    }
    if (is_file($raw_file)) {
        // Both exist - a previous run got past the rename but not the drop.
        // The raw file is the live one by now; it already holds every row
        // the old table had at that point.
        $main->pdo->exec('DROP TABLE raw_hits');
        return;
    }

    require_once dirname(__DIR__).'/install/schema.php';
    @set_time_limit(300);

    $tmp = $raw_file.'.migrate.tmp';
    @unlink($tmp);

    $lock = new \PDO('sqlite:'.$main_file);
    $lock->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $lock->exec('PRAGMA busy_timeout = 5000');
    $lock->exec('BEGIN IMMEDIATE');

    try {
        $copy = new \PDO('sqlite:'.$main_file);
        $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $copy->exec('ATTACH DATABASE '.$copy->quote($tmp).' AS raw');
        foreach (tr_raw_table_sql('raw.') as $sql) {
            $copy->exec($sql);
        }

        // Explicit column list - columns added later via ALTER TABLE sit in
        // a different order in old installs than in schema-config.php.
        $existing = array_column($copy->query('PRAGMA main.table_info(raw_hits)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $cols = implode(', ', array_intersect(array_keys(TrackerSchema::getTableColumns('raw_hits')), $existing));
        $copy->exec("INSERT INTO raw.raw_hits ($cols) SELECT $cols FROM main.raw_hits ORDER BY id");

        // Keep the id counter where it was, even past MAX(id) - ids must
        // never repeat, the aggregation watermark relies on that.
        $seq = max(
            (int) $copy->query("SELECT seq FROM main.sqlite_sequence WHERE name = 'raw_hits'")->fetchColumn(),
            (int) $copy->query('SELECT MAX(id) FROM raw.raw_hits')->fetchColumn()
        );
        $copy->exec("DELETE FROM raw.sqlite_sequence WHERE name = 'raw_hits'");
        $copy->exec("INSERT INTO raw.sqlite_sequence (name, seq) VALUES ('raw_hits', $seq)");

        $copy->exec('DETACH DATABASE raw');
        $copy = null;

        if (!rename($tmp, $raw_file)) {
            throw new \RuntimeException('could not rename '.$tmp);
        }

        $lock->exec('DROP TABLE raw_hits');
        $lock->exec('COMMIT');
    } catch (\Throwable $e) {
        if ($lock->inTransaction()) {
            $lock->exec('ROLLBACK');
        }
        $copy = null;
        @unlink($tmp);
        throw $e;
    }
    $lock = null;

    // Give the freed pages back - the main file shrinks to its small,
    // aggregates-only size. Not critical if it fails (locked).
    try {
        $main->pdo->exec('VACUUM');
    } catch (\PDOException $e) {
    }
}

/**
 * Daily snapshot of tracker.sqlite3 (data/tracker-backup.sqlite3) - the
 * aggregates in there are the only data that can't be recomputed once
 * raw_hits has been purged. Called from tr_aggregate_pending(), so only
 * ever in the backend. Written under a temporary name and renamed, so the
 * last good snapshot survives a failed run.
 */
function tr_backup_main_db(): void {
    global $tracker_db;

    $settings = tr_get_settings();
    if (($settings['last_backup_date'] ?? '') === date('Y-m-d')) {
        return;
    }

    $target = tr_data_dir().'tracker-backup.sqlite3';
    $tmp = $target.'.tmp';
    @unlink($tmp);
    try {
        $tracker_db->pdo->exec('VACUUM INTO '.$tracker_db->pdo->quote($tmp));
        if (rename($tmp, $target)) {
            tr_save_setting('last_backup_date', date('Y-m-d'));
        }
    } catch (\PDOException $e) {
        @unlink($tmp);
        error_log('tracker: backup failed: '.$e->getMessage());
    }
}

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
        // Admin-added User-Agent substrings (JSON list, lowercase), on top
        // of the built-in tr_default_bot_patterns() - see backend/bot-ui.php.
        'bot_custom_patterns' => '[]',
        // Requests without Accept-Language / Sec-Fetch-* headers count as
        // bots - catches scrapers that send a normal browser User-Agent
        // (often from rotating IPs, so every hit looks like a new visitor).
        'bot_header_filter' => 1,
        // Chromium UAs ~3 years behind the current Chrome version count as
        // bots - real Chrome auto-updates, scrapers often hardcode an old UA.
        'bot_outdated_filter' => 1,
        // Bot hits are kept in raw_hits (so they can be inspected under
        // "Rohdaten"), but only this long - they never reach the stats.
        'bot_retention_days' => 14,
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
        // Date of the last daily snapshot, see tr_backup_main_db().
        'last_backup_date' => '',
    ];
}

/**
 * Built-in User-Agent substrings (lowercase, plain text - no regex). Lives
 * in code rather than in the settings table so plugin updates can extend
 * it; admins add their own via bot_custom_patterns.
 */
function tr_default_bot_patterns(): array {
    return [
        // generic
        'bot', 'crawl', 'spider', 'slurp', 'scrape', 'fetcher', 'preview',
        'headless', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        // search engines / SEO tools
        'mediapartners', 'bingpreview', 'yandex', 'baiduspider', 'sogou',
        'seznam', 'exabot', 'ia_archiver', 'ahrefs', 'semrush', 'mj12',
        'dotbot', 'petalbot', 'serpstat', 'dataforseo', 'screaming frog',
        'lighthouse', 'gtmetrix', 'pingdom', 'uptimerobot', 'statuscake',
        // AI crawlers
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-web',
        'anthropic-ai', 'perplexity', 'bytespider', 'ccbot', 'amazonbot',
        'applebot', 'google-extended', 'meta-externalagent', 'diffbot',
        'imagesift', 'timpibot', 'cohere-ai', 'youbot',
        // link previews
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'discordbot',
        'slackbot', 'skypeuripreview', 'embedly',
        // HTTP libraries / CLI tools
        'curl', 'wget', 'python-requests', 'python-urllib', 'aiohttp',
        'httpx', 'go-http-client', 'okhttp', 'axios', 'node-fetch', 'undici',
        'guzzlehttp', 'libwww-perl', 'apache-httpclient', 'java/', 'scrapy',
        'postmanruntime', 'insomnia',
    ];
}

/**
 * @return string[] admin-added patterns, lowercase, deduplicated
 */
function tr_custom_bot_patterns(array $settings): array {
    $list = json_decode((string) ($settings['bot_custom_patterns'] ?? '[]'), true);
    if (!is_array($list)) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map(fn($p) => strtolower(trim((string) $p)), $list), fn($p) => $p !== '')));
}

/**
 * Snapshot of the current bot rules, built once per request/run and passed
 * to tr_bot_reason() for every hit.
 */
function tr_bot_rules(array $settings): array {
    return [
        'enabled' => !empty($settings['bot_filter_enabled']),
        'header_filter' => !empty($settings['bot_header_filter']),
        'outdated_filter' => !empty($settings['bot_outdated_filter']),
        // Computed once here, not per hit.
        'chrome_min_major' => tr_chrome_min_major(),
        'patterns' => array_values(array_unique(array_merge(tr_default_bot_patterns(), tr_custom_bot_patterns($settings)))),
    ];
}

/**
 * Why a hit counts as a bot, or null for a (presumed) human visitor. The
 * reason is stored in raw_hits.bot_reason, so the "Rohdaten" tab can show
 * which rule caught what - and tr_reclassify_raw_hits() can re-run it after
 * the rules change.
 *
 * @param int|null $has_sec_fetch 1/0 = Sec-Fetch-* headers present/missing on
 *                                an HTTPS request, null = can't tell (plain
 *                                HTTP - browsers only send them over HTTPS -
 *                                or a hit recorded before 1.1.0)
 */
function tr_bot_reason(string $ua, string $accept_language, ?int $has_sec_fetch, array $rules): ?string {
    if (!$rules['enabled']) {
        return null;
    }
    if (trim($ua) === '') {
        // Real browsers always send a User-Agent.
        return 'no_ua';
    }
    $ua_lc = strtolower($ua);
    foreach ($rules['patterns'] as $pattern) {
        if (str_contains($ua_lc, $pattern)) {
            return 'ua:'.$pattern;
        }
    }
    if (!empty($rules['outdated_filter']) && tr_outdated_chrome_major($ua, $rules['chrome_min_major']) !== null) {
        return 'old_chrome';
    }
    if ($rules['header_filter']) {
        if (trim($accept_language) === '') {
            return 'no_lang';
        }
        if ($has_sec_fetch === 0) {
            return 'no_sec_fetch';
        }
    }
    return null;
}

/**
 * 1/0 whether the current request carries Sec-Fetch-* headers, or null if
 * that can't be judged: browsers only send them to secure origins, so on a
 * plain-HTTP request their absence means nothing. Errs on the lenient side
 * behind a TLS-terminating proxy that doesn't set X-Forwarded-Proto.
 */
function tr_request_sec_fetch_flag(): ?int {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (!$https) {
        return null;
    }
    return (isset($_SERVER['HTTP_SEC_FETCH_MODE']) || isset($_SERVER['HTTP_SEC_FETCH_DEST'])) ? 1 : 0;
}

/**
 * User-Agents of real, current browsers - a custom pattern matching any of
 * these would silently drop real visitors, so backend/writer.php rejects it.
 */
function tr_reference_browser_uas(): array {
    return [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0',
    ];
}

function tr_pattern_hits_browsers(string $pattern): bool {
    foreach (tr_reference_browser_uas() as $ua) {
        if (str_contains(strtolower($ua), $pattern)) {
            return true;
        }
    }
    return false;
}

/**
 * Rough current Chrome major version, derived from the release cadence
 * (Chrome 100 on 2022-03-29, roughly one major every ~4.3 weeks incl.
 * holiday gaps) - no hardcoded "current" version that goes stale.
 */
function tr_estimated_chrome_major(): int {
    return 100 + (int) floor((time() - strtotime('2022-03-29')) / (30.5 * 86400));
}

/**
 * Oldest Chrome major version still counted as a real browser: ~3 years
 * (36 majors) behind current. The margin covers browsers shipping an older
 * Chromium (Samsung Internet) and devices stuck on their last supported
 * Chrome (Android 7: 119, macOS 10.15: 128). Windows 7/8 (109) and macOS
 * 10.13/10.14 (116) fall below it - a negligible share by now.
 */
function tr_chrome_min_major(): int {
    return tr_estimated_chrome_major() - 35;
}

/**
 * Chrome major version of a Chromium UA (Chrome, Edge, Opera, WebViews - all
 * send "Chrome/NNN") if it is below tr_chrome_min_major(), i.e. so old that
 * no auto-updating real browser still sends it - typical for scrapers with
 * a hardcoded UA string. null if not Chromium or reasonably current.
 */
function tr_outdated_chrome_major(string $ua, ?int $min_major = null): ?int {
    if (!preg_match('~Chrome/(\d+)\.~', $ua, $m)) {
        return null;
    }
    $major = (int) $m[1];
    return $major < ($min_major ?? tr_chrome_min_major()) ? $major : null;
}

/**
 * Best guess at a distinctive token for the "Als Bot markieren" form: the
 * first "Name/1.2" product token that isn't standard browser boilerplate,
 * else the exact Chrome version if it is long outdated, else '' (a plain,
 * current browser UA - nothing safe to suggest).
 */
function tr_suggest_bot_pattern(string $ua): string {
    $boring = ['mozilla', 'applewebkit', 'chrome', 'safari', 'version', 'gecko', 'firefox', 'mobile', 'edg', 'opr', 'crios', 'fxios', 'samsungbrowser', 'khtml', 'trident'];
    if (preg_match_all('~([A-Za-z][\w.\-]*)/[\w.]+~', $ua, $m)) {
        foreach ($m[1] as $token) {
            $t = strtolower($token);
            if (!in_array($t, $boring, true) && strlen($t) >= 3 && !tr_pattern_hits_browsers($t)) {
                return $t;
            }
        }
    }
    // Exact full version (e.g. "chrome/101.0.4951.67"), not just the major -
    // as narrow as possible, only this one hardcoded string matches.
    if (tr_outdated_chrome_major($ua) !== null && preg_match('~Chrome/[\d.]+~', $ua, $m)) {
        return strtolower($m[0]);
    }
    return '';
}

/**
 * Ensure table exists and matches current schema.
 */
function tr_updateOrCreateTable(string $table_name, array $expected_columns, ?\Medoo\Medoo $db = null): void {
    global $tracker_db;
    $db = $db ?? $tracker_db;

    $tables = $db->query("
    SELECT name FROM sqlite_master
    WHERE type='table' AND name = '$table_name' ")->fetchAll();

    if (empty($tables)) {
        $col_definitions = [];
        foreach ($expected_columns as $col_name => $col_type) {
            $col_definitions[] = "$col_name $col_type";
        }
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (" . implode(', ', $col_definitions) . ")";
        $db->query($sql);
        echo "Created table $table_name<br>";
        return;
    }

    $tableInfo = $db->query("PRAGMA table_info($table_name)")->fetchAll();
    $existing_columns = array_column($tableInfo, 'name');

    foreach ($expected_columns as $col_name => $col_type) {
        if (!in_array($col_name, $existing_columns, true)) {
            $sql = "ALTER TABLE $table_name ADD COLUMN $col_name $col_type";
            $result = $db->query($sql);
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
    global $tracker_db, $tracker_raw_db;

    // Nothing to do if the plugin's database hasn't been created yet (see
    // global/bootstrap.php - only ever auto-created from a real ACP
    // request, never as a side effect of a random frontend visitor), or
    // the raw file can't be opened (see tr_open_raw_db()).
    if (!isset($tracker_db, $tracker_raw_db)) {
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
    $accept_language = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $has_sec_fetch = tr_request_sec_fetch_flag();

    // Bots are stored too (flagged, never aggregated) instead of being
    // dropped here - otherwise the admin can't see what got filtered, and
    // can't re-evaluate older hits after changing the rules. They're purged
    // after bot_retention_days, see tr_purge_old_raw_hits().
    $bot_reason = tr_bot_reason($ua, $accept_language, $has_sec_fetch, tr_bot_rules($settings));

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

    $row = [
        'url' => mb_substr($url, 0, 1000),
        'query_string' => mb_substr($query_string, 0, 1000),
        'referrer' => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
        'user_agent' => mb_substr($ua, 0, 500),
        'accept_language' => mb_substr($accept_language, 0, 100),
        'has_sec_fetch' => $has_sec_fetch,
        'bot_reason' => $bot_reason,
        'country_code' => $country_code,
        'visitor_hash' => tr_visitor_hash($ip, $ua, (string) ($settings['salt'] ?? '')),
    ];

    try {
        try {
            $tracker_raw_db->insert('raw_hits', $row);
        } catch (\PDOException $e) {
            // Waited for the old table while tr_migrate_raw_hits() moved it
            // into its own file - by now that file is in place.
            if ($tracker_raw_db !== $tracker_db || !str_contains($e->getMessage(), 'no such table')) {
                throw $e;
            }
            $tracker_raw_db = tr_open_raw_db($tracker_db, tr_data_dir().'tracker-raw.sqlite3');
            if ($tracker_raw_db === null || $tracker_raw_db === $tracker_db) {
                throw $e;
            }
            $tracker_raw_db->insert('raw_hits', $row);
        }
    } catch (\PDOException $e) {
        // Only a missing column is repairable here: plugin files were
        // updated but install/updater.php hasn't run yet (it only runs on
        // the next backend visit) - add the columns so frontend hits aren't
        // lost in the meantime. Anything else (e.g. still locked after
        // busy_timeout) must not end up in schema repair - the hit is lost,
        // but logged.
        if (!str_contains($e->getMessage(), 'has no column')) {
            error_log('tracker: hit not stored: '.$e->getMessage());
            return;
        }
        // tr_updateOrCreateTable() echoes progress, which must not leak
        // into the page when there's no FPM to have closed it already.
        ob_start();
        require_once __DIR__.'/../install/schema.php';
        tr_updateOrCreateTable('raw_hits', TrackerSchema::getTableColumns('raw_hits'), $tracker_raw_db);
        ob_end_clean();
        try {
            $tracker_raw_db->insert('raw_hits', $row);
        } catch (\PDOException $e) {
            error_log('tracker: hit not stored: '.$e->getMessage());
        }
    }
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
    global $tracker_db, $tracker_raw_db;
    if (!isset($tracker_db, $tracker_raw_db)) {
        return;
    }

    $settings = tr_get_settings();
    $watermark = (int) ($settings['agg_watermark_id'] ?? 0);

    // Only the touched dates and the new watermark are needed - let SQLite
    // compute them instead of loading every pending row into PHP.
    $max_id = (int) $tracker_raw_db->query('SELECT MAX(id) FROM raw_hits')->fetchColumn();

    // Ids only ever grow (AUTOINCREMENT, carried over by
    // tr_migrate_raw_hits()) - unless the raw file was lost and started
    // fresh (tr_open_raw_db()). Its ids then begin again below the
    // watermark, which would hide every new hit from aggregation.
    if ($max_id < $watermark) {
        $watermark = 0;
    }

    if ($max_id > $watermark) {
        $dates = $tracker_raw_db->query(
            'SELECT DISTINCT substr(ts, 1, 10) FROM raw_hits WHERE id > :wm AND id <= :max',
            [':wm' => $watermark, ':max' => $max_id]
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($dates as $date) {
            tr_recompute_day((string) $date);
        }

        tr_save_setting('agg_watermark_id', $max_id);
    }

    tr_purge_old_raw_hits((int) ($settings['retention_days'] ?? 90), (int) ($settings['bot_retention_days'] ?? 14));
    tr_save_setting('last_aggregated_at', time());

    tr_backup_main_db();
}

/**
 * Re-applies the current bot rules to raw_hits rows already stored (all of
 * them, or only those matching $extra_sql), updates bot_reason where it
 * changed and recomputes the affected days - so editing the bot list also
 * corrects past stats, as far back as raw_hits still reaches.
 *
 * @return array{checked:int, changed:int, days:int}
 */
function tr_reclassify_raw_hits(string $extra_sql = '', array $extra_params = []): array {
    global $tracker_raw_db;

    $settings = tr_get_settings();
    $rules = tr_bot_rules($settings);
    $checked = 0;
    $changed = 0;
    $last_id = 0;
    $pending = [];   // date => true, changed but not recomputed yet
    $recomputed = 0;

    // The oldest day may already be partly purged by retention_days -
    // recomputing it from what's left would undercount it, so it (and
    // anything older) keeps its existing totals.
    $retention_days = (int) ($settings['retention_days'] ?? 90);
    $oldest_complete = $retention_days > 0 ? date('Y-m-d', strtotime('-'.($retention_days - 1).' days')) : '';

    $recompute = function (?string $before) use (&$pending, &$recomputed, $oldest_complete) {
        foreach (array_keys($pending) as $date) {
            if ($before !== null && $date >= $before) {
                continue;
            }
            unset($pending[$date]);
            if ($date >= $oldest_complete) {
                tr_recompute_day($date);
                $recomputed++;
            }
        }
    };

    $sql = 'SELECT id, ts, user_agent, accept_language, has_sec_fetch, bot_reason FROM raw_hits WHERE id > :last_id'
        .($extra_sql !== '' ? ' AND ('.$extra_sql.')' : '')
        .' ORDER BY id ASC LIMIT 5000';

    // Chunked by id so memory stays flat however large raw_hits has grown,
    // and one short transaction per chunk: a single one around everything
    // would hold the write lock for the whole run - on a large table far
    // longer than the 5s busy_timeout frontend hits wait before giving up.
    while (true) {
        $rows = $tracker_raw_db->query($sql, [':last_id' => $last_id] + $extra_params)->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }

        // ids grow with time, so every day before this chunk's first hit
        // is fully re-evaluated - recompute it now rather than at the end.
        // A run that dies halfway (time limit) then leaves stats matching
        // the bot flags already committed, except for the day in progress.
        $recompute(substr((string) $rows[0]['ts'], 0, 10));

        $updates = []; // new reason ('' = human) => [ids]
        foreach ($rows as $r) {
            $last_id = (int) $r['id'];
            $checked++;
            $sec_fetch = $r['has_sec_fetch'] === null ? null : (int) $r['has_sec_fetch'];
            $new = tr_bot_reason((string) $r['user_agent'], (string) $r['accept_language'], $sec_fetch, $rules);
            $old = ($r['bot_reason'] ?? '') !== '' ? $r['bot_reason'] : null;
            if ($new !== $old) {
                $updates[$new ?? ''][] = $last_id;
                $pending[substr((string) $r['ts'], 0, 10)] = true;
                $changed++;
            }
        }

        if ($updates) {
            $tracker_raw_db->action(function ($db) use ($updates) {
                foreach ($updates as $reason => $ids) {
                    foreach (array_chunk($ids, 500) as $chunk) {
                        $db->update('raw_hits', ['bot_reason' => $reason === '' ? null : $reason], ['id' => $chunk]);
                    }
                }
            });
        }
    }

    $recompute(null);

    return ['checked' => $checked, 'changed' => $changed, 'days' => $recomputed];
}

/**
 * LIKE operand for a plain-text "contains" match (SQLite's LIKE is already
 * case-insensitive for ASCII). Use with ESCAPE '\'.
 */
function tr_like_contains(string $needle): string {
    return '%'.addcslashes($needle, '%_\\').'%';
}

/**
 * Labels for raw_hits.bot_reason values, for the backend UI.
 */
function tr_bot_reason_label(?string $reason, array $lang): string {
    if ($reason === null || $reason === '') {
        return $lang['label_status_human'];
    }
    if (str_starts_with($reason, 'ua:')) {
        return sprintf($lang['label_reason_pattern'], substr($reason, 3));
    }
    return $lang['label_reason_'.$reason] ?? $reason;
}

/**
 * Fully recomputes and replaces daily_totals/daily_pageviews/daily_breakdown
 * for one calendar date from that date's raw_hits rows. See
 * tr_aggregate_pending()'s comment for why this replaces rather than
 * increments.
 */
function tr_recompute_day(string $date): void {
    global $tracker_db, $tracker_raw_db;

    // Half-open range instead of LIKE 'date%' so the ts index can be used.
    // Iterated row by row instead of select()'s fetchAll() - a busy day
    // holds tens of thousands of hits, which as one array blew past a
    // 128M memory_limit and killed the whole overview page.
    $stmt = $tracker_raw_db->query(
        'SELECT visitor_hash, url, user_agent, country_code, referrer, query_string, bot_reason
         FROM raw_hits WHERE ts >= :from AND ts < :to ORDER BY id ASC',
        [':from' => $date, ':to' => date('Y-m-d', strtotime($date.' +1 day'))]
    );
    $stmt->setFetchMode(\PDO::FETCH_ASSOC);

    $settings = tr_get_settings();
    $site_host = (string) ($settings['site_host'] ?? '');

    $pageviews = 0;
    $bots = 0;
    $visitors = [];
    $per_url = [];   // url => ['views'=>n, 'visitors'=>[hash=>true]]
    $breakdown = []; // "dimension\0value" => count
    $seen_visitor_today = [];

    foreach ($stmt as $hit) {
        // Flagged bot hits are only counted, never part of any statistic.
        if (($hit['bot_reason'] ?? '') !== '') {
            $bots++;
            continue;
        }
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
        // diluting/multiplying its own campaign count. The query is ordered by
        // id ASC above, so "first seen in this loop" is chronological.
        if (!isset($seen_visitor_today[$vh])) {
            $seen_visitor_today[$vh] = true;
            $source = tr_classify_source((string) $hit['referrer'], (string) $hit['query_string']);
            tr_bump_breakdown($breakdown, 'source', $source);
        }
    }
    $stmt->closeCursor();
    $has_rows = ($pageviews + $bots) > 0;

    // Replace the day in one transaction: readers never see it half
    // written, and SQLite syncs once instead of after every insert.
    $tracker_db->action(function ($db) use ($date, $has_rows, $pageviews, $bots, $visitors, $per_url, $breakdown) {
        $db->delete('daily_totals', ['date' => $date]);
        $db->delete('daily_pageviews', ['date' => $date]);
        $db->delete('daily_breakdown', ['date' => $date]);

        if (!$has_rows) {
            return;
        }

        $db->insert('daily_totals', [
            'date' => $date,
            'pageviews' => $pageviews,
            'visitors' => count($visitors),
            'bots' => $bots,
        ]);

        foreach ($per_url as $url => $agg) {
            $db->insert('daily_pageviews', [
                'date' => $date,
                'url' => $url,
                'views' => $agg['views'],
                'visitors' => count($agg['visitors']),
            ]);
        }

        foreach ($breakdown as $key => $count) {
            [$dimension, $value] = explode("\0", $key, 2);
            $db->insert('daily_breakdown', [
                'date' => $date,
                'dimension' => $dimension,
                'value' => $value,
                'count' => $count,
            ]);
        }
    });
}

function tr_bump_breakdown(array &$acc, string $dimension, string $value): void {
    $value = $value !== '' ? $value : 'Unbekannt';
    $key = $dimension."\0".$value;
    $acc[$key] = ($acc[$key] ?? 0) + 1;
}

/**
 * retention_days <= 0 means "keep forever" - an explicit admin choice
 * (backend/settings.php), not just an unset value. Flagged bot hits get
 * their own, usually much shorter window (bot_retention_days, never longer
 * than retention_days) - they're only kept for inspection.
 */
function tr_purge_old_raw_hits(int $retention_days, int $bot_retention_days = 14): void {
    global $tracker_raw_db;
    if ($retention_days > 0) {
        $cutoff = date('Y-m-d H:i:s', strtotime('-'.$retention_days.' days'));
        $tracker_raw_db->delete('raw_hits', ['ts[<]' => $cutoff]);
    }
    if ($bot_retention_days > 0 && ($retention_days <= 0 || $bot_retention_days < $retention_days)) {
        $cutoff = date('Y-m-d H:i:s', strtotime('-'.$bot_retention_days.' days'));
        $tracker_raw_db->delete('raw_hits', ['ts[<]' => $cutoff, 'bot_reason[!]' => null]);
    }
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
