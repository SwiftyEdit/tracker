<?php

include __DIR__.'/schema.php';

    // update
    echo '<p class="alert alert-info">We try to update version: '.$addon_info['addon']['version'].'</p>';

    $tables = TrackerSchema::getTables();

    // 1. Update/Create all tables with current schema
    foreach ($tables as $table_name => $columns) {
        tr_updateOrCreateTable($table_name, $columns);
    }

    // 2. Backfill any settings introduced after initial install (e.g. an
    // existing 1.0.0 install upgrading past a version that adds a new
    // default) without touching settings the admin already changed.
    $defaultSettings = tr_get_default_settings();
    foreach ($defaultSettings as $key => $value) {
        if (!$tracker_db->get('settings', 'value', ['key' => $key])) {
            $tracker_db->insert('settings', [
                'key' => $key,
                'value' => (string) $value,
            ]);
        }
    }

    // 3. Update version
    $tracker_db->update('settings', [
        'value' => $addon_info['addon']['version']
    ], [
        'key' => 'version'
    ]);

    echo '<p class="alert alert-info">Updated to version: '.$addon_info['addon']['version'].'</p>';
