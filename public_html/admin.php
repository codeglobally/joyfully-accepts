<?php
// Private files live outside the web root when the host allows it,
// otherwise in a private/ folder here that .htaccess blocks.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';
require APP_DIR . '/mailer.php';
require APP_DIR . '/content.php';
require APP_DIR . '/users.php';
require APP_DIR . '/notify.php';
require APP_DIR . '/insights.php';
require APP_DIR . '/passkeys.php';

$error = '';
$message = '';
set_language(visitor_language());   // until we know who's signed in

// --- Setup link: a new person chooses their password -----------------

if (isset($_GET['setup'])) {
    $token = input($_GET, 'setup');
    $user = user_for_setup_token($token);
    if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $password = input($_POST, 'password');
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $error = t('admin.login.min_length', ['count' => MIN_PASSWORD_LENGTH]);
        } elseif ($password !== input($_POST, 'confirm')) {
            $error = t('admin.login.mismatch');
        } else {
            set_password((int)$user['id'], $password);
            $who = ['id' => (int)$user['id'], 'name' => $user['name'], 'owner' => false];
            if (passkey_required((int)$user['id'])) {
                // A new password from an emailed link still needs their passkey.
                session_regenerate_id(true);
                $_SESSION['pending_admin'] = ['user' => $who, 'until' => time() + PASSKEY_SECONDS];
                header('Location: /admin.php');
                exit;
            }
            sign_in($who);
            $_SESSION['flash'] = in_language(admin_language_choice((int)$user['id']) ?? current_language(),
                fn() => t('admin.login.welcome', ['name' => $user['name'], 'username' => $user['username']]));
            header('Location: /admin.php');
            exit;
        }
    }
    page_header(t('admin.title'), 'centered admin');
    ?>
    <main class="entry">
      <h1><?= th('admin.title') ?></h1>
      <?php if (!$user): ?>
        <p class="code-box"><?= th('admin.setup.expired') ?></p>
      <?php else: ?>
        <form method="post" class="code-box">
          <?= csrf_field() ?>
          <p><?= t_html('admin.setup.choose', ['username' => '<strong>' . h($user['username']) . '</strong>'], ['name' => $user['name']]) ?></p>
          <label for="password"><?= th('admin.setup.new_password', ['count' => MIN_PASSWORD_LENGTH]) ?></label>
          <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
          <label for="confirm"><?= th('admin.setup.again') ?></label>
          <input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
          <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
          <button type="submit"><?= th('admin.setup.save') ?></button>
        </form>
      <?php endif; ?>
      <p class="site-footer"><a href="/admin.php"><?= th('admin.login.sign_in') ?></a></p>
      <?= language_menu() ?>
    </main>
    <?php
    page_footer();
    exit;
}

// --- Forgot password: email a reset link -------------------------------
// The reply is the same whether or not the account exists, and requests count
// towards the sign-in rate limit, so this can't be used to find accounts or
// flood someone's inbox. The current password keeps working until the link
// is used.

if (isset($_GET['forgot'])) {
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (too_many_attempts()) {
            $error = t('admin.login.too_many');
        } else {
            record_failed_attempt();
            $user = find_user_by_login(input($_POST, 'login'));
            if ($user && valid_email($user['email']) && !setup_link_recent($user)) {
                send_setup_email($user, new_setup_link((int)$user['id']), true);
            }
            $sent = true;
        }
    }
    page_header(t('admin.title'), 'centered admin');
    ?>
    <main class="entry">
      <h1><?= th('admin.title') ?></h1>
      <?php if ($sent): ?>
        <p class="code-box"><?= th('admin.forgot.sent', ['count' => SETUP_LINK_DAYS]) ?></p>
      <?php else: ?>
        <form method="post" class="code-box">
          <?= csrf_field() ?>
          <p><?= th('admin.forgot.intro') ?></p>
          <label for="login"><?= th('admin.forgot.login') ?></label>
          <input id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
          <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
          <button type="submit"><?= th('admin.forgot.button') ?></button>
          <p class="hint"><?= t_html('admin.forgot.owner', ['username' => '<code>' . h(OWNER_USERNAME) . '</code>']) ?></p>
        </form>
      <?php endif; ?>
      <p class="site-footer"><a href="/admin.php"><?= back_arrow() ?> <?= th('admin.forgot.back') ?></a></p>
      <?= language_menu() ?>
    </main>
    <?php
    page_footer();
    exit;
}

// --- Passkey options (asked for by assets/passkey.js) ----------------

if (isset($_GET['passkey_options']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $origin = passkey_origin();
    $purpose = input($_POST, 'purpose');
    $pending = pending_admin();
    $options = null;
    if (!$origin) {
        http_response_code(400);
        exit(json_encode(['error' => t('admin.passkey.only_on', ['url' => SITE_URL])]));
    } elseif ($purpose === 'login') {
        $options = passkey_get_options($origin, 'login');
    } elseif ($purpose === 'confirm' && $pending) {
        $options = passkey_get_options($origin, 'confirm', (int)$pending['id']);
    } elseif ($purpose === 'register' && is_admin() && admin_session_valid()) {
        $me = current_admin();
        $username = $me['id'] ? (string)(find_user((int)$me['id'])['username'] ?? '') : OWNER_USERNAME;
        $options = passkey_create_options($origin, $me, $username);
    }
    if ($options === null) {
        http_response_code(403);
        exit(json_encode(['error' => t('admin.passkey.sign_in_again')]));
    }
    exit(json_encode($options));
}

// --- Login -----------------------------------------------------------
// A password, a passkey, or both: once someone has a passkey, a correct
// password only gets them to a "confirm with your passkey" step.

$loginAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? input($_POST, 'action') : '';
if ($loginAction === 'cancel_sign_in') {
    check_csrf();
    unset($_SESSION['pending_admin'], $_SESSION['passkey']);
    header('Location: /admin.php');
    exit;
}
if ($loginAction === 'passkey_login' || $loginAction === 'passkey_confirm') {
    check_csrf();
    $purpose = $loginAction === 'passkey_login' ? 'login' : 'confirm';
    $challenge = take_passkey_challenge($purpose);
    $origin = passkey_origin();
    $stored = find_passkey(input($_POST, 'credential_id'));
    $pending = pending_admin();
    $clientData = b64url_decode(input($_POST, 'client_data'));
    $authData = b64url_decode(input($_POST, 'authenticator_data'));
    $signature = b64url_decode(input($_POST, 'signature'));
    $result = t('admin.passkey.failed');
    if (too_many_attempts()) {
        $result = t('admin.login.too_many');
    } elseif ($challenge && $origin && $stored && $clientData !== null && $authData !== null && $signature !== null
        && ($purpose === 'login' || ($pending && (int)$stored['user_id'] === (int)$pending['id']))) {
        $result = verify_passkey_assertion(['challenge' => $challenge['challenge'], 'origin' => $origin[1], 'rp_id' => $origin[0]],
            $stored, $clientData, $authData, $signature);
    }
    if (is_int($result)) {
        $user = admin_by_id((int)$stored['user_id']);
        if ($user) {
            passkeys_db()->prepare('UPDATE passkeys SET sign_count = ?, last_used_at = CURRENT_TIMESTAMP WHERE id = ?')
                ->execute([$result, $stored['id']]);
            unset($_SESSION['pending_admin']);
            sign_in($user);
            header('Location: /admin.php');
            exit;
        }
        $result = t('admin.passkey.removed_user');
    }
    if (!too_many_attempts()) {
        record_failed_attempt();
    }
    $error = $result;
}

if (isset($_POST['login'])) {
    check_csrf();
    $username = input($_POST, 'username');
    if (too_many_attempts()) {
        $error = t('admin.login.too_many');
    } elseif ($user = check_login($username, input($_POST, 'password'))) {
        if (passkey_required((int)$user['id'])) {
            // Right password; now the passkey.
            session_regenerate_id(true);
            $_SESSION['pending_admin'] = ['user' => $user, 'until' => time() + PASSKEY_SECONDS];
            header('Location: /admin.php');
            exit;
        }
        sign_in($user);
        header('Location: /admin.php');
        exit;
    } else {
        record_failed_attempt();
        $error = normalise_username($username) === OWNER_USERNAME && ADMIN_PASSWORD === 'change-me-please'
            ? t('admin.login.set_password')
            : t('admin.login.wrong');
    }
}

if (!is_admin() && ($pending = pending_admin())) {
    page_header(t('admin.title'), 'centered admin');
    ?>
    <main class="entry">
      <h1><?= th('admin.title') ?></h1>
      <form method="post" class="code-box passkey-step">
        <?= csrf_field() ?>
        <p><?= th('admin.passkey.confirm_intro', ['name' => $pending['name']]) ?></p>
        <button type="button" data-passkey="confirm" hidden><?= th('admin.passkey.use') ?></button>
        <p class="error" data-passkey-unsupported hidden><?= th('admin.passkey.unsupported_confirm') ?></p>
        <p class="error" role="alert" data-passkey-message <?= $error ? '' : 'hidden' ?>><?= h($error) ?></p>
        <button type="submit" class="link" name="action" value="cancel_sign_in"><?= th('admin.passkey.someone_else') ?></button>
      </form>
      <p class="site-footer"><a href="/"><?= back_arrow() ?> <?= th('admin.back_to_site') ?></a></p>
      <?= language_menu() ?>
    </main>
    <?= js_phrases(['admin.js.reload', 'admin.js.exists', 'admin.js.not_added', 'admin.js.cancelled', 'admin.js.wrong']) ?>
    <script src="<?= h(asset_url('/assets/passkey.js')) ?>"></script>
    <?php
    page_footer();
    exit;
}

if (!is_admin()) {
    page_header(t('admin.title'), 'centered admin');
    ?>
    <main class="entry">
      <h1><?= th('admin.title') ?></h1>
      <form method="post" class="code-box">
        <?= csrf_field() ?>
        <label for="username"><?= th('admin.login.username') ?></label>
        <input id="username" name="username" type="text" autocomplete="username webauthn" autocapitalize="none"
               spellcheck="false" required autofocus value="<?= h(input($_POST, 'username')) ?>">
        <label for="password"><?= th('admin.login.password') ?></label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <p class="error" role="alert" data-passkey-message <?= $error ? '' : 'hidden' ?>><?= h($error) ?></p>
        <button type="submit" name="login" value="1"><?= th('admin.login.sign_in') ?></button>
        <button type="button" class="secondary passkey-button" data-passkey="login" hidden><?= th('admin.login.with_passkey') ?></button>
        <p class="forgot"><a href="/admin.php?forgot=1"><?= th('admin.login.forgot') ?></a></p>
      </form>
      <p class="site-footer"><a href="/"><?= back_arrow() ?> <?= th('admin.back_to_site') ?></a></p>
      <?= language_menu() ?>
    </main>
    <?= js_phrases(['admin.js.reload', 'admin.js.exists', 'admin.js.not_added', 'admin.js.cancelled', 'admin.js.wrong']) ?>
    <script src="<?= h(asset_url('/assets/passkey.js')) ?>"></script>
    <?php
    page_footer();
    exit;
}

// A removed person's session ends straight away.
// A session ends straight away if its person has been removed or their
// password has changed since they signed in (see admin_session_valid()).
if (!admin_session_valid()) {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin.php');
    exit;
}
$me = current_admin();
set_language(admin_language_choice((int)$me['id']) ?? visitor_language());

$unreadFeedback = (int)db()->query('SELECT COUNT(*) FROM feedback WHERE read_at IS NULL')->fetchColumn();
$tabs = [];
foreach (['guests', 'insights', 'email', 'updates', 'content', 'feedback', 'account'] as $key) {
    $tabs[$key] = t('admin.tab.' . $key);
}
$tabs['feedback'] .= $unreadFeedback ? ' (' . number_text($unreadFeedback) . ')' : '';
if (is_owner()) {
    $tabs['people'] = t('admin.tab.people');
}
$tab = input($_GET, 'tab');
if (!isset($tabs[$tab])) {
    $tab = 'guests';
}

// The People tab's "Feedback emails" tick box for one person. Ticking it
// saves straight away (admin.js); without JavaScript there's a Save button.
function feedback_toggle(int $id, string $name, array $contact): string
{
    if (!valid_email($contact['email'])) {
        return '<span class="hint">' . th('admin.people.no_email') . '</span>';
    }
    $label = $id === 0 ? t('admin.people.toggle_you') : t('admin.people.toggle_other', ['name' => $name]);
    return '<form method="post" class="inline feedback-toggle" data-autosubmit>' . csrf_field()
        . '<input type="hidden" name="action" value="user_feedback"><input type="hidden" name="id" value="' . $id . '">'
        . '<label class="check"><input type="checkbox" name="notify_feedback" value="1"' . ($contact['notify'] ? ' checked' : '')
        . ' aria-label="' . h($label) . '"> ' . th($contact['notify'] ? 'admin.on' : 'admin.off') . '</label>'
        . ' <button class="link small" data-autosubmit-button>' . th('admin.save') . '</button></form>';
}

// "{name}, {link} and {code} are filled in…", with the placeholders as code.
function placeholders_hint(): string
{
    return t_html('admin.email.placeholders', ['name' => '<code>{name}</code>', 'link' => '<code>{link}</code>', 'code' => '<code>{code}</code>']);
}

// The formatting help under the section editor, with examples in the admin's language.
function markdown_hint(): string
{
    $code = fn(string $s) => '<code dir="ltr">' . h($s) . '</code>';
    return t_html('admin.md.hint', [
        'heading' => $code('## '), 'bullet' => $code('- '),
        'bold' => $code('**' . t('admin.md.sample_bold') . '**'), 'italic' => $code('*' . t('admin.md.sample_italic') . '*'),
        'link' => $code('[' . t('admin.md.sample_link') . '](https://example.com)'),
        'size' => $code('[large]' . t('admin.md.sample_text') . '[/large]'), 'small' => $code('small'), 'xlarge' => $code('xlarge'),
    ]);
}

// Which language's version is being edited: one compact menu, however many
// languages there are. Each language shows whether it's the main one,
// translated or not yet, with a count and a link to the next one to do.
//   $codes   the languages to offer, in order
//   $current the one being edited
//   $done    the languages that have a version
//   $href    fn(code) => the link for that language
// With $switch, the links are #anchors that admin.js turns into switching
// between translations already on the page (the Updates tab).
function lang_picker(array $codes, string $current, array $done, callable $href, bool $switch = false): string
{
    $status = fn(string $code) => $code === site_language() ? 'main' : (in_array($code, $done, true) ? 'done' : 'todo');
    $label = fn(string $code) => ['main' => t('admin.main'), 'done' => '✓ ' . t('admin.translated'), 'todo' => t('admin.picker.todo')][$status($code)];
    $others = array_values(array_diff($codes, [site_language()]));
    $doneCount = count(array_intersect($others, $done));
    // The next language without a version, after the current one.
    $at = (int)array_search($current, $codes, true);
    $next = null;
    foreach (array_merge(array_slice($codes, $at + 1), array_slice($codes, 0, $at)) as $code) {
        if ($status($code) === 'todo') {
            $next = $code;
            break;
        }
    }
    $html = '<div class="lang-picker"' . ($switch ? ' data-lang-switch hidden' : '') . '>'
        . '<details class="lang-menu"><summary aria-label="' . th('admin.picker.choose') . '">'
        . '<span class="hint">' . th('admin.picker.editing') . '</span> '
        . '<strong lang="' . h($current) . '" data-current>' . h(LANGUAGE_NAMES[$current]) . '</strong> '
        . '<span class="status ' . $status($current) . '" data-current-status>' . h($label($current)) . '</span></summary>'
        . '<ul class="lang-menu-list">';
    foreach ($codes as $code) {
        $html .= '<li><a href="' . h($href($code)) . '" data-lang="' . h($code) . '"' . ($code === $current ? ' aria-current="page"' : '') . '>'
            . '<span lang="' . h($code) . '">' . h(LANGUAGE_NAMES[$code]) . '</span> '
            . '<span class="status ' . $status($code) . '">' . h($label($code)) . '</span></a></li>';
    }
    $html .= '</ul></details><span class="hint">' . th('admin.picker.progress', ['done' => $doneCount, 'total' => count($others)]) . '</span>';
    $html .= $next !== null
        ? '<a class="link" href="' . h($href($next)) . '" data-lang="' . h($next) . '">' . th('admin.picker.next') . ' ' . (is_rtl() ? '&larr;' : '&rarr;') . '</a>'
        : '<span class="hint">' . th('admin.picker.all_done') . '</span>';
    return $html . '</div>';
}

// A dietary chart label: the requirement in the admin's language, or "Other".
function segment_label(string $label): string
{
    return $label === 'Other' ? t('admin.diet.other') : diet_label($label);
}

// What went out on one day of the timeline: "Invitations (12), Update: Hotels (40)".
function sends_text(array $sends): string
{
    $parts = [];
    foreach ($sends as $what => $n) {
        $label = $what === 'invitations' ? t('admin.funnel.invitations') : t('admin.ins.update_label', ['subject' => substr($what, 8)]);
        $parts[] = $label . ' (' . number_text($n) . ')';
    }
    return join_names($parts);
}

// --- Actions --------------------------------------------------------
// Every action redirects afterwards, so refreshing the page can't repeat it
// (important for sending emails).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = input($_POST, 'action');
    $message = '';
    $back = 'guests';          // the tab to return to
    $backExtra = '';           // e.g. reopen an editor

    if ($action === 'add' || $action === 'upload_csv') {
        // Typed lines: "Name; email" or "Name; email; email" (emails optional).
        // Commas belong to the name ("Sarah, Tom and Lily"); see split_guest_line().
        // Uploads: a CSV file read by parse_guest_csv(), never saved anywhere.
        $rows = [];
        $problem = '';
        if ($action === 'add') {
            foreach (preg_split('/\r?\n/', input($_POST, 'guests')) as $line) {
                $row = split_guest_line($line);
                if ($row[0] !== '') {
                    $rows[] = $row;
                }
            }
        } else {
            $file = $_FILES['csv'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
                $problem = t('admin.add.choose_file');
            } elseif ($file['size'] > 1024 * 1024) {
                $problem = t('admin.add.too_big');
            } else {
                $rows = parse_guest_csv((string)file_get_contents((string)$file['tmp_name']));
                if (count($rows) > 2000) {
                    [$rows, $problem] = [[], t('admin.add.too_many_rows')];
                } elseif (!$rows) {
                    $problem = t('admin.add.no_names');
                }
            }
        }
        if ($problem) {
            $message = $problem;
        } else {
            [$added, $bad, $skipped] = add_guests($rows, $action === 'upload_csv');
            $message = t('admin.add.added', ['count' => $added]);
            if ($skipped) {
                $message .= ' ' . t('admin.add.skipped', ['count' => count($skipped), 'names' => join_names($skipped, 10)]);
            }
            if ($bad) {
                $message .= ' ' . t('admin.add.bad_emails', ['names' => join_names($bad)]);
            }
        }
    } elseif ($action === 'greeting_style') {
        $style = input($_POST, 'style') === 'first' ? 'first' : 'full';
        save_setting('greeting_style', $style);
        $message = t($style === 'first' ? 'admin.greet.saved_first' : 'admin.greet.saved_full');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM guests WHERE id = ?')->execute([(int)input($_POST, 'id')]);
        $message = t('admin.guest.deleted');
    } elseif ($action === 'delete_selected' || $action === 'send_selected') {
        // The ticked rows in the guest table (ids[] from the checkboxes).
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        // PHP drops form fields past max_input_vars (usually 1,000) without saying,
        // so a request that reaches the limit may be missing some: refuse it.
        $tooMany = count($ids) >= ((int)ini_get('max_input_vars') ?: 1000) - 10;
        $ids = $tooMany ? [] : array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        // In batches: older SQLite allows at most 999 values in one query.
        $batches = array_map(fn($batch) => [$batch, implode(',', array_fill(0, count($batch), '?'))], array_chunk($ids, 500));
        $settings = mail_settings();
        if ($tooMany) {
            $message = t('admin.bulk.too_many');
        } elseif (!$ids) {
            $message = t($action === 'send_selected' ? 'admin.bulk.tick_send' : 'admin.bulk.tick_delete');
        } elseif ($action === 'send_selected') {
            if (!valid_email($settings['from'])) {
                $message = t('admin.bulk.bad_from');
            } else {
                set_time_limit(300);
                $chosen = [];
                foreach ($batches as [$batch, $marks]) {
                    $q = db()->prepare("SELECT * FROM guests WHERE id IN ($marks)");
                    $q->execute($batch);
                    $chosen = array_merge($chosen, $q->fetchAll());
                }
                usort($chosen, fn($a, $b) => strcasecmp($a['name'], $b['name']));
                $sent = 0;
                $resent = 0;
                $failed = [];
                $noEmail = [];
                foreach ($chosen as $g) {
                    if (!sendable_emails($g['email'])) {
                        $noEmail[] = $g['name'];
                    } elseif (send_invite($g, $settings)) {
                        $sent++;
                        $resent += $g['invited_at'] ? 1 : 0;
                    } else {
                        $failed[] = $g['name'];
                    }
                }
                $message = t('admin.bulk.sent', ['count' => $sent]) . ($resent ? ' ' . t('admin.bulk.sent_again', ['count' => $resent]) : '');
                if ($noEmail) {
                    $message .= ' ' . t('admin.bulk.no_email', ['names' => join_names($noEmail)]);
                }
                if ($failed) {
                    $message .= ' ' . t('admin.send_failed', ['names' => join_names($failed)]);
                }
            }
        } else {
            $deleted = 0;
            foreach ($batches as [$batch, $marks]) {
                $q = db()->prepare("DELETE FROM guests WHERE id IN ($marks)");
                $q->execute($batch);
                $deleted += $q->rowCount();
            }
            $message = t('admin.bulk.deleted', ['count' => $deleted]);
        }
    } elseif (in_array($action, ['save_email', 'preview', 'send_test', 'send_all'], true)) {
        $back = 'email';
        // Which language's version is being edited; From and Reply-to are
        // shared, so they're only saved from the main language's form.
        $mailLang = offered_language(input($_POST, 'lang')) ?? site_language();
        if ($mailLang !== site_language()) {
            $backExtra = '&lang=' . urlencode($mailLang);
        } else {
            foreach (['from_name', 'from', 'reply_to'] as $field) {
                save_setting('mail_' . $field, trim(input($_POST, $field)));
            }
        }
        // The email form's buttons all save the wording first.
        save_wording('mail_subject', $mailLang, str_replace(["\r", "\n"], ' ', input($_POST, 'subject')));
        save_wording('mail_body', $mailLang, input($_POST, 'body'));
        // One line, at most 60 characters; empty goes back to the default.
        preg_match('/^.{0,60}/us', trim((string)preg_replace('/\s+/', ' ', input($_POST, 'tagline'))), $m);
        save_wording('tagline', $mailLang, trim($m[0] ?? ''));
        if ($action === 'preview') {
            header('Location: /admin.php?preview=1&lang=' . urlencode($mailLang));   // opens in its own tab
            exit;
        }
        $settings = mail_settings();
        $message = $mailLang === site_language() ? t('admin.email.saved') : t('admin.saved_version', ['language' => LANGUAGE_NAMES[$mailLang]]);

        if (!valid_email($settings['from'])) {
            $message = t('admin.email.saved_bad_from');
        } elseif ($action === 'send_test') {
            if (!valid_email($settings['reply_to'])) {
                $message = t('admin.email.need_reply_to');
            } else {
                $sample = ['name' => 'Test Guest', 'email' => $settings['reply_to'], 'code' => 'TEST1234', 'language' => $mailLang];
                $message = send_invite($sample, $settings)
                    ? t('admin.test_sent', ['email' => $settings['reply_to']])
                    : t('admin.email.test_failed');
            }
        } elseif ($action === 'send_all') {
            set_time_limit(300);
            $pending = array_filter(
                db()->query('SELECT * FROM guests WHERE invited_at IS NULL')->fetchAll(),
                fn($g) => sendable_emails($g['email'])
            );
            $sent = 0;
            $failed = [];
            foreach ($pending as $g) {
                if (send_invite($g, $settings)) {
                    $sent++;
                } else {
                    $failed[] = $g['name'];
                }
            }
            $message = $pending ? t('admin.bulk.sent', ['count' => $sent]) : t('admin.email.all_sent');
            if ($failed) {
                $message .= ' ' . t('admin.send_failed', ['names' => join_names($failed)]);
            }
        }
    } elseif ($action === 'update') {
        $id = (int)input($_POST, 'id');
        $name = trim(input($_POST, 'name'));
        $email = clean_emails(input($_POST, 'email'));
        $greetingStyle = in_array(input($_POST, 'greeting_style'), ['full', 'first'], true) ? input($_POST, 'greeting_style') : null;
        preg_match('/^.{0,120}/us', trim((string)preg_replace('/\s+/', ' ', input($_POST, 'greeting'))), $m);   // one line, 120 characters
        $greeting = trim($m[0] ?? '');
        $language = offered_language(input($_POST, 'language')) ?? '';   // '' = not known
        $q = db()->prepare('SELECT * FROM guests WHERE id = ?');
        $q->execute([$id]);
        $g = $q->fetch();
        if (!$g) {
            $message = t('admin.guest.gone');
        } elseif ($name === '') {
            $message = t('admin.guest.no_name');
        } else {
            // A new address hasn't been sent anything yet.
            $emailChanged = $email !== clean_emails((string)$g['email']);
            db()->prepare('UPDATE guests SET name = ?, email = ?, invited_at = ?, greeting = ?, greeting_style = ? WHERE id = ?')
                ->execute([$name, $email !== '' ? $email : null, $emailChanged ? null : $g['invited_at'],
                    $greeting !== '' ? $greeting : null, $greetingStyle, $id]);
            // A language picked here counts as set by admin; leaving it alone keeps how it was set.
            if (isset($_POST['language']) && ($language !== (string)($g['language'] ?? ''))) {
                $language = $language !== '' ? $language : null;
                db()->prepare('UPDATE guests SET language = ?, language_set_by = ? WHERE id = ?')
                    ->execute([$language, $language ? 'admin' : null, $id]);
            }
            $message = t('admin.saved_changes', ['name' => $name]);
            if (has_bad_email($email)) {
                $message .= ' ' . t(sendable_emails($email) ? 'admin.guest.one_bad' : 'admin.guest.bad');
            } elseif ($emailChanged && $email !== '' && $g['invited_at']) {
                $message .= ' ' . t(count(sendable_emails($email)) > 1 ? 'admin.guest.changed_many' : 'admin.guest.changed_one');
            }
        }
    } elseif ($action === 'send_one') {
        $q = db()->prepare('SELECT * FROM guests WHERE id = ?');
        $q->execute([(int)input($_POST, 'id')]);
        $g = $q->fetch();
        if ($g) {
            $count = count(sendable_emails($g['email']));
            $message = send_invite($g, mail_settings())
                ? ($count > 1 ? t('admin.guest.sent_many', ['name' => $g['name'], 'count' => $count]) : t('admin.guest.sent', ['name' => $g['name']]))
                : t($count > 1 ? 'admin.guest.send_failed_some' : 'admin.guest.send_failed', ['name' => $g['name']]);
        }

    // --- Updates (bulk emails) ---
    } elseif (in_array($action, ['notify_preview', 'notify_test', 'notify_send', 'template_save', 'template_save_new', 'template_delete'], true)) {
        $back = 'updates';
        $draft = [
            'audience' => isset(audiences()[input($_POST, 'audience')]) ? input($_POST, 'audience') : 'all',
            'subject' => trim(str_replace(["\r", "\n"], ' ', input($_POST, 'subject'))),
            'body' => trim(str_replace("\r\n", "\n", input($_POST, 'body'))),
            'template_id' => (int)input($_POST, 'template_id'),
            'translations' => clean_translations($_POST['tr'] ?? []),
        ];
        $_SESSION['update_draft'] = $draft;   // keep the form filled in after the redirect
        $settings = mail_settings();
        $translationsJson = $draft['translations'] ? json_encode($draft['translations'], JSON_UNESCAPED_UNICODE) : null;

        if ($action === 'notify_preview') {
            $lang = offered_language(input($_GET, 'preview_lang')) ?? site_language();
            header('Location: /admin.php?preview_update=1&lang=' . urlencode($lang));   // opens in its own tab
            exit;
        } elseif ($action === 'template_delete') {
            $t = find_template((int)input($_POST, 'delete_id'));
            if ($t) {
                notify_db()->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$t['id']]);
                $message = t('admin.tpl.deleted', ['name' => $t['name']]);
            }
        } elseif ($draft['subject'] === '' || $draft['body'] === '') {
            $message = t('admin.upd.need_text');
        } elseif ($action === 'template_save' || $action === 'template_save_new') {
            $name = trim(input($_POST, 'template_name'));
            $existing = $action === 'template_save' ? find_template($draft['template_id']) : null;
            if ($existing) {
                notify_db()->prepare('UPDATE email_templates SET subject = ?, body = ?, translations = ? WHERE id = ?')
                    ->execute([$draft['subject'], $draft['body'], $translationsJson, $existing['id']]);
                $message = t('admin.tpl.updated', ['name' => $existing['name']]);
            } else {
                $name = $name !== '' ? $name : $draft['subject'];
                notify_db()->prepare('INSERT INTO email_templates (name, subject, body, translations) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $draft['subject'], $draft['body'], $translationsJson]);
                $_SESSION['update_draft']['template_id'] = (int)notify_db()->lastInsertId();
                $message = t('admin.tpl.saved_new', ['name' => $name]);
            }
        } elseif (!valid_email($settings['from'])) {
            $message = t('admin.upd.bad_from');
        } elseif ($action === 'notify_test') {
            if (!valid_email($settings['reply_to'])) {
                $message = t('admin.upd.need_reply_to');
            } else {
                $sample = ['name' => 'Test Guest', 'email' => $settings['reply_to'], 'code' => 'TEST1234'];
                $message = send_update($sample, $draft['subject'], $draft['body'])
                    ? t('admin.test_sent', ['email' => $settings['reply_to']])
                    : t('admin.upd.test_failed');
            }
        } else {   // notify_send
            set_time_limit(300);
            $recipients = audience_guests($draft['audience']);
            $sent = 0;
            $failed = [];
            foreach ($recipients as $g) {
                if (send_update($g, $draft['subject'], $draft['body'], $draft['translations'])) {
                    $sent++;
                } else {
                    $failed[] = $g['name'];
                }
            }
            log_notification($draft['subject'], audiences()[$draft['audience']], $sent, count($failed), $me['name']);
            $message = $recipients ? t('admin.upd.sent', ['count' => $sent]) : t('admin.upd.nobody');
            if ($failed) {
                $message .= ' ' . t('admin.send_failed', ['names' => join_names($failed)]);
            }
            if ($sent) {
                unset($_SESSION['update_draft']);
            }
        }

    // --- Feedback from guests ---
    } elseif (in_array($action, ['feedback_read', 'feedback_unread', 'feedback_delete', 'feedback_read_all'], true)) {
        $back = 'feedback';
        $id = (int)input($_POST, 'id');
        if ($action === 'feedback_read_all') {
            db()->exec('UPDATE feedback SET read_at = CURRENT_TIMESTAMP WHERE read_at IS NULL');
        } elseif ($action === 'feedback_delete') {
            db()->prepare('DELETE FROM feedback WHERE id = ?')->execute([$id]);
            $message = t('admin.fb.deleted');
        } else {
            db()->prepare('UPDATE feedback SET read_at = ' . ($action === 'feedback_read' ? 'CURRENT_TIMESTAMP' : 'NULL') . ' WHERE id = ?')
                ->execute([$id]);
        }

    // --- Content ---
    } elseif (in_array($action, ['section_save', 'section_preview'], true)) {
        $back = 'content';
        $id = (int)input($_POST, 'id');
        $title = trim(input($_POST, 'title'));
        $body = trim(str_replace("\r\n", "\n", input($_POST, 'body')));
        $visible = input($_POST, 'visible') === '1' ? 1 : 0;
        // Another language's version of an existing section.
        $lang = offered_language(input($_POST, 'lang')) ?? '';
        $translating = $id && find_section($id) && $lang !== '' && $lang !== site_language();
        if ($translating) {
            if ($action === 'section_preview') {
                $_SESSION['section_draft'] = compact('id', 'title', 'body', 'visible', 'lang');
                $backExtra = '&section=' . $id . '&lang=' . urlencode($lang) . '&show_preview=1#editor';
            } else {
                save_section_translation($id, $lang, $title, $body, $me['name']);
                unset($_SESSION['section_draft']);
                $message = t($title === '' && $body === '' ? 'admin.content.removed_version' : 'admin.saved_version',
                    ['language' => LANGUAGE_NAMES[$lang]]);
                $backExtra = '&section=' . $id . '&lang=' . urlencode($lang) . '#editor';
            }
        } elseif ($action === 'section_preview' || $title === '') {
            // Keep the unsaved draft and reopen the editor.
            $_SESSION['section_draft'] = compact('id', 'title', 'body', 'visible');
            $backExtra = '&section=' . ($id ?: 'new') . ($action === 'section_preview' ? '&show_preview=1' : '') . '#editor';
            $message = $title === '' && $action === 'section_save' ? t('admin.content.need_title') : '';
        } elseif ($id && find_section($id)) {
            content_db()->prepare('UPDATE sections SET title = ?, body = ?, visible = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?')
                ->execute([$title, $body, $visible, $me['name'], $id]);
            $message = t('admin.content.saved', ['title' => $title]);
        } else {
            $next = (int)content_db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM sections')->fetchColumn();
            content_db()->prepare('INSERT INTO sections (title, body, visible, position, updated_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$title, $body, $visible, $next, $me['name']]);
            // Started from a template and not reworded: add its draft translations.
            translate_starter_sections(content_db(), (int)content_db()->lastInsertId());
            $message = t($visible ? 'admin.content.added_shown' : 'admin.content.added_hidden', ['title' => $title]);
        }
        if ($action === 'section_save' && $title !== '') {
            unset($_SESSION['section_draft']);
        }
    } elseif ($action === 'section_move') {
        $back = 'content';
        $sections = all_sections();
        $ids = array_map(fn($s) => (int)$s['id'], $sections);
        $i = array_search((int)input($_POST, 'id'), $ids, true);
        $j = input($_POST, 'dir') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $update = content_db()->prepare('UPDATE sections SET position = ? WHERE id = ?');
            foreach ($ids as $pos => $sid) {
                $update->execute([$pos + 1, $sid]);
            }
        }
    } elseif ($action === 'section_toggle') {
        $back = 'content';
        $s = find_section((int)input($_POST, 'id'));
        if ($s) {
            content_db()->prepare('UPDATE sections SET visible = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?')
                ->execute([$s['visible'] ? 0 : 1, $me['name'], $s['id']]);
            $message = t($s['visible'] ? 'admin.content.now_hidden' : 'admin.content.now_shown', ['title' => $s['title']]);
        }
    } elseif ($action === 'section_delete') {
        $back = 'content';
        $s = find_section((int)input($_POST, 'id'));
        if ($s) {
            content_db()->prepare('DELETE FROM sections WHERE id = ?')->execute([$s['id']]);
            content_db()->prepare('DELETE FROM section_translations WHERE section_id = ?')->execute([$s['id']]);
            $message = t('admin.content.deleted', ['title' => $s['title']]);
        }

    // --- Your email address and feedback emails ---
    } elseif ($action === 'account_contact') {
        $back = 'account';
        $email = trim(input($_POST, 'email'));
        $notify = input($_POST, 'notify_feedback') === '1';
        $current = admin_contact((int)$me['id']);
        $changed = strtolower($email) !== strtolower($current['email']);
        if ($email !== '' && !valid_email($email)) {
            $message = t('admin.account.bad_email');
        } elseif ($notify && $email === '') {
            $message = t('admin.account.need_email');
        } elseif ($changed && $me['id'] && !password_matches((int)$me['id'], input($_POST, 'current_password'))) {
            // Other admins' addresses are used for password resets, so changing
            // one needs their password, not just a signed-in browser.
            $message = t('admin.account.need_password');
        } else {
            save_admin_contact((int)$me['id'], $email, $notify);
            $message = t($notify ? 'admin.account.saved_on' : 'admin.saved');
        }
    } elseif ($action === 'account_language') {
        $back = 'account';
        save_admin_language((int)$me['id'], input($_POST, 'language'));
        set_language(admin_language_choice((int)$me['id']) ?? visitor_language());
        $message = t('admin.saved');

    // --- Your passkeys ---
    } elseif ($action === 'passkey_register') {
        $back = 'account';
        $challenge = take_passkey_challenge('register');
        $origin = passkey_origin();
        $clientData = b64url_decode(input($_POST, 'client_data'));
        $attestation = b64url_decode(input($_POST, 'attestation'));
        $result = t('admin.passkey.try_again');
        if ($challenge && $origin && (int)$challenge['user'] === (int)$me['id'] && $clientData !== null && $attestation !== null) {
            $result = verify_passkey_registration(['challenge' => $challenge['challenge'], 'origin' => $origin[1], 'rp_id' => $origin[0]],
                $clientData, $attestation);
        }
        if (is_array($result) && find_passkey($result['credential_id'])) {
            $message = t('admin.passkey.duplicate');
        } elseif (is_array($result)) {
            preg_match('/^.{0,60}/us', trim((string)preg_replace('/\s+/', ' ', input($_POST, 'passkey_name'))), $m);
            $first = !user_passkeys((int)$me['id']);
            passkeys_db()->prepare('INSERT INTO passkeys (user_id, credential_id, public_key, sign_count, name) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int)$me['id'], $result['credential_id'], $result['public_key'], $result['count'], trim($m[0] ?? '') ?: t('admin.passkey.default_name')]);
            $message = t($first ? 'admin.passkey.added_first' : 'admin.passkey.added');
        } else {
            $message = $result;
        }
    } elseif ($action === 'passkey_remove') {
        $back = 'account';
        passkeys_db()->prepare('DELETE FROM passkeys WHERE id = ? AND user_id = ?')->execute([(int)input($_POST, 'id'), (int)$me['id']]);
        $message = t(user_passkeys((int)$me['id']) ? 'admin.passkey.removed' : 'admin.passkey.removed_last');

    // --- People (owner only) ---
    } elseif ($action === 'user_feedback') {
        // Owner only: switch someone's feedback emails on or off from the People tab.
        $back = 'people';
        if (!is_owner()) {
            http_response_code(403);
            exit(t('admin.people.owner_only'));
        }
        $id = (int)input($_POST, 'id');
        $on = input($_POST, 'notify_feedback') === '1';
        $u = $id === 0 ? ['name' => ''] : find_user($id);
        $contact = $u ? admin_contact($id) : null;
        if (!$u) {
            $message = t('admin.people.gone');
        } elseif ($on && !valid_email($contact['email'])) {
            $message = $id === 0 ? t('admin.people.need_email_you') : t('admin.people.need_email', ['name' => $u['name']]);
        } else {
            save_admin_contact($id, $contact['email'], $on);
            $message = $id === 0 ? t($on ? 'admin.people.on_you' : 'admin.people.off_you')
                : t($on ? 'admin.people.on' : 'admin.people.off', ['name' => $u['name']]);
        }
    } elseif (in_array($action, ['user_add', 'user_reset', 'user_remove', 'user_update'], true)) {
        $back = 'people';
        if (!is_owner()) {
            http_response_code(403);
            exit(t('admin.people.owner_only'));
        }
        $emailIt = input($_POST, 'send_email') === '1';
        if ($action === 'user_add') {
            $name = trim(input($_POST, 'name'));
            $username = normalise_username(input($_POST, 'username'));
            $email = trim(input($_POST, 'email'));
            if ($name === '' || !valid_username($username)) {
                $message = t('admin.people.bad_new');
            } elseif ($email !== '' && !valid_email($email)) {
                $message = t('admin.people.bad_email');
            } else {
                try {
                    users_db()->prepare('INSERT INTO users (name, username, email) VALUES (?, ?, ?)')
                        ->execute([$name, $username, $email !== '' ? $email : null]);
                    $u = find_user((int)users_db()->lastInsertId());
                    $url = new_setup_link((int)$u['id']);
                    $emailed = $emailIt && send_setup_email($u, $url, false);
                    $_SESSION['setup_link'] = ['name' => $name, 'url' => $url, 'emailed' => $emailed ? $email : ''];
                    $message = t('admin.people.added', ['name' => $name]) . ' ' . ($emailed ? t('admin.people.emailed', ['email' => $email])
                        : t($emailIt && $email !== '' ? 'admin.people.email_failed' : 'admin.people.send_link'));
                } catch (PDOException $e) {
                    $message = t('admin.people.taken', ['username' => $username]);
                }
            }
        } else {
            $u = find_user((int)input($_POST, 'id'));
            if ($u && $action === 'user_update') {
                $name = trim(input($_POST, 'name'));
                $email = trim(input($_POST, 'email'));
                if ($name === '' || ($email !== '' && !valid_email($email))) {
                    $message = t('admin.people.bad_update');
                } else {
                    users_db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                        ->execute([$name, $email !== '' ? $email : null, $u['id']]);
                    $message = t('admin.saved_changes', ['name' => $name]);
                }
            } elseif ($u && $action === 'user_reset') {
                // Their old password stops working until they use the new link, and
                // their passkeys are cleared (this is how someone recovers a lost one).
                users_db()->prepare('UPDATE users SET password_hash = NULL WHERE id = ?')->execute([$u['id']]);
                passkeys_db()->prepare('DELETE FROM passkeys WHERE user_id = ?')->execute([$u['id']]);
                $url = new_setup_link((int)$u['id']);
                $emailed = $emailIt && send_setup_email($u, $url, true);
                $_SESSION['setup_link'] = ['name' => $u['name'], 'url' => $url, 'emailed' => $emailed ? $u['email'] : ''];
                $message = $emailed
                    ? t('admin.people.reset_emailed', ['name' => $u['name'], 'email' => $u['email']])
                    : t('admin.people.reset_link', ['name' => $u['name']]) . ($emailIt ? ' ' . t('admin.people.reset_email_failed') : '');
            } elseif ($u) {
                users_db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
                passkeys_db()->prepare('DELETE FROM passkeys WHERE user_id = ?')->execute([$u['id']]);
                $message = t('admin.people.removed', ['name' => $u['name']]);
            }
        }

    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: /admin.php');
        exit;
    }

    if ($message !== '') {
        $_SESSION['flash'] = $message;
    }
    header('Location: /admin.php?tab=' . $back . $backExtra);
    exit;
}

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$editId = (int)input($_GET, 'edit');
$mail = mail_settings();

$guests = db()->query('SELECT * FROM guests ORDER BY name COLLATE NOCASE')->fetchAll();

// --- Email preview (opened in a new tab) ----------------------------

// The dark bar across the top of a preview, in the admin's language.
function preview_bar(string $subject): string
{
    return '<div lang="' . h(current_language()) . '" dir="' . (is_rtl() ? 'rtl' : 'ltr') . '" style="position:sticky;top:0;padding:10px 16px;'
        . 'background:#18181b;color:#fff;font:14px ' . email_font() . '">'
        . t_html('admin.preview_bar', ['subject' => '<strong><bdi>' . h($subject) . '</bdi></strong>']) . '</div>';
}

if (isset($_GET['preview'])) {
    $previewLang = offered_language(input($_GET, 'lang')) ?? site_language();
    $settings = mail_settings($previewLang);
    $sample = ['name' => 'Sarah & Tom Jones', 'code' => 'K7MPX4QR'];
    [$subject, , $html] = in_language($previewLang, fn() => render_invite($sample, $settings, email_photo_url()));
    // The email uses inline styles, which the site's normal policy blocks.
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    $bar = preview_bar($subject);
    echo preg_replace('/<body([^>]*)>/', '<body$1>' . $bar, $html, 1);
    exit;
}

// --- Update preview (opened in a new tab) ---------------------------

if (isset($_GET['preview_update'])) {
    $draft = $_SESSION['update_draft'] ?? ['subject' => '', 'body' => ''];
    $lang = offered_language(input($_GET, 'lang')) ?? site_language();
    [$subject, $body] = update_version($lang, $draft['subject'], $draft['body'], $draft['translations'] ?? []);
    $sample = ['name' => 'Sarah & Tom Jones', 'code' => 'K7MPX4QR'];
    [$subject, , $html] = in_language($lang, fn() => render_update($sample, $subject, $body, email_photo_url()));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    $bar = preview_bar($subject);
    echo preg_replace('/<body([^>]*)>/', '<body$1>' . $bar, $html, 1);
    exit;
}

// --- Caterer list (CSV) ---------------------------------------------

if (input($_GET, 'export') === 'caterer') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dietary-requirements.csv"');
    $out = fopen('php://output', 'w');
    $h = headcount($guests);
    fputcsv($out, [t('admin.csv.person'), t('admin.csv.invitation'), t('admin.csv.medical'), t('admin.csv.requirements')], ',', '"', '');
    foreach (catering_rows($guests) as $r) {
        fputcsv($out, [csv_cell($r['person']), csv_cell($r['invitation']),
            $r['medical'] ? csv_cell(t('admin.csv.yes', ['list' => join_names(array_map('diet_label', $r['medical']))])) : '',
            csv_cell(join_names(array_map('diet_label', $r['needs'])))], ',', '"', '');
    }
    fputcsv($out, [], ',', '"', '');
    fputcsv($out, [t('admin.csv.total'), $h['confirmed'] . ($h['confirmed_exact'] ? '' : '+')], ',', '"', '');
    exit;
}

// --- CSV export (for mail merge) ------------------------------------

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="guests.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'email', 'code', 'link', 'reply', 'responded_at', 'emailed_at', 'invited', 'coming', 'number_coming', 'dietary', 'updates', 'greeting'], ',', '"', '');
    foreach ($guests as $g) {
        $status = rsvp_status($g);
        $who = attendees($g);
        fputcsv($out, [csv_cell($g['name']), csv_cell($g['email']), $g['code'], invite_link($g['code']),
            ['yes' => 'yes', 'no' => 'no', 'maybe' => 'not sure yet', 'waiting' => ''][$status], $g['responded_at'], $g['invited_at'],
            implode(' / ', party_members($g['name'])) ?: 'unknown',
            $status === 'yes' ? csv_cell(implode(' / ', $who['names']) ?: 'party of ' . $who['count']) : '',
            $status === 'yes' ? ($who['known'] ? $who['count'] : '') : '',
            $status === 'yes' ? csv_cell(dietary_summary($g)) : '',
            (int)$g['notify'] === 1 ? 'yes' : 'no', csv_cell(greeting_name($g))], ',', '"', '');
    }
    exit;
}

$counts = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'waiting' => 0];
$unsent = 0;
$people = 0;
$peopleUnknown = false;   // a "Family" invitation that hasn't said how many
foreach ($guests as $g) {
    $counts[rsvp_status($g)]++;
    if (rsvp_status($g) === 'yes') {
        $who = attendees($g);
        $people += $who['count'];
        $peopleUnknown = $peopleUnknown || !$who['known'];
    }
    if ($g['invited_at'] === null && sendable_emails($g['email'])) {
        $unsent++;
    }
}

page_header(t('admin.title'), 'admin');
?>
<?php
$myPasskeys = count(user_passkeys((int)$me['id']));
$myUsername = $me['id'] ? (string)(find_user((int)$me['id'])['username'] ?? '') : OWNER_USERNAME;
?>
<header class="top wide">
  <span class="brand"><?= th('admin.title') ?> <bdi class="version" dir="ltr">v<?= h(SITE_VERSION) ?></bdi></span>
  <nav class="top-links">
    <a class="link" href="/"><?= th('admin.view_site') ?></a>
    <?php /* Account menu: a <details> so it works by keyboard and without JavaScript. */ ?>
    <details class="account-menu">
      <summary aria-label="<?= th($myPasskeys ? 'admin.menu_label' : 'admin.menu_label_nudge', ['name' => $me['name']]) ?>">
        <span class="avatar" aria-hidden="true"><?= h(initials($me['name'])) ?></span>
        <?php if (!$myPasskeys): ?><span class="avatar-dot" aria-hidden="true"></span><?php endif; ?>
      </summary>
      <div class="account-panel">
        <div class="account-who">
          <span class="avatar large" aria-hidden="true"><?= h(initials($me['name'])) ?></span>
          <div>
            <strong><?= h($me['name']) ?></strong>
            <span><?= th(is_owner() ? 'admin.role.owner' : 'admin.role.admin') ?> · <bdi><?= h($myUsername) ?></bdi></span>
          </div>
        </div>
        <a class="account-item" href="/admin.php?tab=account" <?= $tab === 'account' ? 'aria-current="page"' : '' ?>>
          <span><?= th('admin.tab.account') ?></span>
          <small class="<?= $myPasskeys ? '' : 'nudge' ?>"><?= $myPasskeys
              ? th('admin.passkey.count', ['count' => $myPasskeys])
              : th('admin.passkey.none_nudge') ?><?= admin_contact((int)$me['id'])['notify'] ? ' · ' . th('admin.feedback_on') : '' ?></small>
        </a>
        <form method="post" class="account-signout">
          <?= csrf_field() ?>
          <button class="account-item" name="action" value="logout"><?= th('admin.sign_out') ?></button>
        </form>
      </div>
    </details>
  </nav>
</header>

<main class="content wide">
  <nav class="tabs" aria-label="<?= th('admin.tabs_label') ?>">
    <?php foreach ($tabs as $key => $label): if ($key === 'account') continue;   // in the account menu instead ?>
      <a href="/admin.php?tab=<?= $key ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($message): ?><p class="notice" role="status"><?= h($message) ?></p><?php endif; ?>

<?php if ($tab === 'guests'): ?>

  <section class="stats">
    <div><strong><?= $counts['yes'] ?></strong><span><?= th('admin.stat.attending') ?></span>
      <small><?= th($peopleUnknown ? 'admin.stat.people_plus' : 'admin.stat.people', ['count' => $people]) ?></small></div>
    <div><strong><?= $counts['no'] ?></strong><span><?= th('admin.stat.not_attending') ?></span></div>
    <div><strong><?= $counts['maybe'] ?></strong><span><?= th('admin.stat.maybe') ?></span></div>
    <div><strong><?= $counts['waiting'] ?></strong><span><?= th('admin.stat.no_reply') ?></span></div>
  </section>

  <?php $diet = dietary_mix($guests); $segments = top_with_other($diet['mix']); ?>
  <section class="card diet-summary">
    <h2><?= th('admin.diet.title') ?></h2>
    <?php if (!$segments): ?>
      <p class="hint"><?= th('admin.diet.none') ?></p>
    <?php else: $totalMentions = array_sum($segments); ?>
      <div class="diet-chart">
        <?= dietary_donut_svg($segments, $diet['people_with'], $diet['people_coming']) ?>
        <table class="diet-legend">
          <thead><tr><th><?= th('admin.diet.requirement') ?></th><th><?= th('admin.diet.mentions') ?></th><th><?= th('admin.diet.share') ?></th></tr></thead>
          <tbody>
          <?php $i = 0; foreach ($segments as $label => $count): ?>
            <tr data-seg="<?= $i ?>">
              <td><span class="swatch swatch-<?= $label === 'Other' ? 'other' : $i ?>"></span><?= h(segment_label($label)) ?></td>
              <td><?= $count ?></td>
              <td><?= round($count / $totalMentions * 100) ?>%</td>
            </tr>
          <?php $i++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= th('admin.diet.hint', ['with' => $diet['people_with'], 'coming' => $diet['people_coming']]) ?>
        <?php if (isset($segments['Other'])): ?><?= th('admin.diet.other_hint', ['other' => t('admin.diet.other')]) ?><?php endif; ?></p>
    <?php endif; ?>
  </section>

  <section class="card collapsible" data-key="add-invitations">
    <?= card_heading(t('admin.add.title'), 'add-invitations') ?>
    <div class="card-body" id="add-invitations-body">
      <form method="post">
        <?= csrf_field() ?>
        <label for="guests"><?= t_html('admin.add.help', ['format' => '<em>' . th('admin.add.format') . '</em>', 'format2' => '<em>' . th('admin.add.format2') . '</em>']) ?></label>
        <textarea id="guests" name="guests" rows="5" placeholder="<?= str_replace("\n", '&#10;', th('admin.add.placeholder')) ?>"></textarea>
        <button type="submit" name="action" value="add"><?= th('admin.add.button') ?></button>
      </form>
      <form method="post" enctype="multipart/form-data" class="csv-upload">
        <?= csrf_field() ?>
        <label for="csv"><?= th('admin.add.csv_label') ?></label>
        <p class="hint" id="csv-hint"><?= t_html('admin.add.csv_hint', ['headings' => '<em lang="en">Name</em>, <em lang="en">Email</em>, <em lang="en">Email 2</em>',
          'language' => '<em lang="en">Language</em>']) ?></p>
        <div class="csv-row">
          <input id="csv" type="file" name="csv" accept=".csv,text/csv" required aria-describedby="csv-hint">
          <button type="submit" name="action" value="upload_csv"><?= th('admin.add.upload') ?></button>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="row-between">
      <h2><?= th('admin.guests.title', ['count' => count($guests)]) ?></h2>
      <a class="link" href="/admin.php?export=1"><?= th('admin.guests.download') ?></a>
    </div>
    <form method="post" class="greeting-style">
      <?= csrf_field() ?>
      <label for="greet-style"><?= th('admin.greet.label') ?></label>
      <select id="greet-style" name="style" aria-describedby="greet-hint">
        <option value="full" <?= site_greeting_style() === 'full' ? 'selected' : '' ?>><?= th('admin.greet.full_example') ?></option>
        <option value="first" <?= site_greeting_style() === 'first' ? 'selected' : '' ?>><?= th('admin.greet.first_example') ?></option>
      </select>
      <button type="submit" class="secondary" name="action" value="greeting_style"><?= th('admin.save') ?></button>
      <p class="hint" id="greet-hint"><?= t_html('admin.greet.hint', ['bold' => '<strong>' . th('admin.greet.bold') . '</strong>']) ?></p>
    </form>
    <?php foreach ($guests as $g): if ($g['id'] != $editId) continue; ?>
      <form method="post" class="edit-guest" id="edit">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
        <p class="edit-title"><?= th('admin.edit.title', ['name' => $g['name']]) ?> <span class="hint"><?= th('admin.edit.code_same', ['code' => $g['code']]) ?></span></p>
        <p class="hint edit-people">
          <?php $members = party_members($g['name']); ?>
          <?= $members ? th('admin.edit.boxes', ['names' => join_names($members)]) : th('admin.edit.one_box') ?>
          <?= t_html('admin.edit.separators', ['amp' => '<bdi><code>&amp;</code></bdi>', 'comma' => '<bdi><code>,</code></bdi>',
              'and' => '<bdi><code>' . th('admin.edit.and_word') . '</code></bdi>', 'plus' => '<bdi dir="ltr"><code>+ guest</code></bdi>']) ?>
        </p>
        <label><?= th('admin.field.name') ?><input type="text" name="name" value="<?= h($g['name']) ?>" required autofocus></label>
        <label><?= th('admin.field.email') ?><input type="text" name="email" value="<?= h($g['email']) ?>" placeholder="<?= th('admin.field.optional') ?>"
          inputmode="email" autocomplete="off" spellcheck="false" dir="ltr" aria-describedby="email-hint"></label>
        <p class="hint edit-email" id="email-hint"><?= th('admin.edit.email_hint') ?></p>
        <label><?= th('admin.edit.greet_by') ?>
          <select name="greeting_style">
            <option value=""><?= th('admin.edit.site_choice', ['choice' => t(site_greeting_style() === 'first' ? 'admin.greet.first_names' : 'admin.greet.full_name')]) ?></option>
            <option value="full" <?= ($g['greeting_style'] ?? '') === 'full' ? 'selected' : '' ?>><?= th('admin.edit.full_name', ['name' => $g['name']]) ?></option>
            <option value="first" <?= ($g['greeting_style'] ?? '') === 'first' ? 'selected' : '' ?>><?= th('admin.edit.first_names', ['name' => first_names($g['name'])]) ?></option>
          </select></label>
        <label><?= th('admin.edit.type_greeting') ?><input type="text" name="greeting" value="<?= h((string)($g['greeting'] ?? '')) ?>" maxlength="120"
          placeholder="<?= h(greeting_name(['greeting' => ''] + $g)) ?>" aria-describedby="greeting-hint"></label>
        <p class="hint edit-email" id="greeting-hint"><?= th('admin.edit.greeting_hint') ?></p>
        <?php if (count(site_languages()) > 1): ?>
        <label><?= th('admin.edit.language') ?>
          <select name="language" aria-describedby="language-hint">
            <option value=""><?= th('admin.edit.language_unknown', ['language' => LANGUAGE_NAMES[site_language()]]) ?></option>
            <?php foreach (site_languages() as $code): ?>
              <option value="<?= h($code) ?>" lang="<?= h($code) ?>" <?= ($g['language'] ?? '') === $code ? 'selected' : '' ?>><?= h(LANGUAGE_NAMES[$code]) ?></option>
            <?php endforeach; ?>
          </select></label>
        <p class="hint edit-email" id="language-hint"><?= th('admin.edit.language_hint') ?></p>
        <?php endif; ?>
        <button type="submit" name="action" value="update"><?= th('admin.save') ?></button>
        <a class="link" href="/admin.php?tab=guests#guest-<?= (int)$g['id'] ?>"><?= th('admin.cancel') ?></a>
      </form>
    <?php endforeach; ?>
    <?php if ($guests): ?>
    <form method="post" id="bulk" class="bulk-bar">
      <?= csrf_field() ?>
      <span id="bulk-count" class="hint" aria-live="polite"><?= th('admin.bulk.hint') ?></span>
      <span class="bulk-actions">
      <button type="submit" class="bulk-send" name="action" value="send_selected"
              data-confirm="<?= th('admin.bulk.confirm_send') ?>">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>
        </svg>
        <span class="bulk-send-label"><?= th('admin.bulk.send') ?></span>
      </button>
      <button type="submit" class="bulk-delete" name="action" value="delete_selected"
              data-confirm="<?= th('admin.bulk.confirm_delete') ?>">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>
        </svg>
        <?= th('admin.bulk.delete') ?>
      </button>
      </span>
    </form>
    <?php endif; ?>
    <div class="table-wrap">
      <table>
        <?php $manyLanguages = count(site_languages()) > 1; ?>
        <thead><tr><th class="select-cell"><input type="checkbox" id="select-all" aria-label="<?= th('admin.guests.select_all') ?>" hidden></th>
          <th><?= th('admin.field.name') ?></th><th><?= th('admin.field.email') ?></th><th><?= th('admin.guests.code') ?></th>
          <th><?= th('admin.guests.emailed') ?></th><th><?= th('admin.guests.reply') ?></th><th><?= th('admin.guests.dietary') ?></th>
          <?php if ($manyLanguages): ?><th><?= th('admin.guests.language') ?></th><?php endif; ?>
          <th title="<?= th('admin.guests.updates_title') ?>"><?= th('admin.guests.updates') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($guests as $g):
            $status = rsvp_status($g);
            $who = attendees($g);
            $invited = count(party_members($g['name'])); ?>
          <tr id="guest-<?= (int)$g['id'] ?>" <?= $g['id'] == $editId ? 'class="editing"' : '' ?>>
            <td class="select-cell"><input type="checkbox" name="ids[]" value="<?= (int)$g['id'] ?>" form="bulk" aria-label="<?= th('admin.guests.select', ['name' => $g['name']]) ?>"
              data-sendable="<?= sendable_emails($g['email']) ? '1' : '0' ?>" data-sent="<?= $g['invited_at'] ? '1' : '0' ?>"></td>
            <td class="name-cell"><?= name_html($g['name']) ?>
              <?php if (greeting_name($g) !== $g['name']): ?><span class="greeted-as"><?= th('admin.guests.greeted_as', ['name' => greeting_name($g)]) ?></span><?php endif; ?></td>
            <td class="email-cell">
              <?php foreach (email_list($g['email']) as $address): ?>
                <span class="email-address" dir="ltr"><?= h($address) ?><?php if (!valid_email($address)): ?> <span class="pill no" dir="auto"><?= th('admin.guests.check_address') ?></span><?php endif; ?></span>
              <?php endforeach; ?>
            </td>
            <td>
              <code><?= h($g['code']) ?></code>
              <button type="button" class="link small" data-copy="<?= h(invite_link($g['code'])) ?>"><?= th('admin.copy_link') ?></button>
            </td>
            <td>
              <?= $g['invited_at'] ? h(admin_date(strtotime($g['invited_at'] . ' UTC'))) : '—' ?>
              <?php if (sendable_emails($g['email'])): ?>
                <form method="post" class="inline"
                      <?= $g['invited_at'] ? 'data-confirm="' . th('admin.guests.confirm_resend', ['name' => $g['name']]) . '"' : '' ?>>
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                  <button class="link small" name="action" value="send_one"><?= th($g['invited_at'] ? 'admin.guests.resend' : 'admin.guests.send') ?></button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <span class="pill <?= $status ?>"><?= $status === 'waiting' ? '—' : th('admin.status.' . $status) ?></span>
              <?php if ($status === 'yes' && $invited > 1 && $who['count'] < $invited): ?>
                <span class="who" title="<?= h(implode(', ', $who['names'])) ?>"><?= th('admin.guests.some_coming', ['count' => $who['count'], 'invited' => $invited, 'names' => join_names($who['names'])]) ?></span>
              <?php elseif ($status === 'yes' && !$invited): ?>
                <span class="who"><?= $who['known'] ? th('admin.guests.n_coming', ['count' => $who['count']]) : th('admin.guests.number_unknown') ?></span>
              <?php endif; ?>
            </td>
            <td class="dietary-cell"><?= $status === 'yes' && dietary_summary($g) !== '' ? h(dietary_summary($g)) : '—' ?></td>
            <?php if ($manyLanguages): ?>
            <td><?php if (valid_language($g['language'] ?? null)): ?><span lang="<?= h($g['language']) ?>"
              title="<?= th(['guest' => 'admin.guests.lang_guest', 'admin' => 'admin.guests.lang_admin'][$g['language_set_by'] ?? ''] ?? 'admin.guests.lang_browser') ?>"><?= h(LANGUAGE_NAMES[$g['language']]) ?></span><?php else: ?>—<?php endif; ?></td>
            <?php endif; ?>
            <td>
              <?php if (!sendable_emails($g['email'])): ?>—
              <?php elseif ((int)$g['notify'] === 1): ?><span class="pill yes" title="<?= th('admin.guests.updates_on_title') ?>"><?= th('admin.on') ?></span>
              <?php else: ?><span class="pill waiting" title="<?= th('admin.guests.updates_off_title') ?>"><?= th('admin.off') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <a class="link small" href="/admin.php?tab=guests&amp;edit=<?= (int)$g['id'] ?>#edit"><?= th('admin.edit') ?></a>
              <form method="post" class="inline" data-confirm="<?= th('admin.guests.confirm_delete', ['name' => $g['name']]) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button class="link small danger" name="action" value="delete"><?= th('admin.delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

<?php elseif ($tab === 'insights'):
    $funnel = rsvp_funnel($guests);
    $hc = headcount($guests);
    $catering = catering_rows($guests);
    $timeline = reply_timeline($guests);
    $visitedNoReply = array_filter($guests, fn($g) => $g['first_visit_at'] && rsvp_status($g) === 'waiting');
    $emailedNotVisited = array_filter($guests, fn($g) => $g['invited_at'] && !$g['first_visit_at']);
    $notSure = array_filter($guests, fn($g) => rsvp_status($g) === 'maybe');
    usort($notSure, fn($a, $b) => strcmp((string)$a['responded_at'], (string)$b['responded_at']));
    $ago = function (?string $when): string {
        if (!$when) return '—';
        $days = (int)floor((time() - strtotime($when . ' UTC')) / 86400);
        return $days <= 0 ? t('admin.ago.today') : ($days === 1 ? t('admin.ago.yesterday') : t('admin.ago.days', ['count' => $days]));
    };
    $first = $funnel['steps']['invitations'] ?: 1;
?>

  <section class="card">
    <h2><?= th('admin.ins.replies') ?></h2>
    <div class="funnel">
      <?php foreach ($funnel['steps'] as $label => $count): ?>
        <div class="funnel-row">
          <span class="funnel-label"><?= th('admin.funnel.' . $label) ?></span>
          <svg class="funnel-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
            <rect class="track" width="100" height="10" rx="2"/>
            <rect class="fill" width="<?= max(0.8, round($count / $first * 100, 1)) ?>" height="10" rx="2"/>
          </svg>
          <span class="funnel-value"><?= $count ?> <small><?= round($count / $first * 100) ?>%</small></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hint"><?= th('admin.ins.split', $funnel['split'] + ['visited' => t('admin.funnel.visited')]) ?></p>
  </section>

  <div class="insight-lists">
    <section class="card">
      <h2><?= th('admin.ins.visited_title') ?> <span class="count"><?= count($visitedNoReply) ?></span></h2>
      <p class="hint"><?= th('admin.ins.visited_hint') ?></p>
      <?php if (!$visitedNoReply): ?><p class="empty"><?= th('admin.ins.nobody') ?></p><?php else: ?>
      <ul class="people-list">
        <?php foreach ($visitedNoReply as $g): ?>
          <li><span><?= h($g['name']) ?></span><span class="hint"><?= th('admin.ins.last_visit', ['when' => $ago($g['last_visit_at'])]) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2><?= th('admin.ins.emailed_title') ?> <span class="count"><?= count($emailedNotVisited) ?></span></h2>
      <p class="hint"><?= th('admin.ins.emailed_hint') ?></p>
      <?php if (!$emailedNotVisited): ?><p class="empty"><?= th('admin.ins.nobody') ?></p><?php else: ?>
      <ul class="people-list">
        <?php foreach ($emailedNotVisited as $g): ?>
          <li><span><?= h($g['name']) ?></span><span class="hint"><?= th('admin.ins.emailed_when', ['when' => $ago($g['invited_at'])]) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2><?= th('admin.status.maybe') ?> <span class="count"><?= count($notSure) ?></span></h2>
      <p class="hint"><?= th('admin.ins.longest') ?></p>
      <?php if (!$notSure): ?><p class="empty"><?= th('admin.ins.nobody') ?></p><?php else: ?>
      <ul class="people-list">
        <?php foreach ($notSure as $g): ?>
          <li><span><?= h($g['name']) ?></span><span class="hint"><?= th('admin.ins.said_when', ['when' => $ago($g['responded_at'])]) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </div>
  <p class="hint insight-tip"><?= t_html('admin.ins.tip', ['updates' => '<a href="/admin.php?tab=updates">' . th('admin.tab.updates') . '</a>'],
      ['no_reply' => t('admin.aud.no_reply'), 'maybe' => t('admin.aud.maybe'),
       'reminder' => in_language(site_language(), fn() => t('tpl.reminder.name')), 'nudge' => in_language(site_language(), fn() => t('tpl.nudge.name'))]) ?></p>

  <section class="card">
    <div class="row-between">
      <h2><?= th('admin.ins.headcount') ?></h2>
      <a class="link" href="/admin.php?export=caterer"><?= th('admin.ins.caterer_csv') ?></a>
    </div>
    <section class="stats headcount">
      <div><strong><?= $hc['confirmed'] ?><?= $hc['confirmed_exact'] ? '' : '+' ?></strong><span><?= th('admin.hc.confirmed') ?></span></div>
      <div><strong dir="ltr">+<?= $hc['maybe'] ?></strong><span><?= th('admin.hc.maybe') ?></span></div>
      <div><strong dir="ltr">+<?= $hc['no_reply'] ?></strong><span><?= th('admin.hc.no_reply') ?></span></div>
      <div><strong dir="ltr"><?= $hc['confirmed'] ?>–<?= $hc['confirmed'] + $hc['maybe'] + $hc['no_reply'] ?></strong><span><?= th('admin.hc.range') ?></span></div>
    </section>
    <p class="hint"><?= th('admin.hc.hint') ?>
      <?php if ($hc['plus_ones_offered']): ?><?= th('admin.hc.plus_ones', ['coming' => $hc['plus_ones_coming'], 'offered' => $hc['plus_ones_offered']]) ?><?php endif; ?>
      <?php if (!$hc['confirmed_exact']): ?><?= th('admin.hc.plus_note') ?><?php endif; ?></p>

    <h3 class="sub"><?= th('admin.ins.by_person') ?></h3>
    <?php if (!$catering): ?>
      <p class="empty"><?= th('admin.none_yet') ?></p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th><?= th('admin.ins.person') ?></th><th><?= th('admin.ins.invitation') ?></th><th><?= th('admin.ins.requirements') ?></th></tr></thead>
        <tbody>
        <?php foreach ($catering as $r): ?>
          <tr>
            <td class="name-cell"><?= h($r['person']) ?></td>
            <td class="name-cell hint"><?= h($r['invitation']) ?></td>
            <td class="dietary-cell">
              <?php foreach ($r['needs'] as $need): ?>
                <span class="need<?= is_medical_need($need) ? ' medical' : '' ?>"><?php if (is_medical_need($need)): ?><span class="sr-only"><?= th('admin.ins.medical') ?> </span>⚠ <?php endif; ?><?= h(diet_label($need)) ?></span>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= th('admin.ins.medical_hint') ?></p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2><?= th('admin.ins.over_time') ?></h2>
    <?php if (count($timeline['days']) < 2): ?>
      <p class="empty"><?= th('admin.ins.chart_soon') ?></p>
    <?php else: ?>
      <?= reply_timeline_svg($timeline, count($guests)) ?>
      <p class="hint"><span class="key-line"></span> <?= th('admin.ins.key_line') ?> &nbsp; <span class="key-send"></span> <?= th('admin.ins.key_send') ?></p>
      <details class="timeline-table">
        <summary><?= th('admin.ins.as_table') ?></summary>
        <div class="table-wrap">
          <table>
            <thead><tr><th><?= th('admin.ins.day') ?></th><th><?= th('admin.ins.new') ?></th><th><?= th('admin.ins.total') ?></th><th><?= th('admin.ins.sent') ?></th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($timeline['days'], true) as $day => $d): if (!$d['new'] && !isset($timeline['sends'][$day])) continue; ?>
              <tr>
                <td><?= h(admin_date(strtotime($day), 'weekday')) ?></td>
                <td><?= $d['new'] ?: '—' ?></td>
                <td><?= $d['total'] ?></td>
                <td class="dietary-cell"><?= isset($timeline['sends'][$day]) ? h(sends_text($timeline['sends'][$day])) : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'email'): ?>

  <?php
  $mailLang = offered_language(input($_GET, 'lang')) ?? site_language();
  $isMainLang = $mailLang === site_language();
  $here = mail_settings($mailLang);                       // what guests reading this language get now
  $ownTagline = $isMainLang ? own_wording('tagline', $mailLang) : trim(setting('tagline.' . $mailLang));
  $fieldDir = 'lang="' . h($mailLang) . '" dir="' . (is_rtl($mailLang) ? 'rtl' : 'ltr') . '"';
  // Where this language's wording comes from, for the hint.
  $source = function (string $key) use ($mailLang, $isMainLang): string {
      if (!$isMainLang && trim(setting($key . '.' . $mailLang)) !== '') {
          return t('admin.email.src_translation', ['language' => LANGUAGE_NAMES[$mailLang]]);
      }
      if (own_wording($key, site_language()) !== '') {
          return $isMainLang ? t('admin.email.src_own') : t('admin.email.src_main', ['language' => LANGUAGE_NAMES[site_language()]]);
      }
      return t('admin.email.src_builtin', ['language' => LANGUAGE_NAMES[$mailLang]]);
  };
  ?>
  <section class="card">
    <h2><?= th('admin.tab.email') ?></h2>
    <?php if (count(site_languages()) > 1): ?>
      <?= lang_picker(site_languages(), $mailLang,
          array_values(array_filter(site_languages(), fn($code) => trim(setting('mail_subject.' . $code)) !== ''
              || trim(setting('mail_body.' . $code)) !== '' || trim(setting('tagline.' . $code)) !== '')),
          fn($code) => '/admin.php?tab=email' . ($code === site_language() ? '' : '&lang=' . $code)) ?>
    <?php endif; ?>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <input type="hidden" name="lang" value="<?= h($mailLang) ?>">
      <?php if ($isMainLang): ?>
      <div class="field-row">
        <label><?= th('admin.email.from_name') ?><input type="text" name="from_name" value="<?= h($mail['from_name']) ?>"></label>
        <label><?= th('admin.email.from') ?><input type="email" name="from" value="<?= h($mail['from']) ?>" dir="ltr"></label>
        <label><?= th('admin.email.reply_to') ?><input type="email" name="reply_to" value="<?= h($mail['reply_to']) ?>" placeholder="you@example.com" dir="ltr"></label>
      </div>
      <p class="hint"><?= th('admin.email.from_hint') ?></p>
      <?php else: ?>
      <p class="hint"><?= th('admin.email.translation_hint', ['language' => LANGUAGE_NAMES[$mailLang], 'main' => LANGUAGE_NAMES[site_language()]]) ?></p>
      <?php endif; ?>
      <label><?= th('admin.email.tagline') ?><input type="text" name="tagline" value="<?= h($ownTagline) ?>" maxlength="60" <?= $fieldDir ?>
        placeholder="<?= h(in_language($mailLang, 'tagline')) ?>" aria-describedby="tagline-hint"></label>
      <p class="hint" id="tagline-hint"><?= th('admin.email.tagline_hint', ['source' => $source('tagline')]) ?></p>
      <label><?= th('admin.field.subject') ?><input type="text" name="subject" <?= $fieldDir ?>
        value="<?= h($isMainLang ? $here['subject'] : trim(setting('mail_subject.' . $mailLang))) ?>"
        placeholder="<?= h($here['subject']) ?>"></label>
      <label><?= th('admin.field.message') ?>
        <textarea name="body" rows="12" <?= $fieldDir ?> placeholder="<?= h($isMainLang ? '' : $here['body']) ?>"><?= h($isMainLang ? $here['body'] : trim(setting('mail_body.' . $mailLang))) ?></textarea>
      </label>
      <p class="hint"><?= placeholders_hint() ?> <?= th('admin.email.message_source', ['source' => $source('mail_body')]) ?></p>
      <div class="actions">
        <button type="submit" name="action" value="save_email" class="secondary"><?= th('admin.save') ?></button>
        <button type="submit" name="action" value="preview" class="secondary" formtarget="_blank"><?= th('admin.preview') ?></button>
        <button type="submit" name="action" value="send_test" class="secondary"><?= th('admin.send_test') ?></button>
        <?php if ($isMainLang): ?>
        <button type="submit" name="action" value="send_all" <?= $unsent ? '' : 'disabled' ?>
                data-confirm="<?= th('admin.email.confirm_send_all', ['count' => $unsent]) ?>">
          <?= th('admin.email.send_all', ['count' => $unsent]) ?>
        </button>
        <?php endif; ?>
      </div>
    </form>
  </section>

<?php elseif ($tab === 'updates'):
    $templates = all_templates();
    $draft = $_SESSION['update_draft'] ?? null;
    if ($t = find_template((int)input($_GET, 'template'))) {
        $draft = ['audience' => $draft['audience'] ?? 'all', 'subject' => $t['subject'], 'body' => $t['body'],
            'template_id' => (int)$t['id'], 'translations' => template_translations($t)];
        $_SESSION['update_draft'] = $draft;
    }
    $draft = ($draft ?? []) + ['audience' => 'all', 'subject' => '', 'body' => '', 'template_id' => 0, 'translations' => []];
    $otherLanguages = array_values(array_diff(site_languages(), [site_language()]));
    $current = find_template((int)$draft['template_id']);
    $history = recent_notifications();
    $optedOut = (int)db()->query('SELECT COUNT(*) FROM guests WHERE notify = 0')->fetchColumn();
?>

  <section class="card">
    <h2><?= th('admin.upd.title') ?></h2>
    <p class="hint"><?= $optedOut ? th('admin.upd.hint_opted_out', ['count' => $optedOut]) : th('admin.upd.hint') ?></p>
    <p class="add-section"><?= th('admin.upd.start_from') ?>
      <?php foreach ($templates as $t): ?>
        <a class="chip-link<?= $current && (int)$current['id'] === (int)$t['id'] ? ' active' : '' ?>" href="/admin.php?tab=updates&amp;template=<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a>
      <?php endforeach; ?>
    </p>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <input type="hidden" name="template_id" value="<?= (int)$draft['template_id'] ?>">
      <label><?= th('admin.upd.send_to') ?>
        <select name="audience" class="party-size">
          <?php foreach (audiences() as $key => $label): $n = count(audience_guests($key)); ?>
            <option value="<?= h($key) ?>" <?= $draft['audience'] === $key ? 'selected' : '' ?>><?= h($label) ?> (<?= $n ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php $mainDir = 'lang="' . h(site_language()) . '" dir="' . (is_rtl(site_language()) ? 'rtl' : 'ltr') . '"'; ?>
      <label><?= th('admin.field.subject') ?><input type="text" name="subject" value="<?= h($draft['subject']) ?>" <?= $mainDir ?>></label>
      <label><?= th('admin.field.message') ?>
        <textarea name="body" rows="12" <?= $mainDir ?>><?= h($draft['body']) ?></textarea>
      </label>
      <p class="hint"><?= placeholders_hint() ?></p>
      <?php $adminDir = 'lang="' . h(current_language()) . '" dir="' . (is_rtl() ? 'rtl' : 'ltr') . '"'; ?>
      <?php if ($otherLanguages): $written = count(array_filter($draft['translations'], fn($tr) => $tr['body'] !== '')); ?>
        <details class="translations">
          <summary><?= th('admin.upd.translations', ['done' => $written, 'total' => count($otherLanguages)]) ?></summary>
          <p class="hint"><?= th('admin.upd.translations_hint') ?></p>
          <?= lang_picker($otherLanguages, $otherLanguages[0],
              array_keys(array_filter($draft['translations'], fn($tr) => $tr['body'] !== '')), fn($code) => '#tr-' . $code, true) ?>
          <?php foreach ($otherLanguages as $code): $tr = $draft['translations'][$code] ?? ['subject' => '', 'body' => '']; ?>
            <details class="translation" id="tr-<?= h($code) ?>" data-lang="<?= h($code) ?>">
              <summary><bdi lang="<?= h($code) ?>"><?= h(LANGUAGE_NAMES[$code]) ?></bdi><?= $tr['body'] !== '' ? ' <span class="tick" aria-label="' . th('admin.written') . '">✓</span>' : '' ?></summary>
              <div lang="<?= h($code) ?>" dir="<?= in_array($code, RTL_LANGUAGES, true) ? 'rtl' : 'ltr' ?>">
                <label><span <?= $adminDir ?>><?= th('admin.field.subject') ?></span><input type="text" name="tr[<?= h($code) ?>][subject]" value="<?= h($tr['subject']) ?>"></label>
                <label><span <?= $adminDir ?>><?= th('admin.field.message') ?></span><textarea name="tr[<?= h($code) ?>][body]" rows="10"><?= h($tr['body']) ?></textarea></label>
              </div>
              <button type="submit" name="action" value="notify_preview" class="secondary" formtarget="_blank" formaction="/admin.php?preview_lang=<?= h($code) ?>"><?= t_html('admin.upd.preview_in', ['language' => '<bdi lang="' . h($code) . '">' . h(LANGUAGE_NAMES[$code]) . '</bdi>']) ?></button>
            </details>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
      <div class="actions">
        <button type="submit" name="action" value="notify_preview" class="secondary" formtarget="_blank"><?= th('admin.preview') ?></button>
        <button type="submit" name="action" value="notify_test" class="secondary"><?= th('admin.send_test') ?></button>
        <button type="submit" name="action" value="notify_send" data-confirm="<?= th('admin.upd.confirm_send') ?>"><?= th('admin.upd.send') ?></button>
      </div>
      <details class="save-template">
        <summary><?= th('admin.tpl.save_as') ?></summary>
        <div class="actions">
          <?php if ($current): ?>
            <button type="submit" name="action" value="template_save" class="secondary"><?= th('admin.tpl.update', ['name' => $current['name']]) ?></button>
          <?php endif; ?>
          <input type="text" name="template_name" placeholder="<?= th('admin.tpl.new_name') ?>">
          <button type="submit" name="action" value="template_save_new" class="secondary"><?= th('admin.tpl.save_new') ?></button>
        </div>
      </details>
    </form>
  </section>

  <section class="card">
    <h2><?= th('admin.tpl.title') ?></h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th><?= th('admin.field.name') ?></th><th><?= th('admin.field.subject') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <td><?= h($t['name']) ?></td>
            <td class="dietary-cell"><?= h($t['subject']) ?></td>
            <td class="row-actions">
              <a class="link small" href="/admin.php?tab=updates&amp;template=<?= (int)$t['id'] ?>"><?= th('admin.tpl.use') ?></a>
              <form method="post" class="inline" data-confirm="<?= th('admin.tpl.confirm_delete', ['name' => $t['name']]) ?>">
                <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
                <button class="link small danger" name="action" value="template_delete"><?= th('admin.delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <h2><?= th('admin.upd.history') ?></h2>
    <?php if (!$history): ?>
      <p class="hint"><?= th('admin.upd.nothing') ?></p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th><?= th('admin.upd.h_sent') ?></th><th><?= th('admin.field.subject') ?></th><th><?= th('admin.upd.h_to') ?></th><th><?= th('admin.upd.h_delivered') ?></th><th><?= th('admin.upd.h_by') ?></th></tr></thead>
        <tbody>
        <?php foreach ($history as $n): ?>
          <tr>
            <td><?= h(admin_date(strtotime($n['sent_at'] . ' UTC'), 'time')) ?></td>
            <td class="dietary-cell"><?= h($n['subject']) ?></td>
            <td><?= h($n['audience']) ?></td>
            <td><?= (int)$n['sent'] ?><?= $n['failed'] ? ' <span class="pill no">' . th('admin.upd.failed', ['count' => (int)$n['failed']]) . '</span>' : '' ?></td>
            <td><?= h($n['sent_by']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'feedback'):
    $messages = db()->query('SELECT * FROM feedback ORDER BY id DESC')->fetchAll();
?>

  <section class="card">
    <div class="row-between">
      <h2><?= th('admin.fb.title') ?></h2>
      <?php if ($unreadFeedback): ?>
        <form method="post"><?= csrf_field() ?><button class="link" name="action" value="feedback_read_all"><?= th('admin.fb.read_all') ?></button></form>
      <?php endif; ?>
    </div>
    <p class="hint"><?= th('admin.fb.hint', ['button' => t('feedback.button')]) ?></p>
    <?php if (!$messages): ?>
      <p><?= th('admin.fb.none') ?></p>
    <?php endif; ?>
    <?php foreach ($messages as $f): ?>
      <article class="feedback-item<?= $f['read_at'] ? '' : ' unread' ?>">
        <header>
          <strong><?= h($f['guest_name']) ?></strong>
          <span class="hint"><?= h(admin_date(strtotime($f['created_at'] . ' UTC'), 'full')) ?></span>
          <?php if (!$f['read_at']): ?><span class="pill maybe"><?= th('admin.fb.new') ?></span><?php endif; ?>
        </header>
        <p class="feedback-text" dir="auto"><?= nl2br(h($f['message'])) ?></p>
        <div class="row-actions">
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <button class="link small" name="action" value="<?= $f['read_at'] ? 'feedback_unread' : 'feedback_read' ?>"><?= th($f['read_at'] ? 'admin.fb.mark_unread' : 'admin.fb.mark_read') ?></button>
          </form>
          <form method="post" class="inline" data-confirm="<?= th('admin.fb.confirm_delete', ['name' => $f['guest_name']]) ?>"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <button class="link small danger" name="action" value="feedback_delete"><?= th('admin.delete') ?></button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

<?php elseif ($tab === 'content'):
    $sections = all_sections();
    $templates = section_templates();
    // Which section (if any) is open in the editor, and with what text.
    $editing = null;
    $sectionParam = input($_GET, 'section');
    $newTemplate = input($_GET, 'new');
    $draft = $_SESSION['section_draft'] ?? null;
    if ($sectionParam !== '' || isset($templates[$newTemplate])) {
        if ($sectionParam === 'new' || isset($templates[$newTemplate])) {
            $t = $templates[$newTemplate] ?? $templates['blank'];
            $editing = ['id' => 0, 'title' => $t['title'], 'body' => $t['body'], 'visible' => 1];
        } elseif ($s = find_section((int)$sectionParam)) {
            $editing = $s;
        }
        // Editing another language's version: start from the saved translation.
        $editLang = offered_language(input($_GET, 'lang')) ?? '';
        if ($editing && $editing['id'] && $editLang !== '' && $editLang !== site_language()) {
            $main = $editing;
            $tr = section_translation((int)$editing['id'], $editLang);
            $editing = ['id' => $editing['id'], 'title' => $tr['title'] ?? '', 'body' => $tr['body'] ?? '', 'visible' => $editing['visible']];
        } else {
            $editLang = site_language();
        }
        if ($editing && $draft && (int)$draft['id'] === (int)$editing['id'] && !isset($templates[$newTemplate])
            && ($draft['lang'] ?? site_language()) === $editLang) {
            $editing = array_merge($editing, $draft);
        }
    }
    if (!$editing) {
        unset($_SESSION['section_draft']);
    }
?>

  <?php if ($editing): ?>
  <section class="card" id="editor">
    <h2><?= th($editing['id'] ? 'admin.content.edit' : 'admin.content.new') ?></h2>
    <?php if ($editing['id'] && count(site_languages()) > 1): $done = section_languages((int)$editing['id']); ?>
      <?= lang_picker(site_languages(), $editLang, $done,
          fn($code) => '/admin.php?tab=content&section=' . (int)$editing['id'] . ($code === site_language() ? '' : '&lang=' . $code) . '#editor') ?>
      <?php if ($editLang !== site_language()): ?>
        <p class="hint"><?= th('admin.content.translation_hint', ['language' => LANGUAGE_NAMES[$editLang]]) ?></p>
        <details class="main-version"><summary><?= th('admin.content.show_main') ?></summary>
          <div class="card rich" lang="<?= h(site_language()) ?>" dir="<?= is_rtl(site_language()) ? 'rtl' : 'ltr' ?>"><h2><?= h($main['title']) ?></h2><?= render_markdown($main['body']) ?></div>
        </details>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (input($_GET, 'show_preview') === '1'): ?>
      <div class="section-preview">
        <p class="hint"><?= th('admin.content.preview_unsaved') ?></p>
        <div class="card rich" lang="<?= h($editLang) ?>" dir="<?= is_rtl($editLang) ? 'rtl' : 'ltr' ?>">
          <h2><?= h($editing['title'] !== '' ? $editing['title'] : t('admin.content.untitled')) ?></h2>
          <?= render_markdown($editing['body']) ?>
        </div>
      </div>
    <?php endif; ?>
    <form method="post" class="email-form section-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php $isTranslation = $editing['id'] && $editLang !== site_language(); ?>
      <?php if ($isTranslation): ?><input type="hidden" name="lang" value="<?= h($editLang) ?>"><?php endif; ?>
      <label><?= th('admin.field.title') ?><input type="text" name="title" value="<?= h($editing['title']) ?>" lang="<?= h($editLang) ?>" dir="<?= is_rtl($editLang) ? 'rtl' : 'ltr' ?>"
        placeholder="<?= $isTranslation ? h($main['title']) : th('admin.content.title_placeholder') ?>" <?= $isTranslation ? '' : 'required' ?>></label>
      <label for="section-body"><?= th('admin.field.text') ?></label>
      <div class="md-toolbar" role="toolbar" aria-label="<?= th('admin.md.toolbar') ?>">
        <button type="button" data-md="bold" title="<?= th('admin.md.bold') ?>" aria-label="<?= th('admin.md.bold') ?>"><strong>B</strong></button>
        <button type="button" data-md="italic" title="<?= th('admin.md.italic') ?>" aria-label="<?= th('admin.md.italic') ?>"><em>I</em></button>
        <button type="button" data-md="heading" title="<?= th('admin.md.subheading') ?>"><?= th('admin.md.heading') ?></button>
        <button type="button" data-md="list" title="<?= th('admin.md.bullets') ?>">&bull; <?= th('admin.md.list') ?></button>
        <button type="button" data-md="link" title="<?= th('admin.md.link') ?>"><?= th('admin.md.link') ?></button>
        <select data-md-size aria-label="<?= th('admin.md.size') ?>" title="<?= th('admin.md.size') ?>">
          <option value=""><?= th('admin.md.size_choose') ?></option>
          <option value="small"><?= th('admin.md.small') ?></option>
          <option value="large"><?= th('admin.md.large') ?></option>
          <option value="xlarge"><?= th('admin.md.xlarge') ?></option>
        </select>
      </div>
      <textarea id="section-body" name="body" rows="14" data-md-target lang="<?= h($editLang) ?>" dir="<?= is_rtl($editLang) ? 'rtl' : 'ltr' ?>"><?= h($editing['body']) ?></textarea>
      <p class="hint"><?= markdown_hint() ?></p>
      <?php if ($isTranslation): ?>
        <input type="hidden" name="visible" value="<?= $editing['visible'] ? '1' : '0' ?>">
      <?php else: ?>
      <label class="check"><input type="checkbox" name="visible" value="1" <?= $editing['visible'] ? 'checked' : '' ?>> <?= th('admin.content.show_on_page') ?></label>
      <?php endif; ?>
      <div class="actions">
        <button type="submit" name="action" value="section_save"><?= th('admin.save') ?></button>
        <button type="submit" name="action" value="section_preview" class="secondary"><?= th('admin.preview') ?></button>
        <a class="link" href="/admin.php?tab=content"><?= th('admin.cancel') ?></a>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="row-between">
      <h2><?= th('admin.content.title') ?></h2>
      <a class="link" href="/admin.php?tab=content&amp;section=new#editor"><?= th('admin.content.add_blank') ?></a>
    </div>
    <p class="hint"><?= th('admin.content.hint') ?></p>
    <?php if (!$sections): ?>
      <p><?= th('admin.content.none') ?></p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="sections-table">
        <thead><tr><th><?= th('admin.content.h_section') ?></th><th><?= th('admin.content.h_status') ?></th><th><?= th('admin.content.h_changed') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sections as $i => $s): ?>
          <tr <?= $editing && (int)$editing['id'] === (int)$s['id'] ? 'class="editing"' : '' ?>>
            <td class="name-cell"><strong lang="<?= h(site_language()) ?>"><?= h($s['title']) ?></strong>
              <?php if (count(site_languages()) > 1): $langs = section_languages((int)$s['id']); $want = count(site_languages()) - 1; ?>
                <span class="greeted-as"><?= th('admin.content.translations', ['done' => count(array_intersect($langs, site_languages())), 'total' => $want]) ?></span>
              <?php endif; ?></td>
            <td><span class="pill <?= $s['visible'] ? 'yes' : 'waiting' ?>"><?= th($s['visible'] ? 'admin.content.shown' : 'admin.content.hidden') ?></span></td>
            <td><?php $changed = admin_date(strtotime($s['updated_at'] . ' UTC')); ?><?= $s['updated_by'] ? th('admin.content.changed_by', ['date' => $changed, 'name' => $s['updated_by']]) : h($changed) ?></td>
            <td class="row-actions">
              <a class="link small" href="/admin.php?tab=content&amp;section=<?= (int)$s['id'] ?>#editor"><?= th('admin.edit') ?></a>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="link small" name="action" value="section_toggle"><?= th($s['visible'] ? 'admin.content.hide' : 'admin.content.show') ?></button>
              </form>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="link small" name="action" value="section_move" <?= $i === 0 ? 'disabled' : '' ?> aria-label="<?= th('admin.content.move_up', ['title' => $s['title']]) ?>">&uarr;</button>
              </form>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="link small" name="action" value="section_move" <?= $i === count($sections) - 1 ? 'disabled' : '' ?> aria-label="<?= th('admin.content.move_down', ['title' => $s['title']]) ?>">&darr;</button>
              </form>
              <form method="post" class="inline" data-confirm="<?= th('admin.content.confirm_delete', ['title' => $s['title']]) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="link small danger" name="action" value="section_delete"><?= th('admin.delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="add-section"><?= th('admin.content.add') ?>
      <?php foreach ($templates as $key => $t): if ($key === 'blank') continue; ?>
        <a class="chip-link" href="/admin.php?tab=content&amp;new=<?= $key ?>#editor"><?= h($t['label']) ?></a>
      <?php endforeach; ?>
    </p>
  </section>

<?php elseif ($tab === 'people'):
    $users = all_users();
    $link = $_SESSION['setup_link'] ?? null;
    unset($_SESSION['setup_link']);
?>

  <?php if ($link): ?>
  <section class="card setup-link">
    <h2><?= th('admin.people.link_for', ['name' => $link['name']]) ?></h2>
    <?php if (!empty($link['emailed'])): ?>
      <p><?= th('admin.people.link_emailed', ['email' => $link['emailed'], 'count' => SETUP_LINK_DAYS]) ?></p>
    <?php else: ?>
      <p><?= th('admin.people.link_send', ['name' => $link['name'], 'count' => SETUP_LINK_DAYS]) ?></p>
    <?php endif; ?>
    <p><code class="link-box" dir="ltr"><?= h($link['url']) ?></code>
       <button type="button" class="secondary" data-copy="<?= h($link['url']) ?>"><?= th('admin.copy_link') ?></button></p>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2><?= th('admin.people.title') ?></h2>
    <?php $editUser = find_user((int)input($_GET, 'edit_user')); if ($editUser): ?>
      <form method="post" class="edit-guest" id="edit-user">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
        <p class="edit-title"><?= th('admin.edit.title', ['name' => $editUser['name']]) ?> <span class="hint"><?= th('admin.people.username_same', ['username' => $editUser['username']]) ?></span></p>
        <label><?= th('admin.field.name') ?><input type="text" name="name" value="<?= h($editUser['name']) ?>" required></label>
        <label><?= th('admin.field.email') ?><input type="email" name="email" value="<?= h($editUser['email']) ?>" placeholder="<?= th('admin.people.email_placeholder') ?>" dir="ltr"></label>
        <button type="submit" name="action" value="user_update"><?= th('admin.save') ?></button>
        <a class="link" href="/admin.php?tab=people"><?= th('admin.cancel') ?></a>
      </form>
    <?php endif; ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th><?= th('admin.field.name') ?></th><th><?= th('admin.people.h_username') ?></th><th><?= th('admin.field.email') ?></th>
          <th><?= th('admin.people.h_status') ?></th><th><?= th('admin.people.h_last') ?></th><th><?= th('admin.people.h_feedback') ?></th><th></th></tr></thead>
        <tbody>
          <tr>
            <?php $ownerContact = admin_contact(0); ?>
            <td><?= th('admin.people.you_owner') ?></td><td><code><?= OWNER_USERNAME ?></code></td>
            <td class="email-cell" dir="ltr"><?= $ownerContact['email'] !== '' ? h($ownerContact['email']) : '—' ?></td>
            <td><span class="pill yes"><?= th('admin.people.active') ?></span></td><td>—</td>
            <td><?= feedback_toggle(0, '', $ownerContact) ?></td>
            <td class="hint"><?= th('admin.people.owner_password') ?></td>
          </tr>
          <?php foreach ($users as $u):
              $pending = !$u['password_hash'];
              $expired = $pending && (!$u['setup_expires'] || $u['setup_expires'] < time()); ?>
          <tr>
            <td><?= h($u['name']) ?></td>
            <td><code><?= h($u['username']) ?></code></td>
            <td class="email-cell" dir="ltr"><?= $u['email'] ? h($u['email']) : '—' ?></td>
            <td><span class="pill <?= $pending ? 'waiting' : 'yes' ?>"><?= th($pending ? ($expired ? 'admin.people.expired' : 'admin.people.waiting') : 'admin.people.active') ?></span>
              <?php $keys = count(user_passkeys((int)$u['id'])); if ($keys): ?><span class="who"><?= th('admin.passkey.count', ['count' => $keys]) ?></span><?php endif; ?></td>
            <td><?= $u['last_login'] ? h(admin_date(strtotime($u['last_login'] . ' UTC'), 'time')) : '—' ?></td>
            <td><?= feedback_toggle((int)$u['id'], $u['name'], admin_contact((int)$u['id'])) ?></td>
            <td class="row-actions">
              <a class="link small" href="/admin.php?tab=people&amp;edit_user=<?= (int)$u['id'] ?>#edit-user"><?= th('admin.edit') ?></a>
              <?php if (valid_email($u['email'])): ?>
              <form method="post" class="inline" data-confirm="<?= th($pending ? 'admin.people.confirm_email' : 'admin.people.confirm_email_reset', ['name' => $u['name'], 'email' => $u['email']]) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="send_email" value="1">
                <button class="link small" name="action" value="user_reset"><?= th($pending ? 'admin.people.email_setup' : 'admin.people.email_reset') ?></button>
              </form>
              <?php endif; ?>
              <form method="post" class="inline" data-confirm="<?= th(user_passkeys((int)$u['id']) ? 'admin.people.confirm_new_link_passkeys' : 'admin.people.confirm_new_link', ['name' => $u['name']]) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="link small" name="action" value="user_reset"><?= th($pending ? 'admin.people.new_link' : 'admin.people.reset') ?></button>
              </form>
              <form method="post" class="inline" data-confirm="<?= th('admin.people.confirm_remove', ['name' => $u['name']]) ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="link small danger" name="action" value="user_remove"><?= th('admin.remove') ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <h2><?= th('admin.people.add_title') ?></h2>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <div class="field-row">
        <label><?= th('admin.field.name') ?><input type="text" name="name" placeholder="Sam" required></label>
        <label><?= th('admin.login.username') ?><input type="text" name="username" placeholder="sam" autocapitalize="none" spellcheck="false" dir="ltr" required></label>
        <label><?= th('admin.field.email') ?><input type="email" name="email" placeholder="sam@example.com" dir="ltr"></label>
      </div>
      <label class="check"><input type="checkbox" name="send_email" value="1" checked> <?= th('admin.people.email_them') ?></label>
      <p class="hint"><?= th('admin.people.add_hint') ?></p>
      <button type="submit" name="action" value="user_add"><?= th('admin.people.add') ?></button>
    </form>
  </section>

<?php elseif ($tab === 'account'): $mine = user_passkeys((int)$me['id']); $origin = passkey_origin(); ?>

  <h2 class="page-title"><?= th('admin.tab.account') ?></h2>
  <?php $contact = admin_contact((int)$me['id']); ?>
  <section class="card">
    <h2><?= th('admin.account.email_title') ?></h2>
    <form method="post" class="email-form account-contact">
      <?= csrf_field() ?>
      <label><?= th('admin.account.your_email') ?><input type="email" name="email" value="<?= h($contact['email']) ?>" placeholder="you@example.com" autocomplete="email" dir="ltr"></label>
      <?php if ($me['id']): ?>
        <label><?= th('admin.account.current_password') ?> <span class="hint-inline"><?= th('admin.account.current_password_hint') ?></span>
          <input type="password" name="current_password" autocomplete="current-password"></label>
        <p class="hint"><?= th('admin.account.also_reset') ?></p>
      <?php else: ?>
        <p class="hint"><?= th('admin.account.owner_hint') ?></p>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="notify_feedback" value="1" <?= $contact['notify'] ? 'checked' : '' ?>>
        <?= th('admin.account.notify') ?></label>
      <p class="hint"><?= th('admin.account.notify_hint') ?></p>
      <button type="submit" name="action" value="account_contact"><?= th('admin.save') ?></button>
    </form>
  </section>

  <section class="card">
    <h2><?= th('admin.account.language_title') ?></h2>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <?php $myChoice = admin_language_choice((int)$me['id']); ?>
      <label><?= th('admin.account.language_label') ?>
        <select name="language" aria-describedby="admin-language-hint">
          <option value=""><?= th('admin.account.language_browser', ['language' => LANGUAGE_NAMES[visitor_language()]]) ?></option>
          <?php foreach (array_keys(LANGUAGE_NAMES) as $code): ?>
            <option value="<?= h($code) ?>" lang="<?= h($code) ?>" <?= $myChoice === $code ? 'selected' : '' ?>><?= h(LANGUAGE_NAMES[$code]) ?></option>
          <?php endforeach; ?>
        </select></label>
      <p class="hint" id="admin-language-hint"><?= th('admin.account.language_hint') ?></p>
      <button type="submit" name="action" value="account_language"><?= th('admin.save') ?></button>
    </form>
  </section>

  <section class="card">
    <h2><?= th('admin.passkey.title') ?></h2>
    <p class="hint"><?= t_html('admin.passkey.hint', ['every_time' => '<strong>' . th('admin.passkey.every_time') . '</strong>']) ?></p>
    <?php if (is_owner() && PASSKEY_RECOVERY): ?>
      <p class="notice" role="status"><?= t_html('admin.passkey.recovery_on', ['false' => '<code>false</code>']) ?></p>
    <?php endif; ?>
    <?php if ($mine): ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= th('admin.passkey.h_name') ?></th><th><?= th('admin.passkey.h_added') ?></th><th><?= th('admin.passkey.h_used') ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($mine as $p): ?>
            <tr>
              <td><?= h($p['name']) ?></td>
              <td><?= h(admin_date(strtotime($p['created_at'] . ' UTC'), 'year')) ?></td>
              <td><?= $p['last_used_at'] ? h(admin_date(strtotime($p['last_used_at'] . ' UTC'), 'time')) : '—' ?></td>
              <td>
                <form method="post" class="inline" data-confirm="<?= th(count($mine) === 1 ? 'admin.passkey.confirm_remove_last' : 'admin.passkey.confirm_remove', ['name' => $p['name']]) ?>">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="link small danger" name="action" value="passkey_remove"><?= th('admin.remove') ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p><?= th('admin.passkey.none') ?></p>
    <?php endif; ?>

    <?php if (!$origin): ?>
      <p class="hint"><?= th('admin.passkey.live_only', ['url' => SITE_URL]) ?></p>
    <?php else: ?>
      <form method="post" class="passkey-add">
        <?= csrf_field() ?>
        <label for="passkey-name"><?= th('admin.passkey.name_label') ?></label>
        <div class="csv-row">
          <input id="passkey-name" name="passkey_name" type="text" maxlength="60" value="<?= $mine ? '' : th('admin.passkey.my_phone') ?>" placeholder="<?= th('admin.passkey.name_placeholder') ?>">
          <button type="button" data-passkey="register" hidden><?= th('admin.passkey.add') ?></button>
        </div>
        <p class="error" data-passkey-unsupported hidden><?= th('admin.passkey.unsupported_create') ?></p>
        <p class="error" role="alert" data-passkey-message hidden></p>
      </form>
    <?php endif; ?>
  </section>

<?php endif; ?>
</main>
<?= js_phrases(['admin.copied', 'admin.copy_link', 'admin.bulk.hint', 'admin.bulk.js_selected', 'admin.bulk.js_too_many',
    'admin.bulk.js_confirm_delete', 'admin.bulk.send', 'admin.bulk.resend', 'admin.bulk.send_resend', 'admin.bulk.js_confirm_send',
    'admin.bulk.js_again_one', 'admin.bulk.js_again_all', 'admin.bulk.js_again_some', 'admin.bulk.js_skipped',
    'admin.md.js_bold', 'admin.md.js_italic', 'admin.md.js_link',
    'admin.js.reload', 'admin.js.exists', 'admin.js.not_added', 'admin.js.cancelled', 'admin.js.wrong']) ?>
<?php if ($tab === 'account'): ?><script src="<?= h(asset_url('/assets/passkey.js')) ?>"></script><?php endif; ?>
<script src="<?= h(asset_url('/assets/admin.js')) ?>"></script>
<?php page_footer();
