<?php
/**
 * Record an upload that Google has confirmed, so it shows in the approval queue.
 *
 * Called by the browser once the final chunk lands and Drive returns a file id.
 * The id is verified against Drive before it is trusted — a caller cannot inject
 * a record for a file that does not exist or that we do not own.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('POST only.', 405);
}

$cfg    = config();
$body   = read_json_body();
$fileId = trim((string) ($body['file_id'] ?? ''));
$from   = trim((string) ($body['from'] ?? ''));
$note   = trim((string) ($body['note'] ?? ''));

if ($fileId === '') {
    json_error('Missing file id.');
}

if (manifest_find($fileId)) {
    // Retries are expected on flaky connections; treat them as success.
    json_out(['ok' => true, 'duplicate' => true]);
}

try {
    $meta = drive_file_meta($cfg, $fileId);
} catch (DriveError $err) {
    error_log('[wedding-uploads] ' . $err->getMessage());
    json_error('Could not confirm the upload.', 502);
}

if (!$meta) {
    json_error('That file could not be found in Drive.', 404);
}

manifest_add([
    'file_id'  => $meta['id'],
    'name'     => $meta['name'] ?? 'untitled',
    'mime'     => $meta['mimeType'] ?? 'application/octet-stream',
    'size'     => (int) ($meta['size'] ?? 0),
    'from'     => mb_substr($from, 0, 80),
    'note'     => mb_substr($note, 0, 500),
    'is_video' => str_starts_with($meta['mimeType'] ?? '', 'video/'),
]);

json_out(['ok' => true]);
