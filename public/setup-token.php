<?php
/**
 * INSTALL WIZARD — delete this file once setup is finished.
 *
 * Runs in one of two modes:
 *
 *   Install mode  (lib/config.php does not exist yet)
 *       Collects the Google credentials and an admin password through a form,
 *       runs the Google consent flow, creates the Drive folder, and writes
 *       lib/config.php itself. No hand-editing of PHP, no command line.
 *
 *   Re-auth mode  (lib/config.php exists)
 *       Locked behind the admin password. Used if the Google connection ever
 *       needs to be redone.
 *
 * Install mode is only reachable while there is no config file, which is the
 * same window WordPress and friends leave open. Delete this file afterwards.
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

admin_session_start();

$installing = !config_exists();
$cfg        = $installing ? [] : config();

$error   = null;
$result  = null;
$written = false;

// Where Google must send the browser back to. Must match the redirect URI
// registered on the OAuth client exactly.
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path        = strtok($_SERVER['REQUEST_URI'] ?? '/setup-token.php', '?');
$redirectUri = $scheme . '://' . $host . $path;

$pendingFile = store_path('install.json');

// ---------------------------------------------------------------------------
// Re-auth mode: sign in before anything happens.
// ---------------------------------------------------------------------------
if (!$installing && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    usleep(300000);
    if (password_verify((string) $_POST['password'], (string) ($cfg['admin_password_hash'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        $error = 'That password did not match.';
    }
}

// ---------------------------------------------------------------------------
// Install mode, manual route.
//
// Some hosts run a firewall that blocks Google's sign-in callback: the address
// Google sends back carries another web address inside it, which matches an
// attack signature. Where that cannot be turned off, the refresh token is
// obtained elsewhere and pasted in here instead.
// ---------------------------------------------------------------------------
if ($installing && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['refresh_token'])) {
    $clientId  = trim((string) ($_POST['m_client_id'] ?? ''));
    $secret    = trim((string) ($_POST['m_client_secret'] ?? ''));
    $refresh   = trim((string) $_POST['refresh_token']);
    $pass      = (string) ($_POST['m_admin_password'] ?? '');
    $passAgain = (string) ($_POST['m_admin_password2'] ?? '');

    if ($clientId === '' || $secret === '' || $refresh === '') {
        $error = 'Please fill in the Client ID, the Client Secret and the refresh token.';
    } elseif (strlen($pass) < 8) {
        $error = 'Please choose an admin password of at least 8 characters.';
    } elseif ($pass !== $passAgain) {
        $error = 'The two passwords did not match.';
    } else {
        try {
            $outcome = install_finalise($clientId, $secret, $refresh, password_hash($pass, PASSWORD_DEFAULT));
            if ($outcome['ok']) {
                $result  = $outcome;
                $written = $outcome['written'];
            } else {
                $error = $outcome['error'];
            }
        } catch (Throwable $err) {
            $error = $err->getMessage();
        }
    }
}

// ---------------------------------------------------------------------------
// Install mode, step 1: the details form.
// ---------------------------------------------------------------------------
if ($installing && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['client_id'])) {
    $clientId  = trim((string) $_POST['client_id']);
    $secret    = trim((string) $_POST['client_secret']);
    $pass      = (string) ($_POST['admin_password'] ?? '');
    $passAgain = (string) ($_POST['admin_password2'] ?? '');

    if ($clientId === '' || $secret === '') {
        $error = 'Please paste both the Client ID and the Client Secret.';
    } elseif (!str_contains($clientId, '.apps.googleusercontent.com')) {
        $error = 'That does not look like a Client ID — it should end in .apps.googleusercontent.com';
    } elseif (strlen($pass) < 8) {
        $error = 'Please choose an admin password of at least 8 characters.';
    } elseif ($pass !== $passAgain) {
        $error = 'The two passwords did not match.';
    } else {
        // Held aside until Google confirms; only then is config.php written.
        store_write_json($pendingFile, [
            'google_client_id'     => $clientId,
            'google_client_secret' => $secret,
            'admin_password_hash'  => password_hash($pass, PASSWORD_DEFAULT),
            'started_at'           => time(),
        ]);
        $_SESSION['installing'] = true;
        header('Location: ' . $path . '?connect=1');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Both modes: Google sent the browser back with a one-time code.
// ---------------------------------------------------------------------------
$pending = $installing ? store_read_json($pendingFile) : null;

if (isset($_GET['code']) && ($pending || admin_is_authed())) {
    $clientId = $pending['google_client_id']     ?? ($cfg['google_client_id'] ?? '');
    $secret   = $pending['google_client_secret'] ?? ($cfg['google_client_secret'] ?? '');

    try {
        // 1. Trade the one-time code for a lasting permission slip.
        $res = drive_http('POST', GOOGLE_TOKEN_URL, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'code'          => $_GET['code'],
                'client_id'     => $clientId,
                'client_secret' => $secret,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ]),
        ]);

        $tokens = json_decode($res['body'], true) ?: [];

        if (empty($tokens['refresh_token'])) {
            throw new RuntimeException(
                'Google did not send back a lasting permission slip. This usually means you '
                . 'have already allowed this app once before. Go to '
                . 'myaccount.google.com/permissions, remove "Wedding Photo Uploads", then try again. '
                . 'Google said: ' . $res['body']
            );
        }

        // 2. Same finishing steps as the manual route.
        $outcome = install_finalise(
            $clientId,
            $secret,
            $tokens['refresh_token'],
            $pending['admin_password_hash'] ?? ($cfg['admin_password_hash'] ?? '')
        );

        if (!$outcome['ok']) {
            throw new RuntimeException($outcome['error']);
        }

        $result  = $outcome;
        $written = !empty($outcome['written']);

        if ($installing && $pending && $written) {
            @unlink($pendingFile);
            @unlink($pendingFile . '.lock');
        }
    } catch (Throwable $err) {
        $error = $err->getMessage();
    }
}

/**
 * Verify a set of credentials against Google, create the Drive folder, and
 * write the config file. Shared by both install routes.
 *
 * Every call here is outbound, from the server to Google. That matters on
 * hosts whose firewall blocks Google's inbound sign-in callback: outbound
 * calls are not inspected, so this path works where the redirect cannot.
 */
function install_finalise(string $clientId, string $secret, string $refreshToken, string $passHash): array
{
    // Trading the refresh token for an access token proves all three values
    // are right, before anything is written to disk.
    $res = drive_http('POST', GOOGLE_TOKEN_URL, [
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]),
    ]);

    $tokens = json_decode($res['body'], true) ?: [];
    if (empty($tokens['access_token'])) {
        return ['ok' => false, 'error' =>
            'Google would not accept those three values together. Check the Client ID, '
            . 'the Client Secret and the refresh token all came from the same project. '
            . 'Google said: ' . $res['body']];
    }

    // The drive.file scope only reaches files the app itself created, so the
    // folder must be made through the API rather than by hand in Drive.
    $folderRes = drive_http('POST', DRIVE_API . '/files?fields=id,name', [
        'headers' => [
            'Authorization: Bearer ' . $tokens['access_token'],
            'Content-Type: application/json',
        ],
        'body' => json_encode([
            'name'     => 'Wedding Guest Uploads',
            'mimeType' => 'application/vnd.google-apps.folder',
        ]),
    ]);

    $folder = json_decode($folderRes['body'], true) ?: [];
    if (empty($folder['id'])) {
        return ['ok' => false, 'error' => 'Could not create the Drive folder: ' . $folderRes['body']];
    }

    $written = write_config([
        'google_client_id'     => $clientId,
        'google_client_secret' => $secret,
        'google_refresh_token' => $refreshToken,
        'drive_folder_id'      => $folder['id'],
        'admin_password_hash'  => $passHash,
    ]);

    return ['ok' => true, 'written' => $written,
            'refresh_token' => $refreshToken, 'folder_id' => $folder['id']];
}

/**
 * Write lib/config.php. Returns false if the directory is not writable, in
 * which case the wizard falls back to showing the values to paste by hand.
 */
function write_config(array $values): bool
{
    $php = "<?php\n"
         . "// Written by the install wizard. Keep this file private.\n"
         . "return [\n";

    foreach ($values as $key => $value) {
        $php .= sprintf("    %-24s => %s,\n", var_export($key, true), var_export($value, true));
    }

    $php .= <<<'TAIL'

    // Upload policy, applied before any upload is allowed to start.
    'max_file_bytes'            => 3 * 1024 * 1024 * 1024,  // 3 GB per file
    'max_files_per_ip_per_hour' => 60,
    'allowed_mime_prefixes'     => ['image/', 'video/'],
    'blocked_mimes'             => ['image/svg+xml'],

    // Set to false after the wedding to close uploads without taking the
    // page down.
    'uploads_open'              => true,
];

TAIL;

    return @file_put_contents(config_path(), $php, LOCK_EX) !== false;
}

$consentUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $pending['google_client_id'] ?? ($cfg['google_client_id'] ?? ''),
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/drive.file',
    'access_type'   => 'offline',   // required to get a lasting permission slip
    'prompt'        => 'consent',   // force one even on a repeat authorisation
]);

render_head('Setup — Ali & Robert');
?>
<style>
  code, pre { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; letter-spacing: 0; }
  pre { border: 1px solid rgba(247,231,167,.45); border-radius: var(--radius-button);
        padding: 12px; overflow-x: auto; text-align: left; }
  ol { padding-left: 20px; text-align: left; }
  li { margin-bottom: var(--space-2); }
  .note { font-size: 14px; opacity: .8; margin-top: var(--space-1); }
</style>
  <main class="wrap">

    <?php render_names(); ?>
    <?php render_barn(true); ?>
    <?php render_divider(); ?>

<?php if ($error): ?>
    <div class="card" style="margin-bottom:var(--space-3)">
      <p class="body" style="color:var(--gold-confetti)"><?= e($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($result && $written): ?>

    <h2 class="title">All Connected</h2>
    <div class="card" style="margin-top:var(--space-3)">
      <p class="body">
        Your site is set up and talking to Google Drive. A folder called
        <strong>Wedding Guest Uploads</strong> is now in your Drive, and every
        photo guests send will land there.
      </p>
      <p class="body" style="margin-top:var(--space-2);color:var(--gold-confetti)">
        <strong>One last thing: delete this file from your server.</strong><br>
        It is called <code>setup-token.php</code>. Setup is done and it is not
        needed again.
      </p>
      <p style="margin-top:var(--space-3)">
        <a class="btn" href="index.php">Go to the Upload Page</a>
      </p>
    </div>

<?php elseif ($result && !$written): ?>

    <h2 class="title">Nearly There</h2>
    <div class="card" style="margin-top:var(--space-3)">
      <p class="body">
        Google is connected, but the site could not save its own settings file —
        the <code>lib</code> folder is not writable. Two options: set that
        folder's permissions to 755 and run this page again, or paste these two
        lines into <code>lib/config.php</code> by hand.
      </p>
      <pre>'google_refresh_token' =&gt; '<?= e($result['refresh_token']) ?>',
'drive_folder_id'      =&gt; '<?= e($result['folder_id']) ?>',</pre>
    </div>

<?php elseif ($installing && !$pending): ?>

    <h2 class="title">Set Up Your Site</h2>
    <p class="body" style="margin-top:var(--space-2)">
      Paste in the two values from the Google Cloud console, and choose the
      password you will use to approve photos.
    </p>

    <form class="card" method="post" style="margin-top:var(--space-3)">
      <div class="field">
        <label for="client_id">Google Client ID</label>
        <input type="text" id="client_id" name="client_id" required
               autocomplete="off" spellcheck="false"
               placeholder="ends in .apps.googleusercontent.com">
      </div>

      <div class="field" style="margin-top:var(--space-2)">
        <label for="client_secret">Google Client Secret</label>
        <input type="text" id="client_secret" name="client_secret" required
               autocomplete="off" spellcheck="false" placeholder="starts with GOCSPX-">
      </div>

      <div class="field" style="margin-top:var(--space-3)">
        <label for="admin_password">Choose an Admin Password</label>
        <input type="password" id="admin_password" name="admin_password" required
               autocomplete="new-password" minlength="8">
        <p class="note">
          This is what you will type to approve photos. At least 8 characters.
          It is not your Google password — pick something new.
        </p>
      </div>

      <div class="field" style="margin-top:var(--space-2)">
        <label for="admin_password2">Type It Again</label>
        <input type="password" id="admin_password2" name="admin_password2" required
               autocomplete="new-password" minlength="8">
      </div>

      <button class="btn btn--spaced" type="submit">Continue to Google</button>
    </form>

    <?php render_divider(); ?>

    <h2 class="title">If Google Sign-In Is Blocked</h2>
    <p class="body" style="margin-top:var(--space-2)">
      Some hosts refuse Google's reply, showing <em>Not Acceptable</em> or
      <em>Mod_Security</em>. If that happens, fetch the permission slip from
      Google directly and paste it below. The instructions are in README.md
      under <strong>If your host blocks the Google callback</strong>.
    </p>

    <form class="card" method="post" style="margin-top:var(--space-3)">
      <div class="field">
        <label for="m_client_id">Google Client ID</label>
        <input type="text" id="m_client_id" name="m_client_id" required
               autocomplete="off" spellcheck="false">
      </div>

      <div class="field" style="margin-top:var(--space-2)">
        <label for="m_client_secret">Google Client Secret</label>
        <input type="text" id="m_client_secret" name="m_client_secret" required
               autocomplete="off" spellcheck="false">
      </div>

      <div class="field" style="margin-top:var(--space-2)">
        <label for="refresh_token">Refresh Token</label>
        <input type="text" id="refresh_token" name="refresh_token" required
               autocomplete="off" spellcheck="false" placeholder="starts with 1//">
      </div>

      <div class="field" style="margin-top:var(--space-3)">
        <label for="m_admin_password">Choose an Admin Password</label>
        <input type="password" id="m_admin_password" name="m_admin_password" required
               autocomplete="new-password" minlength="8">
        <p class="note">At least 8 characters. Not your Google password.</p>
      </div>

      <div class="field" style="margin-top:var(--space-2)">
        <label for="m_admin_password2">Type It Again</label>
        <input type="password" id="m_admin_password2" name="m_admin_password2" required
               autocomplete="new-password" minlength="8">
      </div>

      <button class="btn btn--spaced" type="submit">Finish Setup</button>
    </form>

<?php elseif (!$installing && !admin_is_authed()): ?>

    <h2 class="title">Setup</h2>
    <form class="card" method="post" style="margin-top:var(--space-3)">
      <div class="field">
        <label for="password">Admin Password</label>
        <input type="password" id="password" name="password" autofocus>
      </div>
      <button class="btn btn--spaced" type="submit">Continue</button>
    </form>

<?php else: ?>

    <h2 class="title">Connect Google Drive</h2>
    <div class="card" style="margin-top:var(--space-3)">
      <p class="body">
        Google will ask whether to let <strong>Wedding Photo Uploads</strong>
        use your Drive. Say yes.
      </p>
      <p class="body" style="margin-top:var(--space-2)">
        You will probably see a warning that the app is not verified. That is
        expected — it is your own app and you are the only person who will ever
        see this screen. Click <strong>Advanced</strong>, then
        <strong>Go to Wedding Photo Uploads (unsafe)</strong>.
      </p>
      <p class="note">
        If Google says the redirect URI does not match, the address registered
        on your OAuth client needs to be exactly:<br>
        <code><?= e($redirectUri) ?></code>
      </p>
      <a class="btn btn--spaced" href="<?= e($consentUrl) ?>">Connect Google Drive</a>
    </div>

<?php endif; ?>

  </main>
<?php render_foot(); ?>
