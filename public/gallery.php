<?php
/** Public gallery. Shows approved uploads only. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$cfg   = config();
$items = manifest_by_status('approved');

// Newest first.
usort($items, fn($a, $b) => ($b['uploaded_at'] ?? 0) <=> ($a['uploaded_at'] ?? 0));

render_head('The Gallery — Ali & Robert');
?>
  <main class="wrap wrap--wide">

    <?php render_masthead('Photographs from the Wedding of'); ?>
    <?php render_divider(); ?>

    <h2 class="title">The Gallery</h2>

    <?php if (!$items): ?>

      <p class="empty">
        Nothing here yet.<br>Be the first to share something from the day.
      </p>

    <?php else: ?>

      <p class="label" style="margin-top:var(--space-2)">
        <?= count($items) ?> Shared
      </p>

      <div class="grid">
        <?php foreach ($items as $item): ?>
          <?php
            $id      = $item['file_id'];
            $isVideo = !empty($item['is_video']);
          ?>
          <button class="tile"
                  data-id="<?= e($id) ?>"
                  data-video="<?= $isVideo ? '1' : '0' ?>"
                  aria-label="Open <?= e($item['name']) ?>">
            <span class="tile__frame">
              <img src="api/media.php?id=<?= e($id) ?>&amp;size=thumb&amp;w=500"
                   alt="" loading="lazy" decoding="async">
              <?php if ($isVideo): ?>
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

    <?php render_divider(); ?>

    <p class="linkrow"><a href="index.php">Add Your Photographs</a></p>

  </main>

  <div class="lightbox" hidden data-lightbox>
    <button class="lightbox__close" data-close>Close</button>
    <div data-stage></div>
  </div>

  <script>
  /* Full-size media is fetched only when a tile is opened, so the grid stays
     light even with a hundred items on a phone. */
  (function () {
    var box   = document.querySelector('[data-lightbox]');
    var stage = box.querySelector('[data-stage]');
    var lastFocus = null;

    function close() {
      stage.innerHTML = '';   // stops any playing video
      box.hidden = true;
      if (lastFocus) lastFocus.focus();
    }

    document.querySelectorAll('.tile').forEach(function (tile) {
      tile.addEventListener('click', function () {
        lastFocus = tile;
        var id  = tile.dataset.id;
        var src = 'api/media.php?id=' + encodeURIComponent(id) + '&size=full';

        if (tile.dataset.video === '1') {
          var v = document.createElement('video');
          v.src = src;
          v.controls = true;
          v.autoplay = true;
          v.playsInline = true;
          stage.appendChild(v);
        } else {
          var img = document.createElement('img');
          img.src = src;
          img.alt = '';
          stage.appendChild(img);
        }
        box.hidden = false;
        box.querySelector('[data-close]').focus();
      });
    });

    box.addEventListener('click', function (evt) {
      if (evt.target === box || evt.target.hasAttribute('data-close')) close();
    });

    document.addEventListener('keydown', function (evt) {
      if (evt.key === 'Escape' && !box.hidden) close();
    });
  })();
  </script>
<?php render_foot(); ?>
