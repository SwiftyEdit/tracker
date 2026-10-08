<?php

include __DIR__.'/schema.php';

    // update
    echo '<p class="alert alert-info">We try to update version: '.$addon_info['addon']['version'].'</p>';

    $tables = TrackerSchema::getTables();

    // 1. Update/Create all tables with current schema
    foreach ($tables as $table_name => $columns) {
        tr_updateOrCreateTable($table_name, $columns);
    }
    $tracker_db->query("CREATE INDEX IF NOT EXISTS idx_raw_hits_ts ON raw_hits (ts)")->execute();

    // 2. 1.1.0: the old pipe-separated regex (bot_filter_patterns) became a
    // plain-text list (bot_custom_patterns) on top of the built-in defaults
    // in code. Keep only what the admin added themselves.
    if ($tracker_db->has('settings', ['key' => 'bot_filter_patterns']) && !$tracker_db->has('settings', ['key' => 'bot_custom_patterns'])) {
        $old_patterns = (string) $tracker_db->get('settings', 'value', ['key' => 'bot_filter_patterns']);
        $defaults = tr_default_bot_patterns();
        $custom = [];
        foreach (explode('|', $old_patterns) as $p) {
            $p = strtolower(trim(stripslashes($p)));
            if ($p === '' || in_array($p, $custom, true)) {
                continue;
            }
            // Already covered if a built-in pattern is part of it (e.g.
            // "ahrefsbot" via "bot") - matches everything this one would.
            foreach ($defaults as $d) {
                if (str_contains($p, $d)) {
                    continue 2;
                }
            }
            $custom[] = $p;
        }
        $tracker_db->insert('settings', ['key' => 'bot_custom_patterns', 'value' => json_encode($custom)]);
        $tracker_db->delete('settings', ['key' => 'bot_filter_patterns']);
    }

    // 3. Backfill any settings introduced after initial install (e.g. an
    // existing 1.0.0 install upgrading past a version that adds a new
    // default) without touching settings the admin already changed.
    // has(), not get(): a stored "0" is falsy and would trip the UNIQUE key.
    $defaultSettings = tr_get_default_settings();
    foreach ($defaultSettings as $key => $value) {
        if (!$tracker_db->has('settings', ['key' => $key])) {
            $tracker_db->insert('settings', [
                'key' => $key,
                'value' => (string) $value,
            ]);
        }
    }

    // 4. Update version
    $tracker_db->update('settings', [
        'value' => $addon_info['addon']['version']
    ], [
        'key' => 'version'
    ]);

    echo '<p class="alert alert-info">Updated to version: '.$addon_info['addon']['version'].'</p>';
