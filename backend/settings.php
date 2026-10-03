<?php

require __DIR__.'/../global/bootstrap.php';

echo '<h1>'.$addon_lang['title_settings'].'</h1>';

echo '<div class="row">';
echo '<div class="col-lg-6 mb-3">';
echo '<div class="card h-100">';
// Just "load" - no "update_tracker_settings from:body" listener here.
// Both writer.php handlers that touch settings (save_settings, import_geoip)
// deliberately don't fire that event: reloading this whole card on every
// save/import used to wipe the small success message they show in their
// own response target before it was readable (2026-09-04 feedback). See
// writer.php's comments on both handlers for the full reasoning.
echo '<div class="card-body" hx-get="/admin-xhr/addons/plugin/tracker/read/?show=settings_form" hx-trigger="load">LOADING ...</div>';
echo '</div>';
echo '</div>';
// GeoIP import sits beside the settings form, not below it (2026-10-03).
echo '<div class="col-lg-6 mb-3">';
echo '<div class="card h-100">';
echo '<div class="card-body" hx-get="/admin-xhr/addons/plugin/tracker/read/?show=geoip_import" hx-trigger="load">LOADING ...</div>';
echo '</div>';
echo '</div>';
echo '</div>';
