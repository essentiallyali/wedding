<?php
/**
 * One-off admin password reset. Delete this file once you are done.
 *
 * Guarded by proof of filesystem access: a file named reset-ok.txt must exist
 * in the data folder. Only someone who can already reach File Manager or FTP
 * can create it, which is the same person entitled to reset the password.
 * Without that guard this page would let anyone passing by take over the
 * approval queue.
 */

declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$guard = store_path('reset-ok.txt');
$done  = false;
$error = null;

if (!is_file($guard)) {
    render_head('Reset Password');
    ?>
      <main class="wrap">
        <?php render_masthead('', false); ?>
        <?php render_divider(); ?>
        <h2 class="title">One Step First</h2>
        <div class="card" style="margin-top:var(--space-3)">
          <p class="body">
            To prove this is you, create an empty file called
            <strong>reset-ok.txt</strong> inside the <strong>data</strong>
            folder, using File Manager. Then reload this page.
          </p>
          <p class="body" style="margin-top:var(--space-2);opacity:.8">
            Looking for it at:<br><code><?= e($guard) ?></code>
          </p>
        </div>
      </main>
    <?php
    render_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pass  = (string) ($_POST['p1'] ?? '');
    $again = (string) ($_POST['p2'] ?? '');

    if (strlen($pass) < 8) {
        $error = 'Please choose a password of at least 8 characters.';
    } elseif ($pass !== $again) {
        $error = 'The two passwords did not match.';
    } else {
        $cfg = require config_path();
        $cfg['admin_password_hash'] = password_hash($pass, PASSWORD_DEFAULT);

        $php = "<?php\n// Written by the password reset tool. Keep this file private.\nreturn [\n";
        foreach ($cfg as $key => $value) {
            $php .= sprintf("    %-28s => %s,\n", var_export($key, true), var_export($value, true));
        }
        $php .= "];\n";

        if (@file_put_contents(config_path(), $php, LOCK_EX) === false) {
            $error = 'Could not save. Set the lib folder to 755 and try again.';
        } else {
            @unlink($guard);           // single use
            $done = true;
        }
    }
}

render_head('Reset Password');
?>
  <main class="wrap">
    <?php render_masthead('', false); ?>
    <?php render_divider(); ?>

<?php if ($done): ?>
    <h2 class="title">Password Changed</h2>
    <div class="card" style="margin-top:var(--space-3)">
      <p class="body">You can sign in to the approval page with it now.</p>
      <p class="body" style="margin-top:var(--space-2);color:var(--gold-confetti)">
        <strong>Delete this file</strong> (<code>set-password.php</code>) from your server.
      </p>
      <a class="btn btn--spaced" href="admin.php">Go to Approvals</a>
    </div>
<?php else: ?>
    <h2 class="title">Choose a New Password</h2>
  <?php if ($error): ?>
    <div class="card" style="margin-top:var(--space-3)">
      <p class="body" style="color:var(--gold-confetti)"><?= e($error) ?></p>
    </div>
  <?php endif; ?>
    <form class="card" method="post" style="margin-top:var(--space-3)">
      <div class="field">
        <label for="p1">New Password</label>
        <input type="password" id="p1" name="p1" required minlength="8" autofocus
               autocomplete="new-password">
      </div>
      <div class="field" style="margin-top:var(--space-2)">
        <label for="p2">Type It Again</label>
        <input type="password" id="p2" name="p2" required minlength="8"
               autocomplete="new-password">
      </div>
      <button class="btn btn--spaced" type="submit">Set Password</button>
    </form>
<?php endif; ?>

  </main>
<?php render_foot(); ?>
