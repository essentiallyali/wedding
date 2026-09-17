<?php
/** Guest-facing upload page. This is what the QR codes point at. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
$cfg = config();

render_head(
    'Share Your Photographs — Ali & Robert',
    'Add the photographs and videos you took at our wedding.'
);
?>
  <main class="wrap">

    <?php render_names('Photographs from the Wedding of'); ?>
    <?php render_dateline(); ?>
    <?php render_barn(); ?>
    <?php render_divider(); ?>

    <?php if (empty($cfg['uploads_open'])): ?>

      <h2 class="title">Thank You</h2>
      <p class="body" style="margin-top:var(--space-2)">
        Sharing is now closed. Thank you to everyone who sent us something.
      </p>

    <?php else: ?>

      <h2 class="title">Our Day, Through Your Eyes</h2>

      <p class="body" style="margin-top:var(--space-2)">
        Add any photographs or videos you took. There is nothing to install
        and nothing to sign in to.
      </p>

      <form class="card" data-uploader onsubmit="return false"
            style="margin-top:var(--space-3)">

        <div class="field">
          <label for="from">Your Name</label>
          <input type="text" id="from" name="from" autocomplete="name"
                 placeholder="So we know who to thank">
        </div>

        <!--
          No `capture` attribute: that would force the camera open. Guests
          want their existing camera roll.
        -->
        <input type="file" name="files" accept="image/*,video/*" multiple hidden>

        <button type="button" class="btn btn--spaced" data-pick>Choose Photos &amp; Videos</button>

        <ul class="up-list" data-list></ul>

        <div class="field" style="margin-top:var(--space-3)">
          <label for="note">Leave a Note</label>
          <textarea id="note" name="note" placeholder="A memory from the day…"></textarea>
        </div>

        <button type="button" class="btn btn--spaced" data-start hidden>Upload</button>

        <p class="status" data-status></p>
      </form>

      <p class="help">
        Large videos can take a few minutes. If your connection drops, open
        this page again and tap upload — it carries on from where it stopped.
      </p>

    <?php endif; ?>

    <?php render_divider(); ?>

    <p class="linkrow"><a href="gallery.php">See the Gallery</a></p>

  </main>
  <script src="assets/upload.js"></script>
<?php render_foot(); ?>
