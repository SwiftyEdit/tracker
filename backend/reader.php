<?php

require __DIR__.'/../global/bootstrap.php';

/* ---------------------------------------------------------------
 * Overview dashboard - reads only the aggregated tables
 * (daily_totals/daily_pageviews/daily_breakdown), never raw_hits - see the
 * plugin's project memory ("the stats UI itself only ever reads the
 * aggregated tables"). Time range is selectable (round 7-8, 2026-09-04):
 * a preset via ?days=, "Alle" via ?range=all, or a custom ?from=&to=. The
 * whole #trOverviewContent container (backend/start.php) gets reloaded on
 * every range change, so every section below (totals, chart, table,
 * breakdowns) and the controls' own active state stay in sync in one
 * request.
 * -------------------------------------------------------------- */
if (isset($_GET['show']) && $_GET['show'] === 'overview') {

    $today = date('Y-m-d');
    $allowed_days = [7, 14, 30, 90];

    $mode = 'days';
    $days = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, $allowed_days, true)) {
        $days = 30;
    }
    $from = date('Y-m-d', strtotime('-'.($days - 1).' days'));
    $to = $today;

    // "Y-m-d" round-trip check rejects anything that isn't a real,
    // canonically-formatted calendar date (e.g. "2026-13-40" or garbage)
    // without needing a separate regex.
    $is_valid_date = fn($s) => is_string($s) && \DateTime::createFromFormat('Y-m-d', $s) !== false && \DateTime::createFromFormat('Y-m-d', $s)->format('Y-m-d') === $s;

    if (($_GET['range'] ?? '') === 'all') {
        $mode = 'all';
        $earliest = $tracker_db->query('SELECT MIN(date) FROM daily_totals')->fetchColumn();
        $from = $earliest ?: $today;
        $to = $today;
    } elseif (!empty($_GET['from']) && !empty($_GET['to']) && $is_valid_date($_GET['from']) && $is_valid_date($_GET['to']) && $_GET['from'] <= $_GET['to']) {
        $mode = 'custom';
        $from = $_GET['from'];
        $to = min($_GET['to'], $today); // no future dates
    }

    echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">';
    echo '<div class="btn-group" role="group">';
    foreach ($allowed_days as $d) {
        $active = ($mode === 'days' && $d === $days) ? ' active' : '';
        echo '<button type="button" class="btn btn-sm btn-outline-primary'.$active.'"'
            .' hx-get="/admin-xhr/addons/plugin/tracker/read/?show=overview&days='.$d.'"'
            .' hx-target="#trOverviewContent" hx-swap="innerHTML">'
            .sprintf($addon_lang['btn_range_days'], $d).'</button>';
    }
    $all_active = $mode === 'all' ? ' active' : '';
    echo '<button type="button" class="btn btn-sm btn-outline-primary'.$all_active.'"'
        .' hx-get="/admin-xhr/addons/plugin/tracker/read/?show=overview&range=all"'
        .' hx-target="#trOverviewContent" hx-swap="innerHTML">'.$addon_lang['btn_range_all'].'</button>';
    echo '</div>';

    $custom_active = $mode === 'custom' ? ' text-primary fw-semibold' : ' text-muted';
    echo '<form class="d-flex align-items-center gap-1 small'.$custom_active.'"'
        .' hx-get="/admin-xhr/addons/plugin/tracker/read/" hx-target="#trOverviewContent" hx-swap="innerHTML">';
    echo '<input type="hidden" name="show" value="overview">';
    echo '<input type="date" class="form-control form-control-sm" name="from" value="'.htmlspecialchars($mode === 'custom' ? $from : '').'" max="'.$today.'" style="width:150px">';
    echo '<span>'.$addon_lang['label_range_to'].'</span>';
    echo '<input type="date" class="form-control form-control-sm" name="to" value="'.htmlspecialchars($mode === 'custom' ? $to : '').'" max="'.$today.'" style="width:150px">';
    echo '<button type="submit" class="btn btn-sm btn-outline-secondary">'.$addon_lang['btn_apply'].'</button>';
    echo '</form>';
    echo '</div>';

    $period_label = date('d.m.Y', strtotime($from)).' – '.date('d.m.Y', strtotime($to));
    echo '<p class="text-muted small mb-3">'.sprintf($addon_lang['label_period'], $period_label).'</p>';

    $totals = $tracker_db->select('daily_totals', ['date', 'pageviews', 'visitors'], [
        'date[>=]' => $from,
        'date[<=]' => $to,
        'ORDER' => ['date' => 'DESC'],
    ]);

    $sum_pageviews = array_sum(array_column($totals, 'pageviews'));
    $sum_visitors = array_sum(array_column($totals, 'visitors'));

    echo '<div class="row mb-3">';
    echo '<div class="col-md-4"><div class="card text-center p-3"><div class="fs-2">'.number_format($sum_pageviews, 0, ',', '.').'</div><div class="text-muted">'.$addon_lang['label_pageviews'].'</div></div></div>';
    echo '<div class="col-md-4"><div class="card text-center p-3"><div class="fs-2">'.number_format($sum_visitors, 0, ',', '.').'</div><div class="text-muted">'.$addon_lang['label_visitors'].'</div></div></div>';
    echo '<div class="col-md-4"><div class="card text-center p-3"><div class="fs-2">'.count($totals).'</div><div class="text-muted">'.$addon_lang['label_active_days'].'</div></div></div>';
    echo '</div>';

    if (!$totals) {
        echo '<p class="text-muted">'.$addon_lang['msg_no_data'].'</p>';
        exit;
    }

    // Chart.js trend chart - $totals is DESC (newest first, convenient for
    // the table below), reversed here into chronological order for the
    // chart's x-axis. Chart.js itself is vendored (assets/vendor/, MIT
    // licensed, see endpoint.php) and lazy-loaded on demand via the
    // bootstrap-free /dispatch.php?p=tracker asset endpoint - not inlined
    // into every htmx response, and cached by the browser after the first
    // load (immutable Cache-Control, see endpoint.php).
    $chart_dates = array_reverse(array_column($totals, 'date'));
    $chart_pageviews = array_map('intval', array_reverse(array_column($totals, 'pageviews')));
    $chart_visitors = array_map('intval', array_reverse(array_column($totals, 'visitors')));
    // "04.09." style labels read better on a chart axis than raw ISO dates,
    // especially once the 90-day range makes labels tight.
    $chart_labels = array_map(fn($d) => date('d.m.', strtotime($d)), $chart_dates);

    echo '<div class="card p-3 mb-3">';
    echo '<h5>'.$addon_lang['title_chart'].'</h5>';
    echo '<div style="position:relative;height:280px;"><canvas id="trDailyChart"></canvas></div>';
    echo '</div>';

    echo '<script>(function(){';
    echo 'var labels='.json_encode($chart_labels).';';
    echo 'var pv='.json_encode($chart_pageviews).';';
    echo 'var vis='.json_encode($chart_visitors).';';
    echo 'function trRenderChart(){';
    echo 'var el=document.getElementById("trDailyChart");if(!el||typeof Chart==="undefined")return;';
    echo 'new Chart(el,{type:"line",data:{labels:labels,datasets:['
        .'{label:'.json_encode($addon_lang['label_pageviews']).',data:pv,borderColor:"#0d6efd",backgroundColor:"rgba(13,110,253,0.12)",tension:0.3,fill:true,pointRadius:2},'
        .'{label:'.json_encode($addon_lang['label_visitors']).',data:vis,borderColor:"#198754",backgroundColor:"rgba(25,135,84,0.12)",tension:0.3,fill:true,pointRadius:2}'
        .']},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:"index",intersect:false},scales:{y:{beginAtZero:true}}}});';
    echo '}';
    // Lazy-loaded on first use, then cached - subsequent range-button
    // clicks (or reopening the tab later in the same admin session) find
    // window.Chart already defined and skip straight to rendering, no
    // repeat network request.
    echo 'if(typeof Chart==="undefined"){var s=document.createElement("script");s.src="/dispatch.php?p=tracker&asset=chart.umd.min.js";s.onload=trRenderChart;document.head.appendChild(s);}else{trRenderChart();}';
    echo '})();</script>';

    echo '<div class="card p-3 mb-3">';
    echo '<h5>'.$addon_lang['title_daily_table'].'</h5>';
    // Scroll container instead of an ever-growing page (2026-09-04
    // feedback: "wird ja ewig lang" at the 90-day range) - sticky header so
    // the column labels stay visible while scrolling through it.
    echo '<div class="table-responsive" style="max-height:340px;overflow-y:auto;">';
    echo '<table class="table table-sm table-striped mb-0"><thead style="position:sticky;top:0;z-index:1;background-color:var(--bs-card-bg);"><tr>';
    echo '<th>'.$addon_lang['th_date'].'</th><th>'.$addon_lang['label_pageviews'].'</th><th>'.$addon_lang['label_visitors'].'</th>';
    echo '</tr></thead><tbody>';
    foreach ($totals as $row) {
        echo '<tr><td>'.htmlspecialchars($row['date']).'</td><td>'.(int) $row['pageviews'].'</td><td>'.(int) $row['visitors'].'</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';

    $top_pages = $tracker_db->query(
        'SELECT url, SUM(views) AS views, SUM(visitors) AS visitors FROM daily_pageviews WHERE date >= :from AND date <= :to GROUP BY url ORDER BY views DESC LIMIT 30',
        [':from' => $from, ':to' => $to]
    )->fetchAll();

    echo '<div class="card p-3 mb-3">';
    echo '<h5>'.$addon_lang['title_top_pages'].'</h5>';
    if (!$top_pages) {
        echo '<p class="text-muted mb-0">'.$addon_lang['msg_no_data'].'</p>';
    } else {
        // Same scroll-container treatment as the daily table (2026-09-04
        // feedback: "Alle anderen Karten sollten auch einen
        // scroll-container haben") - raised the LIMIT above from 15 to 30
        // at the same time, since a taller list is now actually browsable
        // without pushing the rest of the page down.
        echo '<div class="table-responsive" style="max-height:300px;overflow-y:auto;">';
        echo '<table class="table table-sm table-striped mb-0"><thead style="position:sticky;top:0;z-index:1;background-color:var(--bs-card-bg);"><tr>';
        echo '<th>'.$addon_lang['th_url'].'</th><th>'.$addon_lang['label_pageviews'].'</th><th>'.$addon_lang['label_visitors'].'</th>';
        echo '</tr></thead><tbody>';
        foreach ($top_pages as $row) {
            echo '<tr><td>'.htmlspecialchars($row['url']).'</td><td>'.(int) $row['views'].'</td><td>'.(int) $row['visitors'].'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    $breakdown_dimensions = [
        'source' => $addon_lang['title_sources'],
        'referrer' => $addon_lang['title_referrers'],
        'browser' => $addon_lang['title_browsers'],
        'os' => $addon_lang['title_os'],
        'device' => $addon_lang['title_devices'],
        'country' => $addon_lang['title_countries'],
    ];

    echo '<div class="row">';
    foreach ($breakdown_dimensions as $dimension => $label) {
        $values = $tracker_db->query(
            'SELECT value, SUM(count) AS total FROM daily_breakdown WHERE date >= :from AND date <= :to AND dimension = :dimension GROUP BY value ORDER BY total DESC LIMIT 20',
            [':from' => $from, ':to' => $to, ':dimension' => $dimension]
        )->fetchAll();

        echo '<div class="col-md-6 mb-3">';
        echo '<div class="card p-3 h-100">';
        echo '<h6>'.$label.'</h6>';
        if (!$values) {
            echo '<p class="text-muted mb-0">'.$addon_lang['msg_no_data'].'</p>';
        } else {
            // Same scroll-container treatment as the other cards
            // (2026-09-04 feedback), LIMIT raised from 10 to 20 to match.
            echo '<div style="max-height:280px;overflow-y:auto;">';
            echo '<table class="table table-sm mb-0"><tbody>';
            foreach ($values as $row) {
                echo '<tr><td>'.htmlspecialchars($row['value']).'</td><td class="text-end">'.(int) $row['total'].'</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';

    exit;
}

/* ---------------------------------------------------------------
 * Settings form
 * -------------------------------------------------------------- */
if (isset($_GET['show']) && $_GET['show'] === 'settings_form') {

    $settings = tr_get_settings();
    $has_geoip_data = (int) $tracker_db->count('ip_ranges') > 0;

    echo '<div id="trSettingsResponse"></div>';
    echo '<form hx-post="/admin-xhr/addons/plugin/tracker/write/" hx-target="#trSettingsResponse" hx-swap="innerHTML">';
    echo '<input type="hidden" name="save_settings" value="1">';
    echo '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES).'">';

    echo '<div class="mb-3">';
    echo '<label class="form-label">'.$addon_lang['label_retention_days'].tr_hint_icon($addon_lang['hint_retention_days']).'</label>';
    echo '<input type="number" min="0" class="form-control" name="retention_days" value="'.(int) ($settings['retention_days'] ?? 90).'" style="max-width:160px">';
    echo '</div>';

    echo '<hr>';

    echo '<div class="form-check form-switch mb-2">';
    echo '<input class="form-check-input" type="checkbox" role="switch" id="trBotFilterEnabled" name="bot_filter_enabled" value="1"'.(!empty($settings['bot_filter_enabled']) ? ' checked' : '').'>';
    echo '<label class="form-check-label" for="trBotFilterEnabled">'.$addon_lang['label_bot_filter_enabled'].'</label>';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<label class="form-label">'.$addon_lang['label_bot_filter_patterns'].tr_hint_icon($addon_lang['hint_bot_filter_patterns']).'</label>';
    echo '<textarea class="form-control" name="bot_filter_patterns" rows="3">'.htmlspecialchars((string) ($settings['bot_filter_patterns'] ?? '')).'</textarea>';
    echo '</div>';

    echo '<hr>';

    echo '<div class="form-check form-switch mb-2">';
    echo '<input class="form-check-input" type="checkbox" role="switch" id="trGeoipEnabled" name="geoip_enabled" value="1"'.(!empty($settings['geoip_enabled']) ? ' checked' : '').'>';
    echo '<label class="form-check-label" for="trGeoipEnabled">'.$addon_lang['label_geoip_enabled'].'</label>';
    echo '</div>';
    if (!$has_geoip_data) {
        echo '<p class="small text-muted">'.$addon_lang['hint_geoip_no_data'].'</p>';
    } else {
        $range_count = (int) $tracker_db->count('ip_ranges');
        $imported_at = (int) ($settings['geoip_imported_at'] ?? 0);
        $imported_label = $imported_at > 0 ? date('d.m.Y H:i', $imported_at) : '-';
        echo '<p class="small text-muted">'.sprintf($addon_lang['hint_geoip_loaded'], number_format($range_count, 0, ',', '.'), $imported_label).'</p>';
    }

    echo '<hr>';

    echo '<div class="mb-3">';
    echo '<label class="form-label">'.$addon_lang['label_site_host'].tr_hint_icon($addon_lang['hint_site_host']).'</label>';
    echo '<input type="text" class="form-control" name="site_host" value="'.htmlspecialchars((string) ($settings['site_host'] ?? '')).'" style="max-width:320px">';
    echo '</div>';

    echo '<button type="submit" class="btn btn-primary">'.$addon_lang['btn_save'].'</button>';
    echo '</form>';

    exit;
}

/* ---------------------------------------------------------------
 * GeoIP import card - its own show= handler (and its own card, right
 * column in backend/settings.php) since a CSV import is a separate action
 * and its own multipart request, independent from saving the settings
 * form. Split out of settings_form 2026-10-03 (feedback: empty space to
 * the right of the settings card, import belongs beside it).
 * -------------------------------------------------------------- */
if (isset($_GET['show']) && $_GET['show'] === 'geoip_import') {

    $csv_files = tr_list_geoip_csv_files();

    echo '<h5>'.$addon_lang['title_geoip_import'].'</h5>';
    echo '<div id="trGeoipImportResponse"></div>';

    // "Use an existing file" and "upload a new one" are two clearly separate
    // actions/cards now, not one dropdown+file-input combo inside a single
    // form - a user mistook the dropdown for an editable field and deleted
    // the ready-to-use bundled CSV trying to "clear" it (2026-09-04
    // feedback). Each import button below is its own <form>, so submitting
    // one can never touch the other's input at all.
    if ($csv_files) {
        foreach ($csv_files as $f) {
            echo '<div class="card p-3 mb-2">';
            echo '<div class="d-flex justify-content-between align-items-center">';
            echo '<div>';
            echo '<div><i class="bi bi-check-circle-fill text-success"></i> <strong>'.htmlspecialchars($f['name']).'</strong></div>';
            echo '<div class="small text-muted">'.sprintf($addon_lang['label_file_ready'], number_format($f['size'] / 1048576, 1)).'</div>';
            echo '</div>';
            echo '<form hx-post="/admin-xhr/addons/plugin/tracker/write/" hx-target="#trGeoipImportResponse" hx-swap="innerHTML" class="mb-0">';
            echo '<input type="hidden" name="import_geoip" value="1">';
            echo '<input type="hidden" name="use_existing_file" value="'.htmlspecialchars($f['name']).'">';
            echo '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES).'">';
            echo '<button type="submit" class="btn btn-primary">'.$addon_lang['btn_import'].'</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }
    }

    echo '<div class="card p-3">';
    echo '<form hx-post="/admin-xhr/addons/plugin/tracker/write/" hx-target="#trGeoipImportResponse" hx-swap="innerHTML" hx-encoding="multipart/form-data" class="mb-0">';
    echo '<input type="hidden" name="import_geoip" value="1">';
    echo '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES).'">';
    echo '<div class="mb-3">';
    echo '<label class="form-label">'.$addon_lang['label_upload_csv'].tr_hint_icon($addon_lang['hint_upload_csv']).'</label>';
    echo '<input type="file" class="form-control" name="geoip_csv" accept=".csv" style="max-width:420px">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-default">'.$addon_lang['btn_upload_and_import'].'</button>';
    echo '</form>';
    echo '</div>';

    exit;
}

/* ---------------------------------------------------------------
 * Docs tab - same pattern as plugins/paperboy/backend/reader.php.
 * -------------------------------------------------------------- */
if (isset($_GET['show']) && $_GET['show'] === 'docs_nav') {

    // se_parse_docs_file() (acp/core/functions.php) reads this via
    // `global $Parsedown` and calls ->text() on it directly - without
    // instantiating it here first, that call fatals on a null object and
    // the whole admin-xhr response dies (nothing renders, the "Hilfe" tab
    // just stays blank). Same requirement in the docs_content branch below.
    $Parsedown = new Parsedown();

    $docs_root = SE_ROOT.'plugins/tracker/docs/en';
    if (is_dir(SE_ROOT.'plugins/tracker/docs/'.$languagePack)) {
        $docs_root = SE_ROOT.'plugins/tracker/docs/'.$languagePack;
    }

    $current_file = basename($_GET['file'] ?? 'index.md');
    $docsfiles = glob($docs_root.'/*.md');
    $parsed_files = [];

    foreach ($docsfiles as $doc) {
        $parsed_file = se_parse_docs_file($doc);
        $parsed_files[] = [
            'title' => $parsed_file['header']['title'],
            'priority' => $parsed_file['header']['priority'],
            'btn' => $parsed_file['header']['btn'],
            'file' => $doc,
        ];
    }

    $sorted_parsed_files = se_array_multisort($parsed_files, 'priority', SORT_ASC);

    $list = '<div class="card mb-3">';
    $list .= '<div class="list-group list-group-flush">';
    foreach ($sorted_parsed_files as $v) {
        $active = basename($v['file']) === $current_file ? ' active' : '';
        $hx_get = '/admin-xhr/addons/plugin/tracker/read/?show=docs_content&file='.basename($v['file']);
        $list .= '<button class="list-group-item list-group-item-action'.$active.'" hx-get="'.$hx_get.'" hx-target="#docsContent"
            hx-on:click="this.closest(\'.list-group\').querySelectorAll(\'.active\').forEach(function(el){ el.classList.remove(\'active\'); }); this.classList.add(\'active\')">';
        $list .= $v['btn'];
        $list .= '</button>';
    }
    $list .= '</div>';
    $list .= '</div>';
    echo $list;
    exit;
}

if (isset($_GET['show']) && $_GET['show'] === 'docs_content') {

    $Parsedown = new Parsedown();

    $df = basename($_GET['file'] ?? 'index.md');

    $doc_file = SE_ROOT.'plugins/tracker/docs/'.$languagePack.'/'.$df;
    if (!is_file($doc_file)) {
        $doc_file = SE_ROOT.'plugins/tracker/docs/en/'.$df;
    }

    if (is_file($doc_file)) {
        $parsed_file = se_parse_docs_file($doc_file);
        echo $parsed_file['content'];
    }
    exit;
}

exit;
