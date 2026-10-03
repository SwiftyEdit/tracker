<?php
// Same docs-tab pattern as plugins/paperboy/backend/docs.php: sidebar
// (docs_nav) + content pane (docs_content), both hx-get'd from
// backend/reader.php against plugins/tracker/docs/<lang>/*.md.

echo '<div class="row">';
echo '<div class="col-md-3">';

echo '<div hx-get="/admin-xhr/addons/plugin/tracker/read/?show=docs_nav" hx-trigger="load">Loading data ...</div>';

echo '</div>';
echo '<div class="col-md-9">';

echo '<div id="docsContent" hx-get="/admin-xhr/addons/plugin/tracker/read/?show=docs_content" hx-trigger="load">Loading data ...</div>';

echo '</div>';
echo '</div>';
