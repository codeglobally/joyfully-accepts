<?php
// Private files live outside the web root when the host allows it,
// otherwise in a private/ folder here that .htaccess blocks.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';
require APP_DIR . '/content.php';
require APP_DIR . '/calendar.php';
require APP_DIR . '/mailer.php';
require APP_DIR . '/users.php';

$guest = require_guest();
set_language(guest_language($guest));
$saved = false;

// One dietary box per person; a single box for the whole party when the
// invitation name doesn't say how many people (e.g. "The O'Brien Family").
$members = party_members($guest['name']);
$dietLabels = $members ?: ['Your party'];   // saved in English; shown as t('rsvp.your_party')
// "we'll" for couples, families and plus-ones; "I'll" for one person.
$plural = count($members) !== 1;

// Feedback box: saved for the couple to read on the admin Feedback tab.
// At most 5 messages per invitation per day, 2,000 characters each.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input($_POST, 'action') === 'feedback') {
    check_csrf();
    $text = trim(str_replace("\r\n", "\n", input($_POST, 'message')));
    preg_match('/^.{0,2000}/us', $text, $m);
    $recent = db()->prepare("SELECT COUNT(*) FROM feedback WHERE guest_id = ? AND created_at > datetime('now', '-1 day')");
    $recent->execute([$guest['id']]);
    if (($m[0] ?? '') === '') {
        $result = 'empty';
    } elseif ($recent->fetchColumn() >= 5) {
        $result = 'limit';
    } else {
        db()->prepare('INSERT INTO feedback (guest_id, guest_name, message) VALUES (?, ?, ?)')
            ->execute([$guest['id'], $guest['name'], $m[0]]);
        send_feedback_notice($guest, $m[0]);   // to admins who asked; the message is saved either way
        $result = 'sent';
    }
    header('Location: /guest.php?feedback=' . $result . '#feedback');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $choice = input($_POST, 'attending');
    $states = ['yes' => RSVP_YES, 'no' => RSVP_NO, 'maybe' => RSVP_MAYBE];
    if (isset($states[$choice])) {
        // Who's coming: ticked names for a party, a number for a "Family".
        $coming = null;
        $partySize = null;
        if ($choice === 'yes') {
            if (count($members) > 1) {
                $ticked = is_array($_POST['coming'] ?? null) ? $_POST['coming'] : [];
                $coming = [];
                foreach ($members as $i => $member) {
                    if (in_array((string)$i, $ticked, true)) {
                        $coming[] = $member;
                    }
                }
                if (!$coming) {
                    header('Location: /guest.php?error=nobody#rsvp');
                    exit;
                }
            } elseif ($members) {
                $coming = $members;
            } else {
                $partySize = (int)input($_POST, 'party_size');
                if ($partySize < 1 || $partySize > 20) {
                    header('Location: /guest.php?error=count#rsvp');
                    exit;
                }
            }
        }
        $answers = is_array($_POST['diet'] ?? null) ? $_POST['diet'] : [];
        $dietary = [];
        foreach ($dietLabels as $i => $label) {
            $dietary[] = ['name' => $label, 'needs' => implode(', ', clean_dietary_picks($answers[$i] ?? []))];
        }
        $notify = input($_POST, 'notify') === '1' ? 1 : 0;
        db()->prepare('UPDATE guests SET attending = ?, attendees = ?, party_size = ?, dietary = ?, notify = ?, responded_at = CURRENT_TIMESTAMP,
                       first_replied_at = COALESCE(first_replied_at, CURRENT_TIMESTAMP) WHERE id = ?')
            ->execute([$states[$choice], $coming === null ? null : json_encode($coming, JSON_UNESCAPED_UNICODE),
                $partySize, json_encode($dietary, JSON_UNESCAPED_UNICODE), $notify, $guest['id']]);
        header('Location: /guest.php?saved=1#rsvp');
        exit;
    }
}
$saved = isset($_GET['saved']);

// Visit tracking for the admin Insights tab (stays on this server).
db()->prepare('UPDATE guests SET first_visit_at = COALESCE(first_visit_at, CURRENT_TIMESTAMP),
               last_visit_at = CURRENT_TIMESTAMP, visits = visits + 1 WHERE id = ?')->execute([$guest['id']]);
$errors = [
    'nobody' => t('rsvp.error_nobody', ['no' => t('rsvp.no')]),
    'count' => t('rsvp.error_count'),
];
$error = $errors[input($_GET, 'error')] ?? '';
$status = rsvp_status($guest);
$savedDiet = dietary_list($guest);
// Who's ticked: what they chose last time, or everyone to start with.
$comingNow = $status === 'yes' ? attendees($guest)['names'] : $members;
$savedMessages = ['yes' => t('rsvp.saved_yes'), 'no' => t('rsvp.saved_no'), 'maybe' => t('rsvp.saved_maybe')];

// Sections are managed in admin on the Content tab.
$sections = localise_sections(all_sections(true), current_language());

page_header(COUPLE_NAMES, has_photo() ? 'photo-bg guest-page' : 'guest-page');
?>
<div class="lightbox page">
<?= language_hint_html($guest) ?>
<header class="top">
  <span class="brand"><?= h(COUPLE_NAMES) ?></span>
  <span class="top-links">
    <?= language_menu('top') ?>
    <a href="/logout.php" class="link"><?= th('guest.sign_out') ?></a>
  </span>
</header>

<main class="content">
  <section class="hero">
    <p class="eyebrow"><?= th('guest.welcome') ?></p>
    <h1><?= h(greeting_name($guest)) ?></h1>
    <p class="date"><?= h(wedding_date_text()) ?><br><?= place_html() ?></p>
    <?php if (wedding_day()): ?>
    <details class="add-cal">
      <summary><?= th('cal.add') ?></summary>
      <ul>
        <li><a href="/calendar.php" download><?= th('cal.apple') ?></a><span class="hint"><?= th('cal.apple_hint') ?></span></li>
        <li><a href="<?= h(google_calendar_link()) ?>" target="_blank" rel="noopener"><?= th('cal.google') ?></a></li>
        <li><a href="<?= h(outlook_calendar_link()) ?>" target="_blank" rel="noopener"><?= th('cal.outlook') ?></a></li>
      </ul>
    </details>
    <?php endif; ?>
  </section>

  <?php
  // Nudge guests who haven't replied, or said "not sure yet": RSVP is always
  // open for them, and it gives a little wiggle (not straight after they save).
  $undecided = in_array($status, ['waiting', 'maybe'], true);
  ?>
  <section class="card collapsible<?= $undecided && !$saved ? ' nudge' : '' ?>" id="rsvp" data-key="rsvp"<?= $undecided ? ' data-open="1"' : '' ?>>
    <?= card_heading(t('rsvp.title'), 'rsvp') ?>
    <div class="card-body" id="rsvp-body">
    <?php if ($saved && isset($savedMessages[$status])): ?>
      <p class="notice" role="status"><?= h($savedMessages[$status]) ?></p>
    <?php endif; ?>
    <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/guest.php" class="rsvp-form">
      <?= csrf_field() ?>
      <fieldset>
        <legend><?= th('rsvp.question') ?></legend>
        <label class="choice">
          <input type="radio" name="attending" value="yes" required <?= $status === 'yes' ? 'checked' : '' ?>>
          <span><?= th('rsvp.yes') ?></span>
        </label>
        <label class="choice">
          <input type="radio" name="attending" value="no" <?= $status === 'no' ? 'checked' : '' ?>>
          <span><?= th('rsvp.no') ?></span>
        </label>
        <label class="choice">
          <input type="radio" name="attending" value="maybe" <?= $status === 'maybe' ? 'checked' : '' ?>>
          <span><?= th($plural ? 'rsvp.maybe_us' : 'rsvp.maybe_me') ?></span>
        </label>
      </fieldset>

      <?php if (count($members) > 1): ?>
      <fieldset class="coming">
        <legend><?= th('rsvp.who') ?></legend>
        <?php foreach ($members as $i => $member): ?>
          <label class="choice">
            <input type="checkbox" name="coming[]" value="<?= $i ?>" data-person="<?= $i ?>"
                   <?= in_array($member, $comingNow, true) ? 'checked' : '' ?>>
            <span><?= h($member === 'Guest' ? t('rsvp.plus_one') : $member) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <?php elseif (!$members): ?>
      <fieldset class="coming">
        <legend><?= th('rsvp.how_many') ?></legend>
        <select name="party_size" class="party-size" aria-label="<?= th('rsvp.how_many_label') ?>">
          <option value=""><?= th('rsvp.choose') ?></option>
          <?php for ($n = 1; $n <= 12; $n++): ?>
            <option value="<?= $n ?>" <?= (int)$guest['party_size'] === $n ? 'selected' : '' ?>><?= h(number_text($n)) ?></option>
          <?php endfor; ?>
        </select>
      </fieldset>
      <?php endif; ?>

      <fieldset class="dietary">
        <legend><?= th('rsvp.diet') ?></legend>
        <p class="hint"><?= th(count($dietLabels) > 1 ? 'rsvp.diet_hint_many' : 'rsvp.diet_hint_one') ?></p>
        <?php foreach ($dietLabels as $i => $label):
            $picked = array_values(array_filter(explode(', ', (string)($savedDiet[$i]['needs'] ?? ''))));
            $options = array_merge(DIETARY_OPTIONS, array_diff($picked, DIETARY_OPTIONS)); ?>
          <div class="diet-row" data-person="<?= $i ?>">
            <label for="diet-<?= $i ?>"><?= h($members ? ($label === 'Guest' ? t('rsvp.plus_one') : $label) : t('rsvp.your_party')) ?></label>
            <!-- A plain multi-select; guest.js turns it into the chip picker. -->
            <select id="diet-<?= $i ?>" name="diet[<?= $i ?>][]" multiple class="diet-select"
                    data-placeholder="<?= th($members ? 'rsvp.diet_placeholder' : 'rsvp.diet_party_placeholder') ?>">
              <?php foreach ($options as $option): ?>
                <option value="<?= h($option) ?>"<?= in_array($option, $picked, true) ? ' selected' : '' ?>><?= h(diet_label($option)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="updates">
        <label class="choice">
          <input type="checkbox" name="notify" value="1" <?= (int)($guest['notify'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span><?= th($plural ? 'rsvp.updates_us' : 'rsvp.updates_me') ?></span>
        </label>
        <p class="hint"><?= th('rsvp.updates_hint') ?></p>
      </fieldset>

      <button type="submit"><?= th($status === 'waiting' ? 'rsvp.send' : 'rsvp.update') ?></button>
    </form>
    </div>
  </section>

  <?php foreach ($sections as $section): ?>
    <section class="card rich collapsible" data-key="section-<?= (int)$section['id'] ?>">
      <?= card_heading($section['title'], 'section-' . (int)$section['id']) ?>
      <div class="card-body" id="section-<?= (int)$section['id'] ?>-body">
        <?= render_markdown($section['body']) ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php $feedback = input($_GET, 'feedback'); ?>
  <section class="feedback-box" id="feedback">
    <details <?= in_array($feedback, ['empty', 'limit'], true) ? 'open' : '' ?>>
      <summary class="feedback-button"><?= th('feedback.button') ?></summary>
      <form method="post" action="/guest.php" class="feedback-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="feedback">
        <label for="feedback-message"><?= th('feedback.label') ?></label>
        <textarea id="feedback-message" name="message" rows="5" maxlength="2000" required></textarea>
        <?php if ($feedback === 'empty'): ?><p class="error" role="alert"><?= th('feedback.empty') ?></p><?php endif; ?>
        <?php if ($feedback === 'limit'): ?><p class="error" role="alert"><?= th('feedback.limit') ?></p><?php endif; ?>
        <button type="submit"><?= th('feedback.send') ?></button>
      </form>
    </details>
    <?php if ($feedback === 'sent'): ?><p class="notice" role="status"><?= th('feedback.sent') ?></p><?php endif; ?>
  </section>
</main>
<?= lightbox_footer() ?>
</div>
<?= js_phrases(['chips.add', 'chips.remove', 'card.collapse', 'card.expand']) ?>
<script src="<?= h(asset_url('/assets/guest.js')) ?>"></script>
<?php page_footer();
