<?php
/**
 * ONE-TIME SETUP HELPER — delete this file once setup is finished.
 *
 * Walks through the Google OAuth consent flow and prints the two values that
 * belong in lib/config.php: the refresh token and the Drive folder id.
 *
 * The folder is created through the API rather than by hand in the Drive UI.
 * The drive.file scope only reaches files the app itself created, so letting
 * the app create the folder guarantees it can write into it.
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$cfg = config();
admin_session_start();

// Gate the whole page behind the admin password — it is briefly public.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    usleep(300000);
    if (password_verify((string) $_POST['password'], (string) $cfg['admin_password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    }
}

$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path       = strtok($_SERVER['REQUEST_URI'] ?? '/setup-token.php', '?');
$redirectUri = $scheme . '://' . $host . $path;

$result = null;
$error  = null;

if (admin_is_authed() && isset($_GET['code'])) {
    try {
        // 1. Trade the one-time code for tokens.
        $res = drive_http('POST', GOOGLE_TOKEN_URL, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'code'          => $_GET['code'],
                'client_id'     => $cfg['google_client_id'],
                'client_secret' => $cfg['google_client_secret'],
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ]),
        ]);

        $tokens = json_decode($res['body'], true) ?: [];

        if (empty($tokens['refresh_token'])) {
            throw new RuntimeException(
                'Google did not return a refresh token. This usually means you have '
                . 'authorised this app before — revoke it at myaccount.google.com/permissions '
                . 'and try again. Raw response: ' . $res['body']
            );
        }

        // 2. Create the destination folder with the fresh access token.
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
            throw new RuntimeException('Could not create the Drive folder: ' . $folderRes['body']);
        }

        $result = [
            'refresh_token' => $tokens['refresh_token'],
            'folder_id'     => $folder['id'],
        ];
    } catch (Throwable $err) {
        $error = $err->getMessage();
    }
}

$consentUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => $cfg['google_client_id'],
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/drive.file',
    'access_type'   => 'offline',   // required to receive a refresh token
    'prompt'        => 'consent',   // force one, even on repeat authorisation
]);

render_head('Setup — Ali & Robert');
?>
<style>
  code, pre { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; letter-spacing: 0; }
  pre { border: 1px solid rgba(247,231,167,.45); border-radius: var(--radius-button);
        padding: 12px; overflow-x: auto; text-align: left; }
  ol { padding-left: 20px; text-align: left; }
  li { margin-bottom: var(--space-2); }
</style>
  <main class="wrap">

    <?php render_names(); ?>
    <?php render_barn(true); ?>
    <?php render_divider(); ?>

    <h2 class="title">Setup</h2>

<?php if (!admin_is_authed()): ?>

    <form class="card" method="post" style="margin-top:var(--space-3)">
      <div class="field">
        <label for="password">Admin Password</label>
        <input type="password" id="password" name="password" autofocus>
      </div>
      <button class="btn btn--spaced" type="submit">Continue</button>
    </form>

<?php elseif ($result): ?>

    <div class="card" style="margin-top:var(--space-3)">
      <p class="body"><strong>Done.</strong> Put these two values into <code>lib/config.php</code>:</p>
      <pre>'google_refresh_token' =&gt; '<?= e($result['refresh_token']) ?>',
'drive_folder_id'      =&gt; '<?= e($result['folder_id']) ?>',</pre>
      <p class="body" style="margin-top:var(--space-2)">
        A folder named <strong>Wedding Guest Uploads</strong> now exists in your
        Drive. Every upload lands there.
      </p>
      <p class="body" style="margin-top:var(--space-2);color:var(--gold-confetti)">
        <strong>Now delete this file</strong> (<code>public/setup-token.php</code>)
        from your server.
      </p>
    </div>

<?php else: ?>

  <?php if ($error): ?>
    <div class="card" style="margin-top:var(--space-3)">
      <p class="body" style="color:var(--gold-confetti)"><?= e($error) ?></p>
    </div>
  <?php endif; ?>

    <div class="card" style="margin-top:var(--space-3)">
      <ol class="body">
        <li>
          In the Google Cloud console, open your OAuth client and add this exact
          redirect URI:
          <pre><?= e($redirectUri) ?></pre>
        </li>
        <li>
          Make sure the consent screen is <strong>published to Production</strong>.
          Left in Testing, the refresh token silently stops working after 7 days.
        </li>
        <li>Then authorise:</li>
      </ol>
      <a class="btn btn--spaced" href="<?= e($consentUrl) ?>">Connect Google Drive</a>
    </div>

<?php endif; ?>

  </main>
<?php render_foot(); ?>
