<?php

require __DIR__.'/../global/bootstrap.php';

// Same lazy aggregation as the Übersicht tab - keeps bot_reason-based
// counts and the stats in step when this tab is opened first.
tr_maybe_aggregate();

echo '<h1>'.$addon_lang['title_rawdata'].'</h1>';
echo '<p class="text-muted">'.$addon_lang['intro_rawdata'].'</p>';

// Filter form, view switch and drill-down links inside show=raw_data all
// re-render this whole container, same pattern as #trOverviewContent.
echo '<div id="trRawContent" hx-get="/admin-xhr/addons/plugin/tracker/read/?show=raw_data" hx-trigger="load">';
echo 'LOADING ...';
echo '</div>';
