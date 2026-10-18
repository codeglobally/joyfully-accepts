<?php
// "Page not found", in the same style as the rest of the site. .htaccess
// sends missing pages here (ErrorDocument 404).
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

http_response_code(404);

$guest = current_guest();
set_language($guest ? guest_language($guest) : visitor_language());
$quips = t_list('notfound.quips');
$quip = $quips[random_int(0, count($quips) - 1)];

page_header(t('notfound.title') . ' · ' . COUPLE_NAMES, has_photo() ? 'photo-bg' : 'centered');
?>
<main class="entry lightbox not-found">
  <p class="big-404" aria-hidden="true">4<span class="heart">&#x2665;&#xFE0E;</span>4</p>
  <h1><?= th('notfound.title') ?></h1>
  <p class="quip"><?= h($quip) ?></p>
  <p class="hint"><?= th('notfound.hint') ?></p>
  <a class="button" href="<?= $guest ? '/guest.php' : '/' ?>"><?= th($guest ? 'notfound.back_invitation' : 'notfound.back_home') ?></a>
  <?= lightbox_footer(false) ?>
</main>
<?php page_footer();
