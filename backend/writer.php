<?php

require __DIR__.'/../global/bootstrap.php';

if (isset($_POST['save_settings'])) {

    $retention_days = max(0, (int) ($_POST['retention_days'] ?? 90));
    $site_host = preg_replace('/^www\./i', '', strtolower(trim((string) ($_POST['site_host'] ?? ''))));

    // Bot settings live in their own card/handlers since 1.1.0, see below.
    tr_save_setting('retention_days', $retention_days);
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

/* ---------------------------------------------------------------
 * Bot detection (1.1.0). Every handler answers with the whole re-rendered
 * #trBotCard (backend/bot-ui.php) plus a message on top - except
 * add_bot_pattern from the Rohdaten tab (context=rawdata), which only needs
 * a short message inside that table row.
 *
 * Rule changes are applied to the raw_hits already stored right away
 * (tr_reclassify_raw_hits()), so the stats reflect them immediately for
 * every day raw data still exists for.
 * -------------------------------------------------------------- */

$tr_bot_handlers = ['save_bot_settings', 'add_bot_pattern', 'remove_bot_pattern', 'reclassify_bots'];
if (array_intersect($tr_bot_handlers, array_keys($_POST))) {

    require_once __DIR__.'/bot-ui.php';

    // A full re-evaluation reads every raw_hits row - a few seconds per
    // 100k rows, but a busy site with 90 days of raw data can take longer.
    @set_time_limit(300);

    $alert = fn(string $type, string $text) => '<div class="alert alert-'.$type.'">'.$text.'</div>';
    $result_text = fn(array $r) => sprintf($addon_lang['msg_reclassified'], number_format($r['changed'], 0, ',', '.'), number_format($r['days'], 0, ',', '.'));
    $settings = tr_get_settings();

    if (isset($_POST['save_bot_settings'])) {
        $enabled = isset($_POST['bot_filter_enabled']) ? 1 : 0;
        $header_filter = isset($_POST['bot_header_filter']) ? 1 : 0;
        $outdated_filter = isset($_POST['bot_outdated_filter']) ? 1 : 0;
        $rules_changed = $enabled !== (int) ($settings['bot_filter_enabled'] ?? 0)
            || $header_filter !== (int) ($settings['bot_header_filter'] ?? 0)
            || $outdated_filter !== (int) ($settings['bot_outdated_filter'] ?? 0);

        tr_save_setting('bot_filter_enabled', $enabled);
        tr_save_setting('bot_header_filter', $header_filter);
        tr_save_setting('bot_outdated_filter', $outdated_filter);
        tr_save_setting('bot_retention_days', max(0, (int) ($_POST['bot_retention_days'] ?? 14)));

        $msg = $addon_lang['msg_saved'];
        if ($rules_changed) {
            $msg .= ' '.$result_text(tr_reclassify_raw_hits());
        }
        echo tr_render_bot_card($alert('success', $msg));
        exit;
    }

    if (isset($_POST['add_bot_pattern'])) {
        $in_rawdata = ($_POST['context'] ?? '') === 'rawdata';
        $pattern = strtolower(trim((string) ($_POST['pattern'] ?? '')));
        $custom = tr_custom_bot_patterns($settings);

        $error = '';
        if (mb_strlen($pattern) < 3 || mb_strlen($pattern) > 100) {
            $error = $addon_lang['msg_pattern_length'];
        } elseif (tr_pattern_hits_browsers($pattern)) {
            // e.g. "chrome" or "mozilla" - would drop real visitors.
            $error = $addon_lang['msg_pattern_hits_browsers'];
        } elseif (in_array($pattern, $custom, true)) {
            $error = $addon_lang['msg_pattern_exists'];
        } else {
            // A pattern containing an existing one adds nothing - that one
            // already matches every UA this one would (e.g. "fooscraper"
            // via the built-in "scrape").
            foreach (array_merge(tr_default_bot_patterns(), $custom) as $existing) {
                if (str_contains($pattern, $existing)) {
                    $error = sprintf($addon_lang['msg_pattern_covered'], htmlspecialchars($existing));
                    break;
                }
            }
        }

        if ($error !== '') {
            echo $in_rawdata ? $alert('danger', $error) : tr_render_bot_card($alert('danger', $error));
            exit;
        }

        $custom[] = $pattern;
        tr_save_setting('bot_custom_patterns', json_encode(array_values($custom)));

        // Only rows still counted as visitors whose UA contains the new
        // pattern can change - no need to scan everything.
        $r = tr_reclassify_raw_hits("bot_reason IS NULL AND user_agent LIKE :p ESCAPE '\\'", [':p' => tr_like_contains($pattern)]);
        $msg = sprintf($addon_lang['msg_pattern_added'], htmlspecialchars($pattern)).' '.$result_text($r);

        echo $in_rawdata ? $alert('success', $msg) : tr_render_bot_card($alert('success', $msg));
        exit;
    }

    if (isset($_POST['remove_bot_pattern'])) {
        $pattern = strtolower(trim((string) $_POST['remove_bot_pattern']));
        $custom = array_values(array_filter(tr_custom_bot_patterns($settings), fn($p) => $p !== $pattern));
        tr_save_setting('bot_custom_patterns', json_encode($custom));

        // Rows caught by exactly this pattern get re-evaluated - they may
        // still be bots by another rule, or count as visitors again.
        $r = tr_reclassify_raw_hits('bot_reason = :reason', [':reason' => 'ua:'.$pattern]);
        $msg = sprintf($addon_lang['msg_pattern_removed'], htmlspecialchars($pattern)).' '.$result_text($r);
        echo tr_render_bot_card($alert('success', $msg));
        exit;
    }

    if (isset($_POST['reclassify_bots'])) {
        $r = tr_reclassify_raw_hits();
        echo tr_render_bot_card($alert('success', sprintf($addon_lang['msg_reclassify_checked'], number_format($r['checked'], 0, ',', '.')).' '.$result_text($r)));
        exit;
    }
}

exit;
