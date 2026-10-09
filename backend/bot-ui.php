<?php
/**
 * "Bot-Erkennung" card on the Einstellungen tab. Rendered by both
 * reader.php (initial load) and writer.php (after every change, with a
 * result message on top) - every form in it targets #trBotCard and gets the
 * whole card back, so the pattern list, hit counts and switches can never
 * drift out of sync with what was just saved, and the message stays visible
 * (no separate reload that would wipe it, cf. writer.php's save_settings).
 */

function tr_render_bot_card(string $message = ''): string {
    global $tracker_raw_db, $addon_lang;

    $settings = tr_get_settings();
    $custom = tr_custom_bot_patterns($settings);
    $defaults = tr_default_bot_patterns();
    $csrf = '<input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['token'] ?? '', ENT_QUOTES).'">';
    $post = 'hx-post="/admin-xhr/addons/plugin/tracker/write/" hx-target="#trBotCard" hx-swap="innerHTML"';

    // Raw hits per bot_reason, to show what each rule actually catches.
    $reason_counts = [];
    $rows = !isset($tracker_raw_db) ? [] : $tracker_raw_db->query('SELECT bot_reason, COUNT(*) AS n FROM raw_hits WHERE bot_reason IS NOT NULL GROUP BY bot_reason')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $reason_counts[$r['bot_reason']] = (int) $r['n'];
    }
    $count_badge = function (string $reason) use ($reason_counts, $addon_lang): string {
        $n = $reason_counts[$reason] ?? 0;
        return '<span class="badge text-bg-secondary ms-1" title="'.htmlspecialchars($addon_lang['hint_raw_hits_caught'], ENT_QUOTES).'">'.number_format($n, 0, ',', '.').'</span>';
    };

    $h = '<h5>'.$addon_lang['title_bot_filter'].'</h5>';
    $h .= $message;
    $h .= '<div class="row">';

    /* Left: switches, retention, re-evaluate */
    $h .= '<div class="col-lg-5 mb-3">';
    $h .= '<form '.$post.'>';
    $h .= '<input type="hidden" name="save_bot_settings" value="1">'.$csrf;

    $h .= '<div class="form-check form-switch mb-2">';
    $h .= '<input class="form-check-input" type="checkbox" role="switch" id="trBotFilterEnabled" name="bot_filter_enabled" value="1"'.(!empty($settings['bot_filter_enabled']) ? ' checked' : '').'>';
    $h .= '<label class="form-check-label" for="trBotFilterEnabled">'.$addon_lang['label_bot_filter_enabled'].'</label>';
    $h .= '</div>';

    $h .= '<div class="form-check form-switch mb-1">';
    $h .= '<input class="form-check-input" type="checkbox" role="switch" id="trBotHeaderFilter" name="bot_header_filter" value="1"'.(!empty($settings['bot_header_filter']) ? ' checked' : '').'>';
    $h .= '<label class="form-check-label" for="trBotHeaderFilter">'.$addon_lang['label_bot_header_filter'].tr_hint_icon($addon_lang['hint_bot_header_filter']).'</label>';
    $h .= '</div>';
    $h .= '<div class="small text-muted mb-3 ms-5">'
        .$addon_lang['label_reason_no_lang'].$count_badge('no_lang').' &middot; '
        .$addon_lang['label_reason_no_sec_fetch'].$count_badge('no_sec_fetch').' &middot; '
        .$addon_lang['label_reason_no_ua'].$count_badge('no_ua')
        .'</div>';

    $h .= '<div class="form-check form-switch mb-1">';
    $h .= '<input class="form-check-input" type="checkbox" role="switch" id="trBotOutdatedFilter" name="bot_outdated_filter" value="1"'.(!empty($settings['bot_outdated_filter']) ? ' checked' : '').'>';
    $h .= '<label class="form-check-label" for="trBotOutdatedFilter">'.$addon_lang['label_bot_outdated_filter'].tr_hint_icon(sprintf($addon_lang['hint_bot_outdated_filter'], tr_estimated_chrome_major(), tr_chrome_min_major())).'</label>';
    $h .= '</div>';
    $h .= '<div class="small text-muted mb-3 ms-5">'
        .sprintf($addon_lang['label_outdated_threshold'], tr_chrome_min_major()).$count_badge('old_chrome')
        .'</div>';
    $h .= '<div class="mb-3">';
    $h .= '<label class="form-label">'.$addon_lang['label_bot_retention_days'].tr_hint_icon($addon_lang['hint_bot_retention_days']).'</label>';
    $h .= '<input type="number" min="0" class="form-control" name="bot_retention_days" value="'.(int) ($settings['bot_retention_days'] ?? 14).'" style="max-width:160px">';
    $h .= '</div>';

    $h .= '<button type="submit" class="btn btn-primary">'.$addon_lang['btn_save'].'</button>';
    $h .= '</form>';

    $h .= '<hr>';
    $h .= '<p class="small text-muted">'.$addon_lang['hint_reclassify'].'</p>';
    $h .= '<form '.$post.' hx-disabled-elt="find button">';
    $h .= '<input type="hidden" name="reclassify_bots" value="1">'.$csrf;
    $h .= '<button type="submit" class="btn btn-default"><i class="bi bi-arrow-repeat"></i> '.$addon_lang['btn_reclassify'].'</button>';
    $h .= '</form>';
    $h .= '</div>';

    /* Right: custom patterns + built-in list */
    $h .= '<div class="col-lg-7 mb-3">';
    $h .= '<label class="form-label">'.$addon_lang['label_custom_patterns'].tr_hint_icon($addon_lang['hint_custom_patterns']).'</label>';

    if (!$custom) {
        $h .= '<p class="text-muted small">'.$addon_lang['msg_no_custom_patterns'].'</p>';
    } else {
        $h .= '<ul class="list-group mb-2" style="max-height:260px;overflow-y:auto;">';
        foreach ($custom as $p) {
            $h .= '<li class="list-group-item d-flex justify-content-between align-items-center py-1">';
            $h .= '<span><code>'.htmlspecialchars($p).'</code>'.$count_badge('ua:'.$p).'</span>';
            $h .= '<form '.$post.' class="mb-0">';
            $h .= '<input type="hidden" name="remove_bot_pattern" value="'.htmlspecialchars($p, ENT_QUOTES).'">'.$csrf;
            $h .= '<button type="submit" class="btn btn-sm btn-link text-danger" title="'.htmlspecialchars($addon_lang['btn_remove'], ENT_QUOTES).'"><i class="bi bi-trash"></i></button>';
            $h .= '</form>';
            $h .= '</li>';
        }
        $h .= '</ul>';
    }

    $h .= '<form '.$post.' class="d-flex gap-2 mb-3">';
    $h .= '<input type="hidden" name="add_bot_pattern" value="1">'.$csrf;
    $h .= '<input type="text" class="form-control" name="pattern" maxlength="100" required placeholder="'.htmlspecialchars($addon_lang['placeholder_pattern'], ENT_QUOTES).'">';
    $h .= '<button type="submit" class="btn btn-default text-nowrap"><i class="bi bi-plus-lg"></i> '.$addon_lang['btn_add'].'</button>';
    $h .= '</form>';

    $h .= '<details>';
    $h .= '<summary class="small text-muted">'.sprintf($addon_lang['label_default_patterns'], count($defaults)).'</summary>';
    $h .= '<div class="mt-2" style="max-height:220px;overflow-y:auto;">';
    foreach ($defaults as $p) {
        $n = $reason_counts['ua:'.$p] ?? 0;
        $h .= '<span class="badge border text-body fw-normal me-1 mb-1">'.htmlspecialchars($p).($n > 0 ? ' <span class="text-muted">'.number_format($n, 0, ',', '.').'</span>' : '').'</span>';
    }
    $h .= '</div>';
    $h .= '</details>';
    $h .= '</div>';

    $h .= '</div>';
    return $h;
}
