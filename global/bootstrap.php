<?php
global $addon_lang, $hidden_csrf_token;

$mod_root = SE_ROOT.'plugins/tracker/';
$tracker_db_file = $mod_root.'data/tracker.sqlite3';
// raw_hits lives in its own file since 1.1.2 - see the "Two database
// files" note in global/functions.php.
$tracker_raw_db_file = $mod_root.'data/tracker-raw.sqlite3';

// The frontend capture hook (hooks-frontend/register.php) runs inside
// app.php's own request lifecycle, not through a plugin entry point that
// gets its own fresh scope - declaring this global forces $tracker_db into
// the real global scope regardless of which include chain reaches this
// file, same reasoning as plugins/paperboy/global/bootstrap.php.
global $tracker_db, $tracker_raw_db;

// $addon_info is only pre-set when this bootstrap is reached via the admin
// nav-tab router (acp/core/addons/edit-plugin.php). The admin-xhr reader/
// writer router and the frontend hook do not set it, so it is loaded here
// directly to make bootstrap.php safe in all contexts.
if(!isset($addon_info)) {
    $addon_info = json_decode(file_get_contents($mod_root.'info.json'), true);
}

require_once __DIR__.'/functions.php';
require_once __DIR__.'/geoip-import.php';

if(is_file($tracker_db_file)) {
    $tracker_db = tr_connect($tracker_db_file);
} else {

    // Only ever auto-create the database from a real admin-panel request
    // (backend nav tab, or the activation call in acp/core/addons/data-writer.php,
    // which loads this bootstrap indirectly the first time the Übersicht tab
    // is opened). Never from the frontend capture hook - a hit arriving
    // before the plugin has been opened once in the ACP should just be
    // silently skipped (tr_capture_hit() below already checks isset($tracker_db)),
    // not trigger a first-time install as a side effect of a random visitor.
    if(SE_SECTION === 'backend') {
        include __DIR__ . '/../install/installer.php';
    }

}

if(isset($tracker_db)) {
    $tr_settings = tr_get_settings();

    // Before the updater, which already works on the raw file. On failure
    // raw_hits just stays in the main file for now (tr_open_raw_db() keeps
    // using it there) and the next backend request tries again.
    if(SE_SECTION === 'backend') {
        try {
            tr_migrate_raw_hits($tracker_db, $tracker_db_file, $tracker_raw_db_file);
        } catch (\Throwable $e) {
            error_log('tracker: moving raw_hits to '.$tracker_raw_db_file.' failed: '.$e->getMessage());
        }
    }

    $tracker_raw_db = tr_open_raw_db($tracker_db, $tracker_raw_db_file);

    if(SE_SECTION === 'backend') {
        if (version_compare($tr_settings['version'] ?? '0', $addon_info['addon']['version'], '<')) {
            include __DIR__ . '/../install/updater.php';
        }
    }
}

// se_return_addon_translations() lives in acp/core/functions_addons.php,
// which is only autoloaded on admin pages - it's undefined from the
// frontend capture hook, where $addon_lang isn't needed anyway (the hook
// never renders anything user-facing).
if(!is_array($addon_lang) && function_exists('se_return_addon_translations')) {
    $addon_lang = se_return_addon_translations('tracker');
}
