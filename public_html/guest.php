<?php
// Private files live outside the web root when the host allows it,
// otherwise in a private/ folder here that .htaccess blocks.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';
require APP_DIR . '/content.php';
require APP_DIR . '/calendar.php';

$guest = require_guest();
$saved = false;

// One dietary box per person; a single box for the whole party when the
// invitation name doesn't say how many people (e.g. "The O'Brien Family").
$members = party_members($guest['name']);
$dietLabels = $members ?: ['Your party'];
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
    'nobody' => 'Tick at least one person who\'s coming, or choose "Regretfully declines".',
    'count' => 'Let us know how many of you are coming.',
];
$error = $errors[input($_GET, 'error')] ?? '';
$status = rsvp_status($guest);
$savedDiet = dietary_list($guest);
// Who's ticked: what they chose last time, or everyone to start with.
$comingNow = $status === 'yes' ? attendees($guest)['names'] : $members;
$savedMessages = [
    'yes' => 'Thank you, your reply has been saved. We can\'t wait to see you!',
    'no' => 'Thank you for letting us know. You\'ll be missed. You can change your reply here at any time.',
    'maybe' => 'Thanks, we\'ve noted that you\'ll get back to us. Come back and update your reply whenever you know.',
];

// Sections are managed in admin on the Content tab.
$sections = all_sections(true);

page_header(COUPLE_NAMES, has_photo() ? 'photo-bg guest-page' : 'guest-page');
?>
<div class="lightbox page">
<header class="top">
  <span class="brand"><?= h(COUPLE_NAMES) ?></span>
  <a href="/logout.php" class="link">Sign out</a>
</header>

<main class="content">
  <section class="hero">
    <p class="eyebrow">Welcome</p>
    <h1><?= h($guest['name']) ?></h1>
    <p class="date"><?= h(WEDDING_DATE) ?><br><?= place_html() ?></p>
    <?php if (wedding_day()): ?>
    <details class="add-cal">
      <summary>Add to calendar</summary>
      <ul>
        <li><a href="/calendar.php" download>Apple Calendar, iPhone or Outlook app</a><span class="hint">with reminders a week and a day before</span></li>
        <li><a href="<?= h(google_calendar_link()) ?>" target="_blank" rel="noopener">Google Calendar</a></li>
        <li><a href="<?= h(outlook_calendar_link()) ?>" target="_blank" rel="noopener">Outlook.com or Office 365</a></li>
      </ul>
    </details>
    <?php endif; ?>
  </section>

  <section class="card collapsible" id="rsvp" data-key="rsvp">
    <?= card_heading('RSVP', 'rsvp') ?>
    <div class="card-body" id="rsvp-body">
    <?php if ($saved && isset($savedMessages[$status])): ?>
      <p class="notice" role="status"><?= h($savedMessages[$status]) ?></p>
    <?php endif; ?>
    <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/guest.php" class="rsvp-form">
      <?= csrf_field() ?>
      <fieldset>
        <legend>Will you be joining us?</legend>
        <label class="choice">
          <input type="radio" name="attending" value="yes" required <?= $status === 'yes' ? 'checked' : '' ?>>
          <span>Joyfully accepts</span>
        </label>
        <label class="choice">
          <input type="radio" name="attending" value="no" <?= $status === 'no' ? 'checked' : '' ?>>
          <span>Regretfully declines</span>
        </label>
        <label class="choice">
          <input type="radio" name="attending" value="maybe" <?= $status === 'maybe' ? 'checked' : '' ?>>
          <span>Not sure yet, <?= $plural ? "we'll" : "I'll" ?> get back to you</span>
        </label>
      </fieldset>

      <?php if (count($members) > 1): ?>
      <fieldset class="coming">
        <legend>Who's coming?</legend>
        <?php foreach ($members as $i => $member): ?>
          <label class="choice">
            <input type="checkbox" name="coming[]" value="<?= $i ?>" data-person="<?= $i ?>"
                   <?= in_array($member, $comingNow, true) ? 'checked' : '' ?>>
            <span><?= h($member) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <?php elseif (!$members): ?>
      <fieldset class="coming">
        <legend>How many of you are coming?</legend>
        <select name="party_size" class="party-size" aria-label="Number of people coming">
          <option value="">Choose…</option>
          <?php for ($n = 1; $n <= 12; $n++): ?>
            <option value="<?= $n ?>" <?= (int)$guest['party_size'] === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endfor; ?>
        </select>
      </fieldset>
      <?php endif; ?>

      <fieldset class="dietary">
        <legend>Dietary requirements</legend>
        <p class="hint">Choose any that apply<?= count($dietLabels) > 1 ? ' for each of you' : '' ?>, or type your own and press Enter. Leave empty if there are none.</p>
        <?php foreach ($dietLabels as $i => $label):
            $picked = array_values(array_filter(explode(', ', (string)($savedDiet[$i]['needs'] ?? ''))));
            $options = array_merge(DIETARY_OPTIONS, array_diff($picked, DIETARY_OPTIONS)); ?>
          <div class="diet-row" data-person="<?= $i ?>">
            <label for="diet-<?= $i ?>"><?= h($label) ?></label>
            <!-- A plain multi-select; guest.js turns it into the chip picker. -->
            <select id="diet-<?= $i ?>" name="diet[<?= $i ?>][]" multiple class="diet-select"
                    data-placeholder="<?= $members ? 'Choose or type…' : 'e.g. Liam: coeliac' ?>">
              <?php foreach ($options as $option): ?>
                <option<?= in_array($option, $picked, true) ? ' selected' : '' ?>><?= h($option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="updates">
        <label class="choice">
          <input type="checkbox" name="notify" value="1" <?= (int)($guest['notify'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>Email <?= $plural ? 'us' : 'me' ?> updates about the wedding</span>
        </label>
        <p class="hint">Occasional news such as hotel details, timings and reminders. You can turn this off at any time.</p>
      </fieldset>

      <button type="submit"><?= $status === 'waiting' ? 'Send reply' : 'Update reply' ?></button>
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
      <summary class="feedback-button">Send us feedback</summary>
      <form method="post" action="/guest.php" class="feedback-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="feedback">
        <label for="feedback-message">Questions, ideas or anything you'd like us to know. Only we can see this.</label>
        <textarea id="feedback-message" name="message" rows="5" maxlength="2000" required></textarea>
        <?php if ($feedback === 'empty'): ?><p class="error" role="alert">Please write a message first.</p><?php endif; ?>
        <?php if ($feedback === 'limit'): ?><p class="error" role="alert">Thanks! You've sent a few messages today, so please try again tomorrow.</p><?php endif; ?>
        <button type="submit">Send</button>
      </form>
    </details>
    <?php if ($feedback === 'sent'): ?><p class="notice" role="status">Thank you, we've got your message.</p><?php endif; ?>
  </section>
</main>
<?= lightbox_footer() ?>
</div>
<script src="<?= h(asset_url('/assets/guest.js')) ?>"></script>
<?php page_footer();
