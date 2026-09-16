<?php
/** Public gallery. Shows approved uploads only. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$cfg   = config();
$items = manifest_by_status('approved');

// Newest first.
usort($items, fn($a, $b) => ($b['uploaded_at'] ?? 0) <=> ($a['uploaded_at'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gallery — <?= e($cfg['couple_names']) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="wrap wrap--wide">

  <header class="masthead">
    <h1 class="masthead__names">Our Day, Your Eyes</h1>
    <p class="masthead__date"><?= count($items) ?> shared so far</p>
  </header>

  <p class="lede">
    <a href="index.php">Add your own photos and videos →</a>
  </p>

  <?php if (!$items): ?>

    <p class="empty">Nothing here yet. Be the first to share something!</p>

  <?php else: ?>

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
          <img src="api/media.php?id=<?= e($id) ?>&amp;size=thumb&amp;w=500"
               alt="" loading="lazy" decoding="async">
          <?php if ($isVideo): ?>
            <span class="tile__play" aria-hidden="true">▶</span>
          <?php endif; ?>
          <?php if (!empty($item['from'])): ?>
            <span class="tile__from"><?= e($item['from']) ?></span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</main>

<div class="lightbox" hidden data-lightbox>
  <button class="lightbox__close" data-close aria-label="Close">&times;</button>
  <div data-stage></div>
</div>

<script>
/* Full-size media is only fetched when a tile is actually opened, so the grid
   stays light even on a phone with a hundred items in it. */
(function () {
  var box   = document.querySelector('[data-lightbox]');
  var stage = box.querySelector('[data-stage]');

  function close() {
    stage.innerHTML = '';   // stops any playing video
    box.hidden = true;
  }

  document.querySelectorAll('.tile').forEach(function (tile) {
    tile.addEventListener('click', function () {
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
</body>
</html>
