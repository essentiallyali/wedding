<?php
/**
 * Approval queue.
 *
 * Nothing a guest uploads reaches the gallery until it is approved here.
 * Rejecting hides an item but keeps the file in Drive; deleting removes it
 * from Drive permanently.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$cfg   = config();
$error = '';

admin_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    // Slow down guessing a little. This page is not linked from anywhere, but
    // it is still on the open internet.
    usleep(300000);
    if (password_verify((string) $_POST['password'], (string) $cfg['admin_password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = 'That password did not match.';
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$authed = admin_is_authed();
$view   = in_array($_GET['view'] ?? 'pending', ['pending', 'approved', 'rejected'], true)
        ? ($_GET['view'] ?? 'pending')
        : 'pending';

$items = $authed ? manifest_by_status($view) : [];
usort($items, fn($a, $b) => ($b['uploaded_at'] ?? 0) <=> ($a['uploaded_at'] ?? 0));

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
if ($authed) {
    foreach (manifest_all() as $entry) {
        $status = $entry['status'] ?? 'pending';
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Approvals</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="wrap<?= $authed ? ' wrap--wide' : '' ?>">

<?php if (!$authed): ?>

  <header class="masthead">
    <h1 class="masthead__names">Approvals</h1>
  </header>

  <form class="card" method="post">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" autofocus>
    </div>
    <?php if ($error): ?>
      <p class="status" data-tone="error"><?= e($error) ?></p>
    <?php endif; ?>
    <button class="btn" type="submit">Sign in</button>
  </form>

<?php else: ?>

  <header class="masthead">
    <h1 class="masthead__names">Approvals</h1>
    <p class="masthead__date">
      <?= $counts['pending'] ?> waiting ·
      <?= $counts['approved'] ?> live ·
      <a href="?logout=1">sign out</a>
    </p>
  </header>

  <nav class="tabs">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Hidden'] as $key => $label): ?>
      <a href="?view=<?= $key ?>" <?= $view === $key ? 'aria-current="page"' : '' ?>>
        <?= $label ?> (<?= $counts[$key] ?>)
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="admin-bar">
    <span class="admin-bar__count" data-count>None selected</span>
    <button class="btn btn--small btn--ghost" data-select-all>Select all</button>
    <?php if ($view !== 'approved'): ?>
      <button class="btn btn--small" data-act="approve" disabled>Approve</button>
    <?php endif; ?>
    <?php if ($view !== 'rejected'): ?>
      <button class="btn btn--small btn--ghost" data-act="reject" disabled>Hide</button>
    <?php endif; ?>
    <button class="btn btn--small btn--ghost" data-act="delete" disabled
            style="color:var(--c-warn);border-color:var(--c-warn)">Delete</button>
  </div>

  <?php if (!$items): ?>
    <p class="empty">Nothing in this list.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($items as $item): ?>
        <?php $id = $item['file_id']; ?>
        <button class="tile tile--selectable"
                data-id="<?= e($id) ?>"
                title="<?= e(($item['from'] ?? '') . ' — ' . ($item['name'] ?? '')) ?>">
          <img src="api/media.php?id=<?= e($id) ?>&amp;size=thumb&amp;w=400"
               alt="" loading="lazy" decoding="async">
          <span class="tile__tick" hidden>✓</span>
          <?php if (!empty($item['is_video'])): ?>
            <span class="tile__play" aria-hidden="true">▶</span>
          <?php endif; ?>
          <?php if (!empty($item['from'])): ?>
            <span class="tile__from"><?= e($item['from']) ?></span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <script>
  (function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var selected = new Set();

    var countEl   = document.querySelector('[data-count]');
    var actionBtns = document.querySelectorAll('[data-act]');
    var tiles     = document.querySelectorAll('.tile--selectable');

    function refresh() {
      countEl.textContent = selected.size
        ? selected.size + ' selected'
        : 'None selected';
      actionBtns.forEach(function (b) { b.disabled = selected.size === 0; });
    }

    tiles.forEach(function (tile) {
      tile.addEventListener('click', function () {
        var id = tile.dataset.id;
        if (selected.has(id)) {
          selected.delete(id);
          tile.classList.remove('is-selected');
          tile.querySelector('.tile__tick').hidden = true;
        } else {
          selected.add(id);
          tile.classList.add('is-selected');
          tile.querySelector('.tile__tick').hidden = false;
        }
        refresh();
      });
    });

    document.querySelector('[data-select-all]').addEventListener('click', function () {
      var selectAll = selected.size !== tiles.length;
      selected.clear();
      tiles.forEach(function (tile) {
        tile.classList.toggle('is-selected', selectAll);
        tile.querySelector('.tile__tick').hidden = !selectAll;
        if (selectAll) selected.add(tile.dataset.id);
      });
      refresh();
    });

    actionBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action = btn.dataset.act;
        if (action === 'delete' &&
            !confirm('Permanently delete ' + selected.size + ' file(s) from Google Drive? This cannot be undone.')) {
          return;
        }

        actionBtns.forEach(function (b) { b.disabled = true; });
        countEl.textContent = 'Working…';

        fetch('api/admin-action.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: action,
            file_ids: Array.from(selected),
            csrf: CSRF
          })
        }).then(function (res) {
          return res.json();
        }).then(function (data) {
          if (data.error) throw new Error(data.error);
          location.reload();
        }).catch(function (err) {
          countEl.textContent = 'Failed: ' + err.message;
          actionBtns.forEach(function (b) { b.disabled = false; });
        });
      });
    });

    refresh();
  })();
  </script>

<?php endif; ?>

</main>
</body>
</html>
