<?php
// Turn wedding update emails off (or back on) for one invitation.
//
// The link in each update email opens a page with a button, rather than
// unsubscribing straight away, because email security scanners often
// "click" links. Mail apps' own unsubscribe buttons (Gmail, Apple Mail)
// send a one-click POST, which is handled directly as the standard expects.

require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

$code = normalise_code(input($_GET, 'code'));
$guest = null;
$message = '';

if ($code !== '' && !too_many_attempts()) {
    $q = db()->prepare('SELECT * FROM guests WHERE code = ?');
    $q->execute([$code]);
    $guest = $q->fetch() ?: null;
    if (!$guest) {
        record_failed_attempt();
    }
}

if ($guest && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $oneClick = input($_POST, 'List-Unsubscribe') === 'One-Click';
    if (!$oneClick) {
        check_csrf();
    }
    $on = !$oneClick && input($_POST, 'notify') === '1';
    db()->prepare('UPDATE guests SET notify = ? WHERE id = ?')->execute([$on ? 1 : 0, $guest['id']]);
    if ($oneClick) {
        exit(in_language(guest_email_language($guest), fn() => t('unsub.one_click')));
    }
    header('Location: /unsubscribe.php?code=' . urlencode((string)$guest['code']) . '&done=' . ($on ? 'on' : 'off'));
    exit;
}

set_language($guest ? guest_email_language($guest) : visitor_language());
page_header(COUPLE_NAMES, 'photo-bg');
?>
<main class="entry lightbox">
  <p class="eyebrow"><?= th('unsub.eyebrow') ?></p>
  <h1><?= h(COUPLE_NAMES) ?></h1>
  <div class="code-box">
    <?php if (!$guest): ?>
      <p><?= th('unsub.no_match') ?></p>
    <?php else:
        $on = (int)$guest['notify'] === 1;
        $done = input($_GET, 'done'); ?>
      <?php if ($done === 'off'): ?>
        <p class="notice" role="status"><?= th('unsub.done_off') ?></p>
      <?php elseif ($done === 'on'): ?>
        <p class="notice" role="status"><?= th('unsub.done_on') ?></p>
      <?php endif; ?>
      <p><?= th($on ? 'unsub.ask' : 'unsub.off_now', ['name' => greeting_name($guest)]) ?></p>
      <form method="post" action="/unsubscribe.php?code=<?= h(urlencode($code)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="notify" value="<?= $on ? '0' : '1' ?>">
        <button type="submit"><?= th($on ? 'unsub.stop' : 'unsub.restart') ?></button>
      </form>
    <?php endif; ?>
  </div>
  <p class="site-footer"><a href="<?= $guest ? h(invite_link($guest['code'])) : '/' ?>"><?= th('unsub.site_link') ?></a></p>
</main>
<?php page_footer();
