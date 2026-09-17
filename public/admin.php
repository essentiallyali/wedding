<?php
/**
 * Approval queue.
 *
 * Nothing a guest uploads reaches the gallery until it is approved here.
 * Rejecting hides an item but leaves the file in Drive; deleting removes it
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

render_head('Approvals — Ali & Robert');
?>
  <main class="wrap<?= $authed ? ' wrap--wide' : '' ?>">

<?php if (!$authed): ?>

    <?php render_masthead('', false); ?>
    <?php render_divider(); ?>

    <h2 class="title">Approvals</h2>

    <form class="card" method="post" style="margin-top:var(--space-3)">
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" autofocus>
      </div>
      <?php if ($error): ?>
        <p class="status" data-tone="error"><?= e($error) ?></p>
      <?php endif; ?>
      <button class="btn btn--spaced" type="submit">Sign In</button>
    </form>

<?php else: ?>

    <?php render_masthead('', false); ?>
    <?php render_divider(); ?>

    <h2 class="title">Approvals</h2>

    <p class="label" style="margin-top:var(--space-2)">
      <?= $counts['pending'] ?> Waiting
      <span class="bar">|</span>
      <?= $counts['approved'] ?> Live
    </p>

    <nav class="tabs">
      <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Hidden'] as $key => $label): ?>
        <a href="?view=<?= $key ?>" <?= $view === $key ? 'aria-current="page"' : '' ?>>
          <?= $label ?> (<?= $counts[$key] ?>)
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="admin-bar">
      <span class="admin-bar__count" data-count>None Selected</span>
      <button class="btn btn--small" data-select-all>Select All</button>
      <?php if ($view !== 'approved'): ?>
        <button class="btn btn--small" data-act="approve" disabled>Approve</button>
      <?php endif; ?>
      <?php if ($view !== 'rejected'): ?>
        <button class="btn btn--small" data-act="reject" disabled>Hide</button>
      <?php endif; ?>
      <button class="btn btn--small btn--danger" data-act="delete" disabled>Delete</button>
    </div>

    <?php if (!$items): ?>
      <p class="empty">Nothing in this list.</p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($items as $item): ?>
          <?php $id = $item['file_id']; ?>
          <button class="tile tile--selectable"
                  data-id="<?= e($id) ?>"
                  title="<?= e(trim(($item['from'] ?? '') . ' — ' . ($item['name'] ?? ''), ' —')) ?>">
            <span class="tile__frame">
              <img src="api/media.php?id=<?= e($id) ?>&amp;size=thumb&amp;w=400"
                   alt="" loading="lazy" decoding="async">
              <span class="tile__tick" hidden></span>
              <?php if (!empty($item['is_video'])): ?>
                <span class="tile__play" aria-hidden="true"></span>
              <?php endif; ?>
            </span>
            <?php if (!empty($item['from'])): ?>
              <span class="tile__from"><?= e($item['from']) ?></span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="linkrow" style="margin-top:var(--space-4)">
      <a href="gallery.php">Gallery</a>
      <span class="bar">|</span>
      <a href="?logout=1">Sign Out</a>
    </p>

    <script>
    (function () {
      var CSRF = <?= json_encode(csrf_token()) ?>;
      var selected = new Set();

      var countEl    = document.querySelector('[data-count]');
      var actionBtns = document.querySelectorAll('[data-act]');
      var tiles      = document.querySelectorAll('.tile--selectable');

      function refresh() {
        countEl.textContent = selected.size
          ? selected.size + ' Selected'
          : 'None Selected';
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
<?php render_foot(); ?>
