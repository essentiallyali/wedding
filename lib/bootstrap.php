<?php
/**
 * Shared bootstrap for every page and endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/view.php';

function config_path(): string
{
    return __DIR__ . '/config.php';
}

/** True once the install wizard has written a config file. */
function config_exists(): bool
{
    return is_file(config_path());
}

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        if (!config_exists()) {
            http_response_code(503);
            exit('This site has not been set up yet. Open setup-token.php to finish installing it.');
        }
        $cfg = require config_path();
    }
    return $cfg;
}

function json_out(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400)
{
    json_out(['error' => $message], $status);
}

function read_json_body(): array
{
    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    // Bluehost sits behind a proxy layer, so prefer the forwarded address when
    // present. This is advisory only — it gates rate limiting, not access.
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd) {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Admin session
// ---------------------------------------------------------------------------

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

function admin_is_authed(): bool
{
    admin_session_start();
    return !empty($_SESSION['admin']);
}

function admin_require(): void
{
    if (!admin_is_authed()) {
        http_response_code(403);
        exit('Not signed in.');
    }
}

/** Single-use CSRF token for admin actions. */
function csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): void
{
    admin_session_start();
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        json_error('Bad or missing CSRF token.', 403);
    }
}
