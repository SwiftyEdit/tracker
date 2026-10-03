<?php

require __DIR__.'/../global/bootstrap.php';

// Lazy aggregation entry point - see tr_maybe_aggregate() in
// global/functions.php. Throttled internally, so opening this tab
// repeatedly doesn't repeatedly redo the same work.
tr_maybe_aggregate();

echo '<h1>'.$addon_lang['title_overview'].'</h1>';

echo '<div class="row">';
echo '<div class="col-md-12">';
// id targeted by the range-selector buttons rendered inside show=overview
// itself (backend/reader.php) - each click swaps this whole container's
// innerHTML with a freshly rendered range, buttons included, so the active
// state always matches what's actually shown.
echo '<div id="trOverviewContent" hx-get="/admin-xhr/addons/plugin/tracker/read/?show=overview" hx-trigger="load">';
echo 'LOADING ...';
echo '</div>';
echo '</div>';
echo '</div>';
