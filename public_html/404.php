<?php
// "Page not found", in the same style as the rest of the site. .htaccess
// sends missing pages here (ErrorDocument 404).
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

http_response_code(404);

$quips = [
    "This page must have RSVP'd no.",
    'This page wandered off to find the bar.',
    "We've looked under every table. It isn't here.",
    'This page is fashionably late. Very late.',
    'This page got lost on the way to the venue.',
];
$quip = $quips[random_int(0, count($quips) - 1)];
$guest = current_guest();

page_header('Page not found · ' . COUPLE_NAMES, has_photo() ? 'photo-bg' : 'centered');
?>
<main class="entry lightbox not-found">
  <p class="big-404" aria-hidden="true">4<span class="heart">&#x2665;&#xFE0E;</span>4</p>
  <h1>Page not found</h1>
  <p class="quip"><?= h($quip) ?></p>
  <p class="hint">The link may have a typo, or the page has moved.</p>
  <a class="button" href="<?= $guest ? '/guest.php' : '/' ?>"><?= $guest ? 'Back to your invitation' : 'Back to the invitation' ?></a>
  <?= lightbox_footer(false) ?>
</main>
<?php page_footer();
