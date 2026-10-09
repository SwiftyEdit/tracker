<?php
/**
 * @var string $tracker_db_file from bootstrap.php
 * @var string $mod_root
 */
require_once __DIR__.'/schema.php';


/* INSTALL */

if(!is_file("$tracker_db_file")) {

    if(!is_dir(dirname($tracker_db_file))) {
        mkdir(dirname($tracker_db_file), 0755, true);
    }

    echo '<p class="alert alert-info">We try to generate SQLite File: '.$tracker_db_file.'</p>';

        // raw_hits (tracker-raw.sqlite3) is created right after this by
        // tr_open_raw_db() in global/bootstrap.php.
        $tracker_db = tr_connect($tracker_db_file);

        $tables = TrackerSchema::getTables();

        foreach ($tables as $table_name => $columns) {
            $col_definitions = [];
            foreach ($columns as $col_name => $col_type) {
                $col_definitions[] = "$col_name $col_type";
            }

            $sql = "CREATE TABLE IF NOT EXISTS $table_name (" .
                implode(', ', $col_definitions) . ")";

            $tracker_db->query($sql)->execute();
        }

        // Lookup indexes - ip_ranges is queried on every GeoIP lookup. The
        // raw_hits index lives in tr_raw_table_sql().
        $tracker_db->query("CREATE INDEX IF NOT EXISTS idx_daily_pageviews_date ON daily_pageviews (date)")->execute();
        $tracker_db->query("CREATE INDEX IF NOT EXISTS idx_daily_breakdown_date ON daily_breakdown (date, dimension)")->execute();
        $tracker_db->query("CREATE INDEX IF NOT EXISTS idx_ip_ranges_start ON ip_ranges (range_start)")->execute();

        $tracker_db->insert('settings', [
            'key' => 'version',
            'value' => $addon_info['addon']['version']
        ]);

        echo '<p class="alert alert-info">Generated SQLite File: '.$tracker_db_file.'</p>';

        $defaultSettings = tr_get_default_settings();

    foreach ($defaultSettings as $key => $value) {
        if (!$tracker_db->get('settings', 'value', ['key' => $key])) {
            $tracker_db->insert('settings', [
                'key' => $key,
                'value' => (string)$value,
            ]);
        }
    }

}

// tr_get_default_settings() / tr_default_bot_patterns() live in
// global/functions.php (loaded by bootstrap.php before this file is
// included either here or from install/updater.php) so both the initial
// install and every later update can seed newly-introduced default settings
// from the one place.
