<?php
/**
 * Minimal Google Drive client.
 *
 * Deliberately dependency-free: Bluehost shared hosting cannot be relied on to
 * run Composer, so this talks to the REST API with cURL directly. It only needs
 * the drive.file scope, which is non-sensitive — meaning the OAuth consent
 * screen can be published without going through Google's verification review.
 */

declare(strict_types=1);

require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/store.php';

const DRIVE_API       = 'https://www.googleapis.com/drive/v3';
const DRIVE_UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

class DriveError extends RuntimeException {}

/**
 * Exchange the long-lived refresh token for a short-lived access token.
 *
 * Access tokens last an hour, so the result is cached on disk and reused. This
 * matters: minting a fresh token on every thumbnail request would be both slow
 * and a good way to get rate limited.
 */
function drive_access_token(array $cfg): string
{
    $cached = store_read_json(store_path('token.json'));
    if ($cached && isset($cached['access_token'], $cached['expires_at'])
        && $cached['expires_at'] > time() + 60) {
        return $cached['access_token'];
    }

    $res = drive_http('POST', GOOGLE_TOKEN_URL, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query([
            'client_id'     => $cfg['google_client_id'],
            'client_secret' => $cfg['google_client_secret'],
            'refresh_token' => $cfg['google_refresh_token'],
            'grant_type'    => 'refresh_token',
        ]),
    ]);

    if ($res['status'] !== 200) {
        throw new DriveError('Could not refresh Google access token: ' . $res['body']);
    }

    $data = json_decode($res['body'], true);
    if (!isset($data['access_token'])) {
        throw new DriveError('Token response had no access_token.');
    }

    store_write_json(store_path('token.json'), [
        'access_token' => $data['access_token'],
        'expires_at'   => time() + (int)($data['expires_in'] ?? 3600),
    ]);

    return $data['access_token'];
}

/**
 * Open a resumable upload session and return the URL the browser will PUT to.
 *
 * This is the crux of the whole design: we hold the credentials here, but the
 * file bytes travel from the guest's phone straight to Google. The web host
 * never sees them, so its upload limits and bandwidth are irrelevant.
 */
function drive_create_resumable_session(array $cfg, string $name, string $mime, int $size, string $origin = ''): string
{
    $token = drive_access_token($cfg);

    $metadata = [
        'name'    => $name,
        'parents' => [$cfg['drive_folder_id']],
    ];

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json; charset=UTF-8',
        'X-Upload-Content-Type: ' . $mime,
        'X-Upload-Content-Length: ' . $size,
    ];

    // Google fixes the session's CORS policy from the Origin on this creating
    // request, and ignores the Origin on the browser's later PUTs. Omit it and
    // the browser is refused with no Access-Control-Allow-Origin — which looks
    // exactly like a dropped connection from the phone's side.
    if ($origin !== '') {
        $headers[] = 'Origin: ' . $origin;
    }

    $res = drive_http('POST', DRIVE_UPLOAD_API . '/files?uploadType=resumable&supportsAllDrives=true', [
        'headers' => $headers,
        'body'           => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        'capture_headers' => true,
    ]);

    if ($res['status'] !== 200) {
        throw new DriveError('Drive refused the upload session: ' . $res['body']);
    }

    $location = $res['headers']['location'] ?? null;
    if (!$location) {
        throw new DriveError('Drive returned no upload session URL.');
    }

    return $location;
}

/** Fetch metadata for one file. Returns null if it is gone or not ours. */
function drive_file_meta(array $cfg, string $fileId): ?array
{
    $token  = drive_access_token($cfg);
    $fields = 'id,name,mimeType,size,createdTime,thumbnailLink,videoMediaMetadata,imageMediaMetadata';

    $res = drive_http('GET', DRIVE_API . '/files/' . rawurlencode($fileId) . '?fields=' . rawurlencode($fields), [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);

    if ($res['status'] === 404) {
        return null;
    }
    if ($res['status'] !== 200) {
        throw new DriveError('Drive metadata lookup failed: ' . $res['body']);
    }

    return json_decode($res['body'], true);
}

/** Permanently remove a file from Drive (used when you reject an upload). */
function drive_delete_file(array $cfg, string $fileId): void
{
    $token = drive_access_token($cfg);
    $res = drive_http('DELETE', DRIVE_API . '/files/' . rawurlencode($fileId) . '?supportsAllDrives=true', [
        'headers' => ['Authorization: Bearer ' . $token],
    ]);

    // 404 means it is already gone, which is the outcome we wanted anyway.
    if (!in_array($res['status'], [204, 200, 404], true)) {
        throw new DriveError('Could not delete file: ' . $res['body']);
    }
}

/**
 * Download a Drive thumbnail and return the raw JPEG bytes.
 *
 * thumbnailLink URLs are short-lived, which is exactly why we fetch them here
 * and cache the result locally rather than putting them in an <img src>.
 * Returns null when Drive has not generated a thumbnail yet — common for video
 * in the first minutes after upload, while Google is still processing it.
 */
function drive_thumbnail_bytes(array $cfg, string $fileId, int $width): ?string
{
    $meta = drive_file_meta($cfg, $fileId);
    if (!$meta || empty($meta['thumbnailLink'])) {
        return null;
    }

    // thumbnailLink ends in a size hint like "=s220". Swap in the width we want.
    $url = preg_replace('/=s\d+(-c)?$/', '=w' . $width, $meta['thumbnailLink']);

    $res = drive_http('GET', $url, [
        'headers' => ['Authorization: Bearer ' . drive_access_token($cfg)],
    ]);

    return $res['status'] === 200 ? $res['body'] : null;
}

/**
 * Stream a file's full contents straight to the browser.
 *
 * Passes the caller's Range header through so that video seeking works — a
 * guest scrubbing a clip should not have to download it from the start.
 */
function drive_stream_file(array $cfg, string $fileId, string $mime, ?string $range): void
{
    $token   = drive_access_token($cfg);
    $headers = ['Authorization: Bearer ' . $token];
    if ($range) {
        $headers[] = 'Range: ' . $range;
    }

    $ch = curl_init(DRIVE_API . '/files/' . rawurlencode($fileId) . '?alt=media');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use ($mime) {
            // Forward only the headers the browser needs for playback.
            $lower = strtolower($header);
            foreach (['content-length:', 'content-range:', 'accept-ranges:'] as $keep) {
                if (str_starts_with($lower, $keep)) {
                    header(trim($header));
                }
            }
            if (str_starts_with($lower, 'http/')) {
                $parts = explode(' ', trim($header));
                if (isset($parts[1]) && $parts[1] === '206') {
                    http_response_code(206);
                }
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
            echo $chunk;
            return strlen($chunk);
        },
    ]);

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    curl_exec($ch);
    curl_close($ch);
}

/** Thin cURL wrapper. Returns status, body, and optionally lowercased headers. */
function drive_http(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $responseHeaders = [];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
    ]);

    if (isset($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }

    if (!empty($opts['capture_headers'])) {
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });
    }

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new DriveError('Network call to Google failed: ' . $err);
    }

    return ['status' => $status, 'body' => $body, 'headers' => $responseHeaders];
}
