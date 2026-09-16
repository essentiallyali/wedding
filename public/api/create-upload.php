<?php
/**
 * Mint a Google Drive resumable upload session for one file.
 *
 * The browser sends only the filename, type and size. We validate those against
 * policy, then hand back a URL that the browser PUTs the bytes to directly.
 * The file itself never passes through this server.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';

/** Best-effort content type for files the browser declined to label. */
function mime_from_extension(string $filename): string
{
    static $map = [
        'heic' => 'image/heic',  'heif' => 'image/heif',
        'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg',
        'png'  => 'image/png',   'gif'  => 'image/gif',
        'webp' => 'image/webp',  'avif' => 'image/avif',
        'mov'  => 'video/quicktime', 'mp4' => 'video/mp4',
        'm4v'  => 'video/x-m4v', 'avi' => 'video/x-msvideo',
        '3gp'  => 'video/3gpp',  'hevc' => 'video/mp4',
    ];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('POST only.', 405);
}

$cfg = config();

if (empty($cfg['uploads_open'])) {
    json_error('Uploads are closed. Thank you!', 403);
}

$body = read_json_body();
$name = trim((string) ($body['name'] ?? ''));
$mime = trim((string) ($body['mime'] ?? ''));
$size = (int) ($body['size'] ?? 0);
$from = trim((string) ($body['from'] ?? ''));

if ($name === '' || $size <= 0) {
    json_error('Missing file details.');
}

// iOS Safari frequently reports an empty type for HEIC/HEVC files straight off
// the camera roll, so fall back to the extension rather than turning the guest
// away. Drive needs *some* content type to store against the file.
if ($mime === '') {
    $mime = mime_from_extension($name);
}

if ($size > $cfg['max_file_bytes']) {
    $limitGb = round($cfg['max_file_bytes'] / 1024 / 1024 / 1024, 1);
    json_error("That file is larger than the {$limitGb} GB limit.", 413);
}

// Accept only the media types we asked for. Browsers occasionally report an
// empty or odd type for HEIC, so fall back to checking the extension.
$mimeOk = false;
foreach ($cfg['allowed_mime_prefixes'] as $prefix) {
    if (str_starts_with($mime, $prefix)) {
        $mimeOk = true;
        break;
    }
}
if (!$mimeOk) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mimeOk = in_array($ext, ['heic', 'heif', 'jpg', 'jpeg', 'png', 'gif', 'webp',
                              'mov', 'mp4', 'm4v', 'avi', 'hevc', '3gp'], true);
}
if (!$mimeOk) {
    json_error('Only photos and videos can be uploaded.', 415);
}

if (in_array($mime, $cfg['blocked_mimes'], true)) {
    json_error('That file type is not accepted.', 415);
}

if (!rate_limit_ok(client_ip(), (int) $cfg['max_files_per_ip_per_hour'])) {
    json_error('That is a lot of uploads at once. Please try again in a little while.', 429);
}

// Prefix the stored name with the uploader's name when they gave one, so the
// Drive folder is browsable by who sent what.
$safeFrom  = preg_replace('/[^\p{L}\p{N} \-_]/u', '', $from) ?: '';
$safeName  = str_replace(['/', '\\', "\0"], '-', $name);
$driveName = $safeFrom !== '' ? "{$safeFrom} — {$safeName}" : $safeName;

try {
    $sessionUrl = drive_create_resumable_session($cfg, $driveName, $mime, $size);
} catch (DriveError $err) {
    error_log('[wedding-uploads] ' . $err->getMessage());
    json_error('Could not start the upload. Please try again in a moment.', 502);
}

json_out(['upload_url' => $sessionUrl]);
