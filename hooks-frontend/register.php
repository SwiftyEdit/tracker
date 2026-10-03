<?php
/**
 * Registers the tracker's pageview capture on the core 'page.display.after'
 * hook (see app/app.php) - fires once per normal frontend page render,
 * AFTER the page has already been handed to $smarty->display(). See
 * tr_capture_hit() in global/functions.php for what happens there
 * (fastcgi_finish_request() first, then everything else) - this is what
 * makes capture add zero perceived load time and needs no frontend
 * JavaScript at all, unlike a typical sendBeacon()-based analytics script.
 *
 * Loaded automatically by app/routing.php for every active plugin that has
 * a hooks-frontend/ directory - no manual registration needed elsewhere.
 */

se_add_frontend_hook('page.display.after', 'tracker_capture_hit_hook');

function tracker_capture_hit_hook($context): void {
    // bootstrap.php connects $tracker_db and loads global/functions.php.
    // Deliberately required here, lazily, only once the hook actually
    // fires - not at registration time above, which runs on every single
    // frontend request regardless of whether a hit ends up being captured.
    global $tracker_db;
    require_once __DIR__.'/../global/bootstrap.php';

    tr_capture_hit(is_array($context) ? $context : []);
}
