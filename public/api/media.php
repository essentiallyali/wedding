<?php
/**
 * Serve photos and videos out of Drive.
 *
 * Drive's public hotlink endpoints no longer work reliably for third-party
 * sites — uc?export=view returns 403, and the undocumented /thumbnail endpoint
 * rate-limits once a page requests many images at once. So everything is
 * fetched here with the API credentials instead.
 *
 * The useful side effect: approval is enforced at the only door. A pending or
 * rejected file simply has no URL that a guest can reach.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

$cfg    = config();
$fileId = trim((string) ($_GET['id'] ?? ''));
$want   = ($_GET['size'] ?? 'thumb') === 'full' ? 'full' : 'thumb';
$width  = max(120, min(1600, (int) ($_GET['w'] ?? 500)));

if ($fileId === '') {
    http_response_code(400);
    exit('Missing id.');
}

$entry = manifest_find($fileId);
if (!$entry) {
    http_response_code(404);
    exit('Not found.');
}

// Anything not yet approved is visible only to a signed-in admin, so the
// review queue can render while guests still cannot see it.
if (($entry['status'] ?? '') !== 'approved' && !admin_is_authed()) {
    http_response_code(404);
    exit('Not found.');
}

if ($want === 'full') {
    // Videos and full-size photos are streamed rather than cached to disk —
    // caching multi-hundred-megabyte originals on shared hosting is exactly
    // the disk usage this design set out to avoid.
    header('Cache-Control: private, max-age=3600');
    try {
        drive_stream_file($cfg, $fileId, $entry['mime'] ?? 'application/octet-stream',
                          $_SERVER['HTTP_RANGE'] ?? null);
    } catch (DriveError $err) {
        error_log('[wedding-uploads] ' . $err->getMessage());
        http_response_code(502);
    }
    exit;
}

// --- Thumbnail path -------------------------------------------------------
// Cached on disk after the first request. A whole wedding's thumbnails are on
// the order of tens of megabytes, which shared hosting handles happily.

$cachePath = thumb_cache_path($fileId, $width);

if (is_file($cachePath) && filesize($cachePath) > 0) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($cachePath));
    readfile($cachePath);
    exit;
}

try {
    $bytes = drive_thumbnail_bytes($cfg, $fileId, $width);
} catch (DriveError $err) {
    error_log('[wedding-uploads] ' . $err->getMessage());
    $bytes = null;
}

if ($bytes === null) {
    // Drive has not finished generating a thumbnail yet. Send a neutral
    // placeholder with a short cache so the gallery retries shortly.
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=60');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 3">'
       . '<rect width="4" height="3" fill="#e8e2d9"/></svg>';
    exit;
}

file_put_contents($cachePath, $bytes, LOCK_EX);

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
