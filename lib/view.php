<?php
/**
 * Shared page furniture for the Ali & Robert design system.
 *
 * The system asks for the same scaffolding on every piece: string lights
 * bleeding off the top edge, gold dot confetti in two opposite corners, and
 * the Round Barn sketch centred below the names. Keeping it in one place means
 * the three pages cannot drift apart.
 */

declare(strict_types=1);

/** Opening <head> plus the start of the page shell. */
function render_head(string $title, string $description = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($description !== ''): ?>
<meta name="description" content="<?= e($description) ?>">
<?php endif; ?>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0a0935">
<link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="frame">
  <img class="lights" src="assets/brand/string-lights.png" alt="" aria-hidden="true">
  <div class="confetti br" aria-hidden="true"></div>
    <?php
}

function render_foot(): void
{
    ?>
</div>
</body>
</html>
    <?php
}

/**
 * Masthead: the Round Barn beside the names rather than stacked above them.
 *
 * The invitation stacks the names with the ampersand on its own line and sets
 * the barn large and centred beneath. That is right for a 5x7 card, but here
 * it pushed the upload form below the fold on a phone, and the whole point of
 * this page is the button. Same elements, laid out across instead of down.
 */
function render_masthead(string $lead = '', bool $withDate = true): void
{
    ?>
    <header class="masthead">
      <img class="masthead__barn" src="assets/brand/round-barn.png" alt="" aria-hidden="true">
      <div class="masthead__text">
        <?php if ($lead !== ''): ?>
          <p class="lead"><?= e($lead) ?></p>
        <?php endif; ?>
        <h1 class="names">ALI <span class="amp">&amp;</span> ROBERT</h1>
        <?php if ($withDate): ?>
          <p class="dateline">09<span class="bar">|</span>19<span class="bar">|</span>2026</p>
        <?php endif; ?>
      </div>
    </header>
    <?php
}

/** Numeric date with vertical bars, from the invitation front. */
function render_dateline(): void
{
    ?>
<p class="dateline">09<span class="bar">|</span>19<span class="bar">|</span>2026</p>
    <?php
}

/** The Round Barn sketch. It appears on every piece in this system. */
function render_barn(bool $small = false): void
{
    $class = $small ? 'barn barn--small' : 'barn';
    echo '<img class="' . $class . '" src="assets/brand/round-barn.png" alt="" aria-hidden="true">';
}

/** The scroll flourish. One per section — never stack two. */
function render_divider(): void
{
    echo '<img class="divider" src="assets/brand/scroll-divider.png" alt="" aria-hidden="true">';
}
