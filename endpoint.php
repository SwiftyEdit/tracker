<?php
/**
 * Bootstrap-free static-asset endpoint, reached via
 * /dispatch.php?p=tracker&asset=<file> (see public/dispatch.php - runs
 * without the full app/session/Smarty bootstrap, just the SE_* path
 * constants from config.php).
 *
 * Currently serves only the vendored Chart.js UMD build used by the
 * backend overview page's trend chart (backend/reader.php,
 * assets/vendor/chart.umd.min.js, Chart.js v4.4.9 MIT-licensed) - this
 * plugin has no frontend-facing assets, this endpoint is only ever loaded
 * from the admin backend, but dispatch.php itself doesn't distinguish
 * frontend/backend context so the same mechanism image-resizer's own
 * endpoint.php uses works here unchanged.
 */

$requested = basename((string) ($_GET['asset'] ?? ''));

$allowed = [
    'chart.umd.min.js' => 'application/javascript',
];

if ($requested === '' || !isset($allowed[$requested])) {
    http_response_code(404);
    exit;
}

$file = __DIR__.'/assets/vendor/'.$requested;
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: '.$allowed[$requested]);
// Pinned vendored library file (version is part of the plugin release, not
// user-replaceable at runtime) - safe to cache aggressively/immutably.
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: '.filesize($file));
readfile($file);
