<?php
// Pure table schema definitions - edit here to add/modify columns.
//
// Design principle (see plugins/tracker's project memory): raw_hits stays
// deliberately minimal/generic - just what a hit looks like, nothing parsed
// or classified yet. All the "expensive" interpretation (user-agent parsing,
// referrer/campaign classification) happens later, lazily, in
// tr_aggregate_pending() (global/functions.php), never on the visitor's own
// request. The one exception is the country code: GeoIP lookup needs the
// raw IP address as input, and the raw IP is deliberately never written to
// disk at all (privacy - see visitor_hash below) - so it has to be resolved
// inline, at capture time, discarding the IP immediately afterward and
// keeping only the resulting (non-identifying) two-letter country code.

return [
    'raw_hits' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'ts' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'url' => 'VARCHAR(1000) NOT NULL',
        'query_string' => 'VARCHAR(1000) NULL',
        'referrer' => 'VARCHAR(1000) NULL',
        'user_agent' => 'VARCHAR(500) NULL',
        'accept_language' => 'VARCHAR(100) NULL',
        // Resolved at capture time against ip_ranges (only when
        // geoip_enabled is on) - the raw IP itself is never stored, see
        // tr_capture_hit() in global/functions.php.
        'country_code' => 'VARCHAR(2) NULL',
        // Daily-rotating hash(IP + User-Agent + date + per-site salt) - no
        // cookie, no persistent identifier. Lets aggregation count unique
        // visitors per day and attribute a campaign source to only the
        // first hit of a same-day visit, without ever storing the IP.
        'visitor_hash' => 'VARCHAR(64) NOT NULL',
    ],

    // One row per day - the overview chart's data source.
    'daily_totals' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'date' => 'VARCHAR(10) NOT NULL UNIQUE',
        'pageviews' => 'INTEGER DEFAULT 0',
        'visitors' => 'INTEGER DEFAULT 0',
    ],

    // One row per day+URL - the "top pages" list.
    'daily_pageviews' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'date' => 'VARCHAR(10) NOT NULL',
        'url' => 'VARCHAR(1000) NOT NULL',
        'views' => 'INTEGER DEFAULT 0',
        'visitors' => 'INTEGER DEFAULT 0',
    ],

    // Generic dimension/value/count rollup, one table covering referrer
    // domain, traffic source (incl. Google/Meta/Microsoft Ads + UTM
    // campaigns), browser, OS, device type and country - instead of one
    // table per dimension. Keeps the schema stable if another breakdown is
    // ever added later (avoids the repeated ALTER-TABLE churn plugins/paperboy
    // went through with its per-dimension tables).
    'daily_breakdown' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'date' => 'VARCHAR(10) NOT NULL',
        'dimension' => 'VARCHAR(20) NOT NULL', // referrer | source | browser | os | device | country
        'value' => 'VARCHAR(255) NOT NULL',
        'count' => 'INTEGER DEFAULT 0',
    ],

    // Bundled/uploaded IPv4 range -> country lookup table (see
    // install/geoip-import.php), used only when geoip_enabled is on.
    // range_start/range_end are packed IPv4 addresses (ip2long()).
    'ip_ranges' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'range_start' => 'INTEGER NOT NULL',
        'range_end' => 'INTEGER NOT NULL',
        'country_code' => 'VARCHAR(2) NOT NULL',
    ],

    'settings' => [
        'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        'key' => 'TEXT NOT NULL UNIQUE',
        'value' => 'TEXT',
    ],
];
