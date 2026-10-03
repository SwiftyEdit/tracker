<?php

require __DIR__.'/../global/bootstrap.php';

if (isset($_POST['save_settings'])) {

    $retention_days = max(0, (int) ($_POST['retention_days'] ?? 90));
    $bot_filter_patterns = (string) ($_POST['bot_filter_patterns'] ?? '');
    $site_host = preg_replace('/^www\./i', '', strtolower(trim((string) ($_POST['site_host'] ?? ''))));

    // A broken regex would otherwise make tr_is_bot() silently stop
    // matching for every visitor (see its own @preg_match) - reject it here
    // instead, before it's saved, so the admin gets immediate feedback.
    if ($bot_filter_patterns !== '' && @preg_match('/'.$bot_filter_patterns.'/i', '') === false) {
        echo '<div class="alert alert-danger">'.$addon_lang['msg_invalid_pattern'].'</div>';
        exit;
    }

    tr_save_setting('retention_days', $retention_days);
    tr_save_setting('bot_filter_enabled', isset($_POST['bot_filter_enabled']) ? 1 : 0);
    tr_save_setting('bot_filter_patterns', $bot_filter_patterns);
    tr_save_setting('geoip_enabled', isset($_POST['geoip_enabled']) ? 1 : 0);
    tr_save_setting('site_host', $site_host);

    // Deliberately NOT firing HX-Trigger: update_tracker_settings here - the
    // outer card-body (backend/settings.php) listens for that and reloads
    // the WHOLE settings_form via a fresh GET the instant it fires, which
    // wiped this very success message before it was readable (2026-09-04
    // feedback: "verschwindet im Bruchteil einer Sekunde wieder"). Nothing
    // else on the page depends on a plain settings save refreshing anyway.
    echo '<div class="alert alert-success">'.$addon_lang['msg_saved'].'</div>';
    exit;
}

if (isset($_POST['import_geoip'])) {
    // A full country-level CSV is a few hundred thousand rows (see
    // global/geoip-import.php) - one bulk-insert transaction is normally a
    // few seconds, but a slower disk/host shouldn't hit the default PHP
    // execution-time limit mid-import and leave ip_ranges half-replaced.
    @set_time_limit(120);

    $target_file = null;

    if (!empty($_FILES['geoip_csv']['tmp_name']) && is_uploaded_file($_FILES['geoip_csv']['tmp_name'])) {
        $ext = strtolower((string) pathinfo((string) $_FILES['geoip_csv']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            echo '<div class="alert alert-danger">'.$addon_lang['msg_geoip_invalid_file'].'</div>';
            exit;
        }

        $geoip_dir = $mod_root.'data/geoip/';
        if (!is_dir($geoip_dir)) {
            mkdir($geoip_dir, 0755, true);
        }

        // Timestamped, not the original filename - avoids collisions and
        // any path-traversal concern from a hostile filename.
        $dest = $geoip_dir.date('Ymd-His').'-upload.csv';
        if (!move_uploaded_file($_FILES['geoip_csv']['tmp_name'], $dest)) {
            echo '<div class="alert alert-danger">'.$addon_lang['msg_geoip_upload_failed'].'</div>';
            exit;
        }
        $target_file = $dest;
    } elseif (!empty($_POST['use_existing_file'])) {
        // basename() confines this to a plain filename - can't escape
        // data/geoip/ via a path-traversal payload in the posted value.
        $candidate = basename((string) $_POST['use_existing_file']);
        $path = $mod_root.'data/geoip/'.$candidate;
        if (is_file($path)) {
            $target_file = $path;
        }
    }

    if (!$target_file) {
        echo '<div class="alert alert-danger">'.$addon_lang['msg_geoip_no_file'].'</div>';
        exit;
    }

    $result = tr_import_geoip_csv($target_file);

    if ($result['success']) {
        echo '<div class="alert alert-success">'.sprintf($addon_lang['msg_geoip_imported'], number_format($result['imported'], 0, ',', '.'), number_format($result['skipped'], 0, ',', '.')).'</div>';
    } else {
        echo '<div class="alert alert-danger">'.$addon_lang['msg_geoip_import_failed'].' '.htmlspecialchars($result['message']).'</div>';
    }

    // Same reasoning as save_settings above - no HX-Trigger here either, so
    // this message doesn't get wiped by a full-form reload. The "X Bereiche
    // geladen, zuletzt importiert am ..." hint further up the settings page
    // goes stale until the admin reopens the Einstellungen tab, but the
    // import's own success message here already states the fresh count -
    // an acceptable tradeoff for not reintroducing the flash-and-vanish bug.
    exit;
}

exit;
