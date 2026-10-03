<?php
/**
 * GeoIP CSV import - parses a plain "start_ip,end_ip,country_code" CSV
 * (no header) into ip_ranges, replacing whatever was there before.
 *
 * Confirmed format via https://github.com/sapics/ip-location-db (checked
 * 2026-09-04) - that project aggregates several free, no-account IPv4
 * country datasets, all published in this exact column shape:
 *
 *   1.0.0.0,1.0.0.255,AU
 *   1.0.1.0,1.0.3.255,CN
 *   ...
 *
 * The bundled starter file (data/geoip/dbip-country-ipv4.csv) is DB-IP's
 * "Lite" country database, CC BY 4.0 - see readme.md / docs/<lang>/geoip.md
 * for the required attribution. A CIDR-notation "cidr,country_code" input
 * (2 columns instead of 3) is also accepted, for other free sources that
 * publish ranges that way instead - see tr_cidr_to_range().
 *
 * Kept as its own file (rather than folded into global/functions.php)
 * since it's a distinct, occasional bulk-write concern with real size to
 * it, not part of the lean day-to-day settings/capture/aggregation code.
 */

/**
 * @return array{success:bool, imported:int, skipped:int, message:string}
 */
function tr_import_geoip_csv(string $file_path): array {
    global $tracker_db;

    if (!is_file($file_path) || !is_readable($file_path)) {
        return ['success' => false, 'imported' => 0, 'skipped' => 0, 'message' => 'File not readable.'];
    }

    $handle = fopen($file_path, 'r');
    if ($handle === false) {
        return ['success' => false, 'imported' => 0, 'skipped' => 0, 'message' => 'Could not open file.'];
    }

    $imported = 0;
    $skipped = 0;
    $batch_size = 2000;

    try {
        $tracker_db->action(function ($db) use ($handle, $batch_size, &$imported, &$skipped) {
            // Replace wholesale - a stale range table would otherwise keep
            // matching alongside newly imported ones with no way to tell
            // which import a given row came from.
            $db->query('DELETE FROM ip_ranges');

            $batch = [];

            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) < 2) {
                    $skipped++;
                    continue;
                }

                if (count($line) >= 3) {
                    // start_ip,end_ip,country_code (the confirmed format,
                    // see this file's own header comment)
                    $start = tr_ipv4_to_int(trim((string) $line[0]));
                    $end = tr_ipv4_to_int(trim((string) $line[1]));
                    $country = strtoupper(trim((string) $line[2]));
                } else {
                    // cidr,country_code
                    $range = tr_cidr_to_range(trim((string) $line[0]));
                    $start = $range[0] ?? null;
                    $end = $range[1] ?? null;
                    $country = strtoupper(trim((string) $line[1]));
                }

                if ($start === null || $end === null || !preg_match('/^[A-Z]{2}$/', $country)) {
                    $skipped++;
                    continue;
                }

                $batch[] = [
                    'range_start' => $start,
                    'range_end' => $end,
                    'country_code' => $country,
                ];
                $imported++;

                if (count($batch) >= $batch_size) {
                    $db->insert('ip_ranges', $batch);
                    $batch = [];
                }
            }

            if ($batch) {
                $db->insert('ip_ranges', $batch);
            }
        });
    } catch (\Throwable $e) {
        fclose($handle);
        return ['success' => false, 'imported' => 0, 'skipped' => 0, 'message' => $e->getMessage()];
    }

    fclose($handle);

    tr_save_setting('geoip_imported_at', time());
    tr_save_setting('geoip_range_count', (string) $imported);

    return ['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'message' => ''];
}

/**
 * CIDR block ("1.2.3.0/24") -> [range_start, range_end] as the same
 * unsigned-32-bit-int encoding tr_ipv4_to_int()/tr_geoip_lookup() use, or
 * null if the input isn't a valid IPv4 CIDR block.
 */
function tr_cidr_to_range(string $cidr): ?array {
    if (!str_contains($cidr, '/')) {
        return null;
    }

    [$base, $prefixStr] = explode('/', $cidr, 2);
    $prefix = (int) $prefixStr;
    if ($prefix < 0 || $prefix > 32 || (string) $prefix !== trim($prefixStr)) {
        return null;
    }

    $base_int = tr_ipv4_to_int($base);
    if ($base_int === null) {
        return null;
    }

    $host_bits = 32 - $prefix;
    $mask = $host_bits === 32 ? 0 : ((~0 << $host_bits) & 0xFFFFFFFF);
    $start = $base_int & $mask;
    $end = $start | (~$mask & 0xFFFFFFFF);

    return [$start, $end];
}

/**
 * CSV files sitting in data/geoip/ - the bundled starter file plus
 * anything an admin has uploaded before (see backend/writer.php's
 * import_geoip handler) - offered as "use this one" options in
 * backend/reader.php's settings_form, alongside a fresh-upload field.
 *
 * @return array<int, array{name:string, size:int}>
 */
function tr_list_geoip_csv_files(): array {
    global $mod_root;

    $files = glob(($mod_root ?? SE_ROOT.'plugins/tracker/').'data/geoip/*.csv') ?: [];
    $result = [];
    foreach ($files as $file) {
        $result[] = ['name' => basename($file), 'size' => (int) filesize($file)];
    }
    return $result;
}
