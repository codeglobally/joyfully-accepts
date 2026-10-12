<?php
// Private files live outside the web root when the host allows it,
// otherwise in a private/ folder here that .htaccess blocks.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

$error = '';
$code = normalise_code(input($_GET, 'code'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $code = normalise_code(input($_POST, 'code'));

    if (too_many_attempts()) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        $q = db()->prepare('SELECT id FROM guests WHERE code = ?');
        $q->execute([$code]);
        $id = $q->fetchColumn();
        if ($id) {
            session_regenerate_id(true);
            $_SESSION['guest_id'] = (int)$id;
            header('Location: /guest.php');
            exit;
        }
        record_failed_attempt();
        $error = "That code doesn't match an invitation. Please check it and try again.";
    }
} elseif ($code === '' && current_guest()) {
    header('Location: /guest.php');
    exit;
}

page_header(COUPLE_NAMES, has_photo() ? 'photo-bg' : 'centered');
?>
<?php if (has_photo() && PHOTO_HOTSPOTS): ?>
<!-- Easter eggs: invisible links over parts of the photo (PHOTO_HOTSPOTS in config). -->
<div class="photo-frame">
  <?php foreach (array_values(PHOTO_HOTSPOTS) as $i => $spot): ?>
    <a class="photo-hotspot photo-hotspot-<?= $i ?>" href="<?= h($spot['url'] ?? '#') ?>" target="_blank" rel="noopener"
       title="<?= h($spot['title'] ?? '') ?>" aria-label="<?= h($spot['title'] ?? 'Link') ?>"></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (has_photo() && PHOTO_CAPTION): ?>
<p class="photo-caption"><?= implode(', ', array_map(
    fn($place, $url) => $url ? '<a class="easter-egg" href="' . h($url) . '" target="_blank" rel="noopener">' . h($place) . '</a>' : h($place),
    array_keys(PHOTO_CAPTION), PHOTO_CAPTION)) ?></p>
<?php endif; ?>
<main class="entry lightbox">
  <p class="eyebrow">You're invited</p>
  <h1><?= h(COUPLE_NAMES) ?></h1>
  <p class="date"><?= h(WEDDING_DATE) ?></p>

  <form method="post" action="/" class="code-box" autocomplete="off">
    <?= csrf_field() ?>
    <label for="code">Enter your invitation code</label>
    <input id="code" name="code" type="text" inputmode="text" maxlength="12"
           autocapitalize="characters" spellcheck="false" required
           placeholder="XXXXXXXX" value="<?= h($code) ?>"
           <?= $code === '' ? 'autofocus' : '' ?>>
    <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <button type="submit">Enter</button>
  </form>
  <?= lightbox_footer() ?>
</main>
<?php page_footer();
