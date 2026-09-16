<?php
/** Guest-facing upload page. This is what the QR codes point at. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
$cfg = config();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Share your photos — <?= e($cfg['couple_names']) ?></title>
<meta name="description" content="Upload the photos and videos you took at our wedding.">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main class="wrap">

  <header class="masthead">
    <h1 class="masthead__names"><?= e($cfg['couple_names']) ?></h1>
    <?php if (!empty($cfg['wedding_date'])): ?>
      <p class="masthead__date"><?= e($cfg['wedding_date']) ?></p>
    <?php endif; ?>
  </header>

  <p class="lede">
    We would love to see the day through your eyes. Add any photos or videos
    you took — there is no app to install and nothing to sign in to.
  </p>

  <?php if (empty($cfg['uploads_open'])): ?>

    <div class="card">
      <p style="text-align:center;margin:0">
        Uploads are now closed. Thank you to everyone who shared something!
      </p>
    </div>

  <?php else: ?>

    <form class="card" data-uploader onsubmit="return false">

      <div class="field">
        <label for="from">Your name <span style="text-transform:none">(optional)</span></label>
        <input type="text" id="from" name="from" autocomplete="name"
               placeholder="So we know who to thank">
      </div>

      <!--
        No `capture` attribute: that would force the camera open. Guests want
        their existing camera roll.
      -->
      <input type="file" name="files" accept="image/*,video/*" multiple hidden>

      <button type="button" class="btn btn--ghost" data-pick>
        Choose photos &amp; videos
      </button>

      <ul class="up-list" data-list></ul>

      <div class="field" style="margin-top:20px">
        <label for="note">Leave a note <span style="text-transform:none">(optional)</span></label>
        <textarea id="note" name="note" placeholder="A memory from the day…"></textarea>
      </div>

      <button type="button" class="btn" data-start hidden>Upload</button>

      <p class="status" data-status></p>
    </form>

    <p class="help">
      Large videos can take a few minutes. If your connection drops, just open
      this page again and tap upload — it picks up where it left off.
    </p>

  <?php endif; ?>

  <p class="help">
    <a href="gallery.php">See the photos everyone has shared →</a>
  </p>

</main>
<script src="assets/upload.js"></script>
</body>
</html>
