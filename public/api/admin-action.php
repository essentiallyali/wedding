<?php
/**
 * Approve, reject, or permanently delete an upload.
 *
 * Rejecting hides an item from the gallery but leaves the file in Drive, so a
 * mistaken rejection is reversible. Deleting removes it from Drive for good.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('POST only.', 405);
}

admin_require();

$body = read_json_body();
csrf_check($body['csrf'] ?? null);

$cfg    = config();
$action = (string) ($body['action'] ?? '');
$ids    = $body['file_ids'] ?? [];

if (!is_array($ids) || !$ids) {
    json_error('No files selected.');
}

if (!in_array($action, ['approve', 'reject', 'delete'], true)) {
    json_error('Unknown action.');
}

$done = 0;
foreach ($ids as $rawId) {
    $fileId = trim((string) $rawId);
    if ($fileId === '' || !manifest_find($fileId)) {
        continue;
    }

    if ($action === 'delete') {
        try {
            drive_delete_file($cfg, $fileId);
        } catch (DriveError $err) {
            error_log('[wedding-uploads] delete failed: ' . $err->getMessage());
            continue;
        }
        manifest_remove($fileId);
        @unlink(thumb_cache_path($fileId, 500));
        @unlink(thumb_cache_path($fileId, 1000));
        $done++;
        continue;
    }

    if (manifest_set_status($fileId, $action === 'approve' ? 'approved' : 'rejected')) {
        $done++;
    }
}

json_out(['ok' => true, 'updated' => $done]);
