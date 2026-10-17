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

// --- Setup link: a new person chooses their password -----------------

if (isset($_GET['setup'])) {
    $token = input($_GET, 'setup');
    $user = user_for_setup_token($token);
    if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $password = input($_POST, 'password');
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $error = 'Please use at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        } elseif ($password !== input($_POST, 'confirm')) {
            $error = "The two passwords don't match.";
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
            $_SESSION['flash'] = 'Welcome, ' . $user['name'] . '. Sign in with the username "' . $user['username'] . '" from now on.';
            header('Location: /admin.php');
            exit;
        }
    }
    page_header('Admin', 'centered admin');
    ?>
    <main class="entry">
      <h1>Admin</h1>
      <?php if (!$user): ?>
        <p class="code-box">This setup link has expired or has already been used. Ask for a new one.</p>
      <?php else: ?>
        <form method="post" class="code-box">
          <?= csrf_field() ?>
          <p>Hi <?= h($user['name']) ?>. Choose a password for signing in as <strong><?= h($user['username']) ?></strong>.</p>
          <label for="password">New password (at least <?= MIN_PASSWORD_LENGTH ?> characters)</label>
          <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
          <label for="confirm">Type it again</label>
          <input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
          <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
          <button type="submit">Save password</button>
        </form>
      <?php endif; ?>
      <p class="site-footer"><a href="/admin.php">Sign in</a></p>
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
            $error = 'Too many attempts. Please wait 15 minutes.';
        } else {
            record_failed_attempt();
            $user = find_user_by_login(input($_POST, 'login'));
            if ($user && valid_email($user['email']) && !setup_link_recent($user)) {
                send_setup_email($user, new_setup_link((int)$user['id']), true);
            }
            $sent = true;
        }
    }
    page_header('Admin', 'centered admin');
    ?>
    <main class="entry">
      <h1>Admin</h1>
      <?php if ($sent): ?>
        <p class="code-box">If that account has an email address, a link to reset the password is on its way.
          It works once and expires in <?= SETUP_LINK_DAYS ?> days. Check your spam folder if it doesn't arrive.</p>
      <?php else: ?>
        <form method="post" class="code-box">
          <?= csrf_field() ?>
          <p>Enter your username or email address and we'll email you a link to choose a new password.</p>
          <label for="login">Username or email</label>
          <input id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
          <?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
          <button type="submit">Email me a link</button>
          <p class="hint">The owner's <code><?= OWNER_USERNAME ?></code> password is set in config.php and can't be reset by email.</p>
        </form>
      <?php endif; ?>
      <p class="site-footer"><a href="/admin.php">&larr; Back to sign in</a></p>
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
        exit(json_encode(['error' => 'Passkeys only work on ' . SITE_URL . '.']));
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
        exit(json_encode(['error' => 'Please sign in again.']));
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
    $result = 'That passkey didn\'t work here. Please try again.';
    if (too_many_attempts()) {
        $result = 'Too many attempts. Please wait 15 minutes.';
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
        $result = 'That passkey belongs to someone who can no longer sign in.';
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
        $error = 'Too many attempts. Please wait 15 minutes.';
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
            ? 'Set ADMIN_PASSWORD in config.php first.'
            : 'Wrong username or password.';
    }
}

if (!is_admin() && ($pending = pending_admin())) {
    page_header('Admin', 'centered admin');
    ?>
    <main class="entry">
      <h1>Admin</h1>
      <form method="post" class="code-box passkey-step">
        <?= csrf_field() ?>
        <p>Hi <?= h($pending['name']) ?>. Now confirm it's you with your passkey.</p>
        <button type="button" data-passkey="confirm" hidden>Use my passkey</button>
        <p class="error" data-passkey-unsupported hidden>This browser can't use passkeys. Try the browser or device where you added it.</p>
        <p class="error" role="alert" data-passkey-message <?= $error ? '' : 'hidden' ?>><?= h($error) ?></p>
        <button type="submit" class="link" name="action" value="cancel_sign_in">Sign in as someone else</button>
      </form>
      <p class="site-footer"><a href="/">&larr; Back to site</a></p>
    </main>
    <script src="<?= h(asset_url('/assets/passkey.js')) ?>"></script>
    <?php
    page_footer();
    exit;
}

if (!is_admin()) {
    page_header('Admin', 'centered admin');
    ?>
    <main class="entry">
      <h1>Admin</h1>
      <form method="post" class="code-box">
        <?= csrf_field() ?>
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username webauthn" autocapitalize="none"
               spellcheck="false" required autofocus value="<?= h(input($_POST, 'username')) ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <p class="error" role="alert" data-passkey-message <?= $error ? '' : 'hidden' ?>><?= h($error) ?></p>
        <button type="submit" name="login" value="1">Sign in</button>
        <button type="button" class="secondary passkey-button" data-passkey="login" hidden>Sign in with a passkey</button>
        <p class="forgot"><a href="/admin.php?forgot=1">Forgot password?</a></p>
      </form>
      <p class="site-footer"><a href="/">&larr; Back to site</a></p>
    </main>
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

$unreadFeedback = (int)db()->query('SELECT COUNT(*) FROM feedback WHERE read_at IS NULL')->fetchColumn();
$tabs = ['guests' => 'Guests', 'insights' => 'Insights', 'email' => 'Invitation email', 'updates' => 'Updates', 'content' => 'Content',
    'feedback' => 'Feedback' . ($unreadFeedback ? " ($unreadFeedback)" : '')];
$tabs['account'] = 'Your sign-in';
if (is_owner()) {
    $tabs['people'] = 'People';
}
$tab = input($_GET, 'tab');
if (!isset($tabs[$tab])) {
    $tab = 'guests';
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
                $problem = 'Choose a CSV file to upload.';
            } elseif ($file['size'] > 1024 * 1024) {
                $problem = 'That file is over 1 MB. A guest list should be much smaller, so check it\'s the right file.';
            } else {
                $rows = parse_guest_csv((string)file_get_contents((string)$file['tmp_name']));
                if (count($rows) > 2000) {
                    [$rows, $problem] = [[], 'That file has more than 2,000 rows, so nothing was added. Check it\'s the right file.'];
                } elseif (!$rows) {
                    $problem = 'No names found in that file. Put each invitation on its own row, with the name in the first column.';
                }
            }
        }
        if ($problem) {
            $message = $problem;
        } else {
            [$added, $bad, $skipped] = add_guests($rows, $action === 'upload_csv');
            $message = "Added $added " . ($added === 1 ? 'invitation' : 'invitations') . '.';
            if ($skipped) {
                $message .= ' Skipped ' . count($skipped) . ' already on the list: ' . implode(', ', array_slice($skipped, 0, 10))
                    . (count($skipped) > 10 ? ' and ' . (count($skipped) - 10) . ' more' : '') . '.';
            }
            if ($bad) {
                $message .= ' These have an email address that looks wrong, so it won\'t be used: ' . implode(', ', $bad) . '.';
            }
        }
    } elseif ($action === 'greeting_style') {
        $style = input($_POST, 'style') === 'first' ? 'first' : 'full';
        save_setting('greeting_style', $style);
        $message = $style === 'first'
            ? 'Guests are now greeted by first names only, unless you\'ve set something different for them.'
            : 'Guests are now greeted by their full names, unless you\'ve set something different for them.';
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM guests WHERE id = ?')->execute([(int)input($_POST, 'id')]);
        $message = 'Invitation deleted.';
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
            $message = 'That\'s more invitations than can be sent in one go, so nothing was changed. Untick some and do it in two goes.';
        } elseif (!$ids) {
            $message = 'Tick the invitations you want to ' . ($action === 'send_selected' ? 'send' : 'delete') . ' first.';
        } elseif ($action === 'send_selected') {
            if (!valid_email($settings['from'])) {
                $message = 'The From address on the Invitation email tab isn\'t valid, so nothing was sent.';
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
                $message = 'Sent ' . $sent . ' ' . ($sent === 1 ? 'invitation' : 'invitations')
                    . ($resent ? " ($resent of them again)" : '') . '.';
                if ($noEmail) {
                    $message .= ' No email address for: ' . implode(', ', $noEmail) . '.';
                }
                if ($failed) {
                    $message .= ' Could not send to: ' . implode(', ', $failed) . '. Your host may limit emails per hour; try again later.';
                }
            }
        } else {
            $deleted = 0;
            foreach ($batches as [$batch, $marks]) {
                $q = db()->prepare("DELETE FROM guests WHERE id IN ($marks)");
                $q->execute($batch);
                $deleted += $q->rowCount();
            }
            $message = 'Deleted ' . $deleted . ' ' . ($deleted === 1 ? 'invitation' : 'invitations') . '.';
        }
    } elseif (in_array($action, ['save_email', 'preview', 'send_test', 'send_all'], true)) {
        $back = 'email';
        // The email form's buttons all save the template first.
        foreach (['from_name', 'from', 'reply_to', 'subject', 'body'] as $field) {
            save_setting('mail_' . $field, trim(str_replace("\r\n", "\n", input($_POST, $field))));
        }
        // One line, at most 60 characters; empty goes back to the default.
        preg_match('/^.{0,60}/us', trim((string)preg_replace('/\s+/', ' ', input($_POST, 'tagline'))), $m);
        save_setting('tagline', trim($m[0] ?? ''));
        if ($action === 'preview') {
            header('Location: /admin.php?preview=1');   // opens in its own tab
            exit;
        }
        $settings = mail_settings();
        $message = 'Email saved.';

        if (!valid_email($settings['from'])) {
            $message = 'Email saved, but the From address isn\'t valid, so nothing was sent.';
        } elseif ($action === 'send_test') {
            if (!valid_email($settings['reply_to'])) {
                $message = 'Add your own email under "Replies go to" so the test has somewhere to go.';
            } else {
                $sample = ['name' => 'Test Guest', 'email' => $settings['reply_to'], 'code' => 'TEST1234'];
                $message = send_invite($sample, $settings)
                    ? 'Test sent to ' . $settings['reply_to'] . '.'
                    : 'The test could not be sent. Check the From address is an email account on your domain.';
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
            $message = $pending
                ? "Sent $sent " . ($sent === 1 ? 'invitation' : 'invitations') . '.'
                : 'Everyone with an email address has already been sent their invitation.';
            if ($failed) {
                $message .= ' Could not send to: ' . implode(', ', $failed) . '. Your host may limit emails per hour; try again later.';
            }
        }
    } elseif ($action === 'update') {
        $id = (int)input($_POST, 'id');
        $name = trim(input($_POST, 'name'));
        $email = clean_emails(input($_POST, 'email'));
        $greetingStyle = in_array(input($_POST, 'greeting_style'), ['full', 'first'], true) ? input($_POST, 'greeting_style') : null;
        preg_match('/^.{0,120}/us', trim((string)preg_replace('/\s+/', ' ', input($_POST, 'greeting'))), $m);   // one line, 120 characters
        $greeting = trim($m[0] ?? '');
        $q = db()->prepare('SELECT * FROM guests WHERE id = ?');
        $q->execute([$id]);
        $g = $q->fetch();
        if (!$g) {
            $message = 'That invitation no longer exists.';
        } elseif ($name === '') {
            $message = 'The name can\'t be empty, so nothing was changed.';
        } else {
            // A new address hasn't been sent anything yet.
            $emailChanged = $email !== clean_emails((string)$g['email']);
            db()->prepare('UPDATE guests SET name = ?, email = ?, invited_at = ?, greeting = ?, greeting_style = ? WHERE id = ?')
                ->execute([$name, $email !== '' ? $email : null, $emailChanged ? null : $g['invited_at'],
                    $greeting !== '' ? $greeting : null, $greetingStyle, $id]);
            $message = 'Saved changes to ' . $name . '.';
            if (has_bad_email($email)) {
                $message .= sendable_emails($email)
                    ? ' One of the email addresses looks wrong, so only the others will be used.'
                    : ' That email address looks wrong, so they won\'t be emailed.';
            } elseif ($emailChanged && $email !== '' && $g['invited_at']) {
                $message .= count(sendable_emails($email)) > 1
                    ? ' The changed addresses haven\'t been sent the invitation yet. Sending it again sends a copy to each address.'
                    : ' Their new address hasn\'t been sent the invitation yet.';
            }
        }
    } elseif ($action === 'send_one') {
        $q = db()->prepare('SELECT * FROM guests WHERE id = ?');
        $q->execute([(int)input($_POST, 'id')]);
        $g = $q->fetch();
        if ($g) {
            $count = count(sendable_emails($g['email']));
            $message = send_invite($g, mail_settings())
                ? 'Invitation sent to ' . $g['name'] . ($count > 1 ? ($count === 2 ? ' (both addresses)' : " (all $count addresses)") : '') . '.'
                : 'Could not send to ' . $g['name'] . ($count > 1 ? ' (at least one address)' : '') . '. Check their email address and the From address.';
        }

    // --- Updates (bulk emails) ---
    } elseif (in_array($action, ['notify_preview', 'notify_test', 'notify_send', 'template_save', 'template_save_new', 'template_delete'], true)) {
        $back = 'updates';
        $draft = [
            'audience' => isset(audiences()[input($_POST, 'audience')]) ? input($_POST, 'audience') : 'all',
            'subject' => trim(str_replace(["\r", "\n"], ' ', input($_POST, 'subject'))),
            'body' => trim(str_replace("\r\n", "\n", input($_POST, 'body'))),
            'template_id' => (int)input($_POST, 'template_id'),
        ];
        $_SESSION['update_draft'] = $draft;   // keep the form filled in after the redirect
        $settings = mail_settings();

        if ($action === 'notify_preview') {
            header('Location: /admin.php?preview_update=1');   // opens in its own tab
            exit;
        } elseif ($action === 'template_delete') {
            $t = find_template((int)input($_POST, 'delete_id'));
            if ($t) {
                notify_db()->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$t['id']]);
                $message = "Deleted the \"{$t['name']}\" template.";
            }
        } elseif ($draft['subject'] === '' || $draft['body'] === '') {
            $message = 'Add a subject and a message first.';
        } elseif ($action === 'template_save' || $action === 'template_save_new') {
            $name = trim(input($_POST, 'template_name'));
            $existing = $action === 'template_save' ? find_template($draft['template_id']) : null;
            if ($existing) {
                notify_db()->prepare('UPDATE email_templates SET subject = ?, body = ? WHERE id = ?')
                    ->execute([$draft['subject'], $draft['body'], $existing['id']]);
                $message = "Updated the \"{$existing['name']}\" template.";
            } else {
                $name = $name !== '' ? $name : $draft['subject'];
                notify_db()->prepare('INSERT INTO email_templates (name, subject, body) VALUES (?, ?, ?)')
                    ->execute([$name, $draft['subject'], $draft['body']]);
                $_SESSION['update_draft']['template_id'] = (int)notify_db()->lastInsertId();
                $message = "Saved as a new template, \"$name\".";
            }
        } elseif (!valid_email($settings['from'])) {
            $message = 'Set a valid From address on the Invitation email tab first.';
        } elseif ($action === 'notify_test') {
            if (!valid_email($settings['reply_to'])) {
                $message = 'Add your own email under "Replies go to" on the Invitation email tab, so the test has somewhere to go.';
            } else {
                $sample = ['name' => 'Test Guest', 'email' => $settings['reply_to'], 'code' => 'TEST1234'];
                $message = send_update($sample, $draft['subject'], $draft['body'])
                    ? 'Test sent to ' . $settings['reply_to'] . '.'
                    : 'The test could not be sent. Check the From address on the Invitation email tab.';
            }
        } else {   // notify_send
            set_time_limit(300);
            $recipients = audience_guests($draft['audience']);
            $sent = 0;
            $failed = [];
            foreach ($recipients as $g) {
                if (send_update($g, $draft['subject'], $draft['body'])) {
                    $sent++;
                } else {
                    $failed[] = $g['name'];
                }
            }
            log_notification($draft['subject'], audiences()[$draft['audience']], $sent, count($failed), $me['name']);
            $message = $recipients ? "Sent the update to $sent " . ($sent === 1 ? 'guest' : 'guests') . '.' : 'Nobody in that group can be emailed.';
            if ($failed) {
                $message .= ' Could not send to: ' . implode(', ', $failed) . '. Your host may limit emails per hour; try again later.';
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
            $message = 'Message deleted.';
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
        if ($action === 'section_preview' || $title === '') {
            // Keep the unsaved draft and reopen the editor.
            $_SESSION['section_draft'] = compact('id', 'title', 'body', 'visible');
            $backExtra = '&section=' . ($id ?: 'new') . ($action === 'section_preview' ? '&show_preview=1' : '') . '#editor';
            $message = $title === '' && $action === 'section_save' ? 'Give the section a title before saving.' : '';
        } elseif ($id && find_section($id)) {
            content_db()->prepare('UPDATE sections SET title = ?, body = ?, visible = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?')
                ->execute([$title, $body, $visible, $me['name'], $id]);
            $message = "Saved \"$title\".";
        } else {
            $next = (int)content_db()->query('SELECT COALESCE(MAX(position), 0) + 1 FROM sections')->fetchColumn();
            content_db()->prepare('INSERT INTO sections (title, body, visible, position, updated_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$title, $body, $visible, $next, $me['name']]);
            $message = "Added \"$title\"" . ($visible ? ' to the guest page.' : ' (hidden until you show it).');
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
            $message = '"' . $s['title'] . '" is now ' . ($s['visible'] ? 'hidden from guests.' : 'shown on the guest page.');
        }
    } elseif ($action === 'section_delete') {
        $back = 'content';
        $s = find_section((int)input($_POST, 'id'));
        if ($s) {
            content_db()->prepare('DELETE FROM sections WHERE id = ?')->execute([$s['id']]);
            $message = 'Deleted "' . $s['title'] . '".';
        }

    // --- Your email address and feedback emails ---
    } elseif ($action === 'account_contact') {
        $back = 'account';
        $email = trim(input($_POST, 'email'));
        $notify = input($_POST, 'notify_feedback') === '1';
        $current = admin_contact((int)$me['id']);
        $changed = strtolower($email) !== strtolower($current['email']);
        if ($email !== '' && !valid_email($email)) {
            $message = 'That email address doesn\'t look right, so nothing was changed.';
        } elseif ($notify && $email === '') {
            $message = 'Add your email address to get feedback emails.';
        } elseif ($changed && $me['id'] && !password_matches((int)$me['id'], input($_POST, 'current_password'))) {
            // Other admins' addresses are used for password resets, so changing
            // one needs their password, not just a signed-in browser.
            $message = 'To change your email address, enter your current password too.';
        } else {
            save_admin_contact((int)$me['id'], $email, $notify);
            $message = $notify ? 'Saved. You\'ll get an email when a guest sends a message.' : 'Saved.';
        }

    // --- Your passkeys ---
    } elseif ($action === 'passkey_register') {
        $back = 'account';
        $challenge = take_passkey_challenge('register');
        $origin = passkey_origin();
        $clientData = b64url_decode(input($_POST, 'client_data'));
        $attestation = b64url_decode(input($_POST, 'attestation'));
        $result = 'That didn\'t work. Please try again.';
        if ($challenge && $origin && (int)$challenge['user'] === (int)$me['id'] && $clientData !== null && $attestation !== null) {
            $result = verify_passkey_registration(['challenge' => $challenge['challenge'], 'origin' => $origin[1], 'rp_id' => $origin[0]],
                $clientData, $attestation);
        }
        if (is_array($result) && find_passkey($result['credential_id'])) {
            $message = 'That passkey has already been added.';
        } elseif (is_array($result)) {
            preg_match('/^.{0,60}/us', trim((string)preg_replace('/\s+/', ' ', input($_POST, 'passkey_name'))), $m);
            $first = !user_passkeys((int)$me['id']);
            passkeys_db()->prepare('INSERT INTO passkeys (user_id, credential_id, public_key, sign_count, name) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int)$me['id'], $result['credential_id'], $result['public_key'], $result['count'], trim($m[0] ?? '') ?: 'Passkey']);
            $message = 'Passkey added.' . ($first ? ' From now on you\'ll need it to sign in, on its own or after your password.' : '');
        } else {
            $message = $result;
        }
    } elseif ($action === 'passkey_remove') {
        $back = 'account';
        passkeys_db()->prepare('DELETE FROM passkeys WHERE id = ? AND user_id = ?')->execute([(int)input($_POST, 'id'), (int)$me['id']]);
        $message = user_passkeys((int)$me['id'])
            ? 'Passkey removed.'
            : 'Passkey removed. You can now sign in with just your password, so consider adding another.';

    // --- People (owner only) ---
    } elseif (in_array($action, ['user_add', 'user_reset', 'user_remove', 'user_update'], true)) {
        $back = 'people';
        if (!is_owner()) {
            http_response_code(403);
            exit('Only the site owner can manage logins.');
        }
        $emailIt = input($_POST, 'send_email') === '1';
        if ($action === 'user_add') {
            $name = trim(input($_POST, 'name'));
            $username = normalise_username(input($_POST, 'username'));
            $email = trim(input($_POST, 'email'));
            if ($name === '' || !valid_username($username)) {
                $message = 'Enter a name, and a username of 2-30 letters, numbers, dots, dashes or underscores ("admin" is taken).';
            } elseif ($email !== '' && !valid_email($email)) {
                $message = 'That email address doesn\'t look right.';
            } else {
                try {
                    users_db()->prepare('INSERT INTO users (name, username, email) VALUES (?, ?, ?)')
                        ->execute([$name, $username, $email !== '' ? $email : null]);
                    $u = find_user((int)users_db()->lastInsertId());
                    $url = new_setup_link((int)$u['id']);
                    $emailed = $emailIt && send_setup_email($u, $url, false);
                    $_SESSION['setup_link'] = ['name' => $name, 'url' => $url, 'emailed' => $emailed ? $email : ''];
                    $message = "Added $name." . ($emailed ? " Their setup link has been emailed to $email."
                        : ($emailIt && $email !== '' ? ' The email could not be sent, so send them the link below.' : ' Send them the setup link below.'));
                } catch (PDOException $e) {
                    $message = "The username \"$username\" is already in use.";
                }
            }
        } else {
            $u = find_user((int)input($_POST, 'id'));
            if ($u && $action === 'user_update') {
                $name = trim(input($_POST, 'name'));
                $email = trim(input($_POST, 'email'));
                if ($name === '' || ($email !== '' && !valid_email($email))) {
                    $message = 'Enter a name and a valid email address (or leave the email empty).';
                } else {
                    users_db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                        ->execute([$name, $email !== '' ? $email : null, $u['id']]);
                    $message = "Saved changes to $name.";
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
                    ? "Emailed a new link to {$u['name']} ({$u['email']}). Their old password no longer works."
                    : "New setup link for {$u['name']} below. Their old password no longer works."
                      . ($emailIt ? ' The email could not be sent, so send them the link yourself.' : '');
            } elseif ($u) {
                users_db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
                passkeys_db()->prepare('DELETE FROM passkeys WHERE user_id = ?')->execute([$u['id']]);
                $message = "Removed {$u['name']}. They can no longer sign in.";
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

if (isset($_GET['preview'])) {
    $settings = mail_settings();
    $sample = ['name' => 'Sarah & Tom Jones', 'code' => 'K7MPX4QR'];
    [$subject, , $html] = render_invite($sample, $settings, email_photo_url());
    // The email uses inline styles, which the site's normal policy blocks.
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    $bar = '<div style="position:sticky;top:0;padding:10px 16px;background:#18181b;color:#fff;'
        . 'font:14px Helvetica,Arial,sans-serif">Preview &middot; Subject: <strong>' . h($subject) . '</strong>'
        . ' &middot; shown for a sample guest</div>';
    echo preg_replace('/<body([^>]*)>/', '<body$1>' . $bar, $html, 1);
    exit;
}

// --- Update preview (opened in a new tab) ---------------------------

if (isset($_GET['preview_update'])) {
    $draft = $_SESSION['update_draft'] ?? ['subject' => '', 'body' => ''];
    $sample = ['name' => 'Sarah & Tom Jones', 'code' => 'K7MPX4QR'];
    [$subject, , $html] = render_update($sample, $draft['subject'], $draft['body'], email_photo_url());
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    $bar = '<div style="position:sticky;top:0;padding:10px 16px;background:#18181b;color:#fff;'
        . 'font:14px Helvetica,Arial,sans-serif">Preview &middot; Subject: <strong>' . h($subject) . '</strong>'
        . ' &middot; shown for a sample guest</div>';
    echo preg_replace('/<body([^>]*)>/', '<body$1>' . $bar, $html, 1);
    exit;
}

// --- Caterer list (CSV) ---------------------------------------------

if (input($_GET, 'export') === 'caterer') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dietary-requirements.csv"');
    $out = fopen('php://output', 'w');
    $h = headcount($guests);
    fputcsv($out, ['person', 'invitation', 'allergy or medical', 'requirements'], ',', '"', '');
    foreach (catering_rows($guests) as $r) {
        fputcsv($out, [csv_cell($r['person']), csv_cell($r['invitation']), $r['medical'] ? 'YES: ' . implode(', ', $r['medical']) : '',
            csv_cell(implode(', ', $r['needs']))], ',', '"', '');
    }
    fputcsv($out, [], ',', '"', '');
    fputcsv($out, ['Total people confirmed', $h['confirmed'] . ($h['confirmed_exact'] ? '' : '+')], ',', '"', '');
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

page_header('Admin', 'admin');
?>
<?php
$myPasskeys = count(user_passkeys((int)$me['id']));
$myUsername = $me['id'] ? (string)(find_user((int)$me['id'])['username'] ?? '') : OWNER_USERNAME;
?>
<header class="top wide">
  <span class="brand">Admin <span class="version">v<?= h(SITE_VERSION) ?></span></span>
  <nav class="top-links">
    <a class="link" href="/">View site</a>
    <?php /* Account menu: a <details> so it works by keyboard and without JavaScript. */ ?>
    <details class="account-menu">
      <summary aria-label="Account: <?= h($me['name']) ?><?= $myPasskeys ? '' : ' (no passkey yet)' ?>">
        <span class="avatar" aria-hidden="true"><?= h(initials($me['name'])) ?></span>
        <?php if (!$myPasskeys): ?><span class="avatar-dot" aria-hidden="true"></span><?php endif; ?>
      </summary>
      <div class="account-panel">
        <div class="account-who">
          <span class="avatar large" aria-hidden="true"><?= h(initials($me['name'])) ?></span>
          <div>
            <strong><?= h($me['name']) ?></strong>
            <span><?= is_owner() ? 'Owner' : 'Admin' ?> · <?= h($myUsername) ?></span>
          </div>
        </div>
        <a class="account-item" href="/admin.php?tab=account" <?= $tab === 'account' ? 'aria-current="page"' : '' ?>>
          <span>Your account</span>
          <small class="<?= $myPasskeys ? '' : 'nudge' ?>"><?= $myPasskeys
              ? $myPasskeys . ' passkey' . ($myPasskeys === 1 ? '' : 's')
              : 'No passkey yet: add one' ?><?= admin_contact((int)$me['id'])['notify'] ? ' · feedback emails on' : '' ?></small>
        </a>
        <form method="post" class="account-signout">
          <?= csrf_field() ?>
          <button class="account-item" name="action" value="logout">Sign out</button>
        </form>
      </div>
    </details>
  </nav>
</header>

<main class="content wide">
  <nav class="tabs" aria-label="Admin sections">
    <?php foreach ($tabs as $key => $label): if ($key === 'account') continue;   // in the account menu instead ?>
      <a href="/admin.php?tab=<?= $key ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($message): ?><p class="notice" role="status"><?= h($message) ?></p><?php endif; ?>

<?php if ($tab === 'guests'): ?>

  <section class="stats">
    <div><strong><?= $counts['yes'] ?></strong><span>Attending</span>
      <small><?= $people ?><?= $peopleUnknown ? '+' : '' ?> <?= $people === 1 && !$peopleUnknown ? 'person' : 'people' ?></small></div>
    <div><strong><?= $counts['no'] ?></strong><span>Not attending</span></div>
    <div><strong><?= $counts['maybe'] ?></strong><span>Getting back to you</span></div>
    <div><strong><?= $counts['waiting'] ?></strong><span>No reply</span></div>
  </section>

  <?php $diet = dietary_mix($guests); $segments = top_with_other($diet['mix']); ?>
  <section class="card diet-summary">
    <h2>Dietary requirements</h2>
    <?php if (!$segments): ?>
      <p class="hint">None yet. This fills in as guests who are coming choose their dietary requirements.</p>
    <?php else: $totalMentions = array_sum($segments); ?>
      <div class="diet-chart">
        <?= dietary_donut_svg($segments, $diet['people_with'], $diet['people_coming']) ?>
        <table class="diet-legend">
          <thead><tr><th>Requirement</th><th>Mentions</th><th>Share</th></tr></thead>
          <tbody>
          <?php $i = 0; foreach ($segments as $label => $count): ?>
            <tr data-seg="<?= $i ?>">
              <td><span class="swatch swatch-<?= $label === 'Other' ? 'other' : $i ?>"></span><?= h($label) ?></td>
              <td><?= $count ?></td>
              <td><?= round($count / $totalMentions * 100) ?>%</td>
            </tr>
          <?php $i++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= $diet['people_with'] ?> of the <?= $diet['people_coming'] ?> people coming have at least one requirement.
        Some have more than one, so the chart shows how often each is mentioned.
        <?php if (isset($segments['Other'])): ?>"Other" groups the less common ones; the full list is in the Dietary column below and the CSV.<?php endif; ?></p>
    <?php endif; ?>
  </section>

  <section class="card collapsible" data-key="add-invitations">
    <?= card_heading('Add invitations', 'add-invitations') ?>
    <div class="card-body" id="add-invitations-body">
      <form method="post">
        <?= csrf_field() ?>
        <label for="guests">One per line: <em>Name; email</em>. Email is optional, and a couple can have one each
          (<em>Name; email; email</em>). Each line gets its own code.</label>
        <textarea id="guests" name="guests" rows="5" placeholder="Sarah, Tom and Lily Jones; sarah@example.com&#10;Ali &amp; Jo Khan; ali@example.com; jo@example.com&#10;Uncle Bob"></textarea>
        <button type="submit" name="action" value="add">Add</button>
      </form>
      <form method="post" enctype="multipart/form-data" class="csv-upload">
        <?= csrf_field() ?>
        <label for="csv">Or upload a spreadsheet saved as CSV</label>
        <p class="hint" id="csv-hint">One invitation per row: the name in the first column, then any email addresses in the
          next columns. Headings like <em>Name</em>, <em>Email</em> and <em>Email 2</em> are fine, and other columns are ignored.
          Names already on the list are skipped, so it's safe to upload an updated file.</p>
        <div class="csv-row">
          <input id="csv" type="file" name="csv" accept=".csv,text/csv" required aria-describedby="csv-hint">
          <button type="submit" name="action" value="upload_csv">Upload</button>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="row-between">
      <h2>Invitations (<?= count($guests) ?>)</h2>
      <a class="link" href="/admin.php?export=1">Download CSV</a>
    </div>
    <form method="post" class="greeting-style">
      <?= csrf_field() ?>
      <label for="greet-style">Greet guests by</label>
      <select id="greet-style" name="style" aria-describedby="greet-hint">
        <option value="full" <?= site_greeting_style() === 'full' ? 'selected' : '' ?>>Full name (Sarah, Tom and Lily Jones)</option>
        <option value="first" <?= site_greeting_style() === 'first' ? 'selected' : '' ?>>First names only (Sarah, Tom and Lily)</option>
      </select>
      <button type="submit" class="secondary" name="action" value="greeting_style">Save</button>
      <p class="hint" id="greet-hint">Used for "Dear …" in emails and the welcome on their invitation page.
        Surnames the site has found are in <strong>bold</strong>. Edit an invitation to change just that one.</p>
    </form>
    <?php foreach ($guests as $g): if ($g['id'] != $editId) continue; ?>
      <form method="post" class="edit-guest" id="edit">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
        <p class="edit-title">Editing <?= h($g['name']) ?> <span class="hint">(code <?= h($g['code']) ?> stays the same)</span></p>
        <p class="hint edit-people">
          <?php $members = party_members($g['name']); ?>
          <?= $members
              ? 'Dietary boxes for: ' . h(implode(', ', $members)) . '.'
              : 'The name doesn\'t say how many people, so they get one dietary box for the whole party.' ?>
          Separate people with <code>&amp;</code>, <code>,</code> or <code>and</code>; add a plus-one with <code>+ guest</code>.
        </p>
        <label>Name<input type="text" name="name" value="<?= h($g['name']) ?>" required autofocus></label>
        <label>Email<input type="text" name="email" value="<?= h($g['email']) ?>" placeholder="Optional"
          inputmode="email" autocomplete="off" spellcheck="false" aria-describedby="email-hint"></label>
        <p class="hint edit-email" id="email-hint">For two or more addresses, separate them with commas. Each gets its own copy.</p>
        <label>Greet them by
          <select name="greeting_style">
            <option value="">Site choice (<?= site_greeting_style() === 'first' ? 'first names' : 'full name' ?>)</option>
            <option value="full" <?= ($g['greeting_style'] ?? '') === 'full' ? 'selected' : '' ?>>Full name: <?= h($g['name']) ?></option>
            <option value="first" <?= ($g['greeting_style'] ?? '') === 'first' ? 'selected' : '' ?>>First names: <?= h(first_names($g['name'])) ?></option>
          </select></label>
        <label>Or type the greeting<input type="text" name="greeting" value="<?= h((string)($g['greeting'] ?? '')) ?>" maxlength="120"
          placeholder="<?= h(greeting_name(['greeting' => ''] + $g)) ?>" aria-describedby="greeting-hint"></label>
        <p class="hint edit-email" id="greeting-hint">Shown as "Dear …" in emails and on their page. Leave empty to use the choice above.</p>
        <button type="submit" name="action" value="update">Save</button>
        <a class="link" href="/admin.php?tab=guests#guest-<?= (int)$g['id'] ?>">Cancel</a>
      </form>
    <?php endforeach; ?>
    <?php if ($guests): ?>
    <form method="post" id="bulk" class="bulk-bar">
      <?= csrf_field() ?>
      <span id="bulk-count" class="hint" aria-live="polite">Tick invitations to send or delete several at once.</span>
      <span class="bulk-actions">
      <button type="submit" class="bulk-send" name="action" value="send_selected"
              data-confirm="Send the invitation to the ticked guests?">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>
        </svg>
        <span class="bulk-send-label">Send selected</span>
      </button>
      <button type="submit" class="bulk-delete" name="action" value="delete_selected"
              data-confirm="Delete the ticked invitations? This can't be undone.">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>
        </svg>
        Delete selected
      </button>
      </span>
    </form>
    <?php endif; ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th class="select-cell"><input type="checkbox" id="select-all" aria-label="Select all invitations" hidden></th><th>Name</th><th>Email</th><th>Code</th><th>Emailed</th><th>Reply</th><th>Dietary</th><th title="Whether they want update emails">Updates</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($guests as $g):
            $status = rsvp_status($g);
            $who = attendees($g);
            $invited = count(party_members($g['name'])); ?>
          <tr id="guest-<?= (int)$g['id'] ?>" <?= $g['id'] == $editId ? 'class="editing"' : '' ?>>
            <td class="select-cell"><input type="checkbox" name="ids[]" value="<?= (int)$g['id'] ?>" form="bulk" aria-label="Select <?= h($g['name']) ?>"
              data-sendable="<?= sendable_emails($g['email']) ? '1' : '0' ?>" data-sent="<?= $g['invited_at'] ? '1' : '0' ?>"></td>
            <td class="name-cell"><?= name_html($g['name']) ?>
              <?php if (greeting_name($g) !== $g['name']): ?><span class="greeted-as">Greeted as “<?= h(greeting_name($g)) ?>”</span><?php endif; ?></td>
            <td class="email-cell">
              <?php foreach (email_list($g['email']) as $address): ?>
                <span class="email-address"><?= h($address) ?><?php if (!valid_email($address)): ?> <span class="pill no">Check address</span><?php endif; ?></span>
              <?php endforeach; ?>
            </td>
            <td>
              <code><?= h($g['code']) ?></code>
              <button type="button" class="link small" data-copy="<?= h(invite_link($g['code'])) ?>">Copy link</button>
            </td>
            <td>
              <?= $g['invited_at'] ? h(date('j M', strtotime($g['invited_at'] . ' UTC'))) : '—' ?>
              <?php if (sendable_emails($g['email'])): ?>
                <form method="post" class="inline"
                      <?= $g['invited_at'] ? 'data-confirm="Send ' . h($g['name']) . ' their invitation again?"' : '' ?>>
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                  <button class="link small" name="action" value="send_one"><?= $g['invited_at'] ? 'Resend' : 'Send' ?></button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <span class="pill <?= $status ?>"><?= ['yes' => 'Attending', 'no' => 'Declined', 'maybe' => 'Not sure yet', 'waiting' => '—'][$status] ?></span>
              <?php if ($status === 'yes' && $invited > 1 && $who['count'] < $invited): ?>
                <span class="who" title="<?= h(implode(', ', $who['names'])) ?>"><?= $who['count'] ?> of <?= $invited ?>: <?= h(implode(', ', $who['names'])) ?></span>
              <?php elseif ($status === 'yes' && !$invited): ?>
                <span class="who"><?= $who['known'] ? $who['count'] . ' coming' : 'number not given' ?></span>
              <?php endif; ?>
            </td>
            <td class="dietary-cell"><?= $status === 'yes' && dietary_summary($g) !== '' ? h(dietary_summary($g)) : '—' ?></td>
            <td>
              <?php if (!sendable_emails($g['email'])): ?>—
              <?php elseif ((int)$g['notify'] === 1): ?><span class="pill yes" title="Happy to get update emails">On</span>
              <?php else: ?><span class="pill waiting" title="Opted out of update emails">Off</span>
              <?php endif; ?>
            </td>
            <td>
              <a class="link small" href="/admin.php?tab=guests&amp;edit=<?= (int)$g['id'] ?>#edit">Edit</a>
              <form method="post" class="inline" data-confirm="Delete <?= h($g['name']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button class="link small danger" name="action" value="delete">Delete</button>
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
        return $days <= 0 ? 'today' : ($days === 1 ? 'yesterday' : "$days days ago");
    };
    $first = $funnel['steps']['Invitations'] ?: 1;
?>

  <section class="card">
    <h2>Replies so far</h2>
    <div class="funnel">
      <?php foreach ($funnel['steps'] as $label => $count): ?>
        <div class="funnel-row">
          <span class="funnel-label"><?= h($label) ?></span>
          <svg class="funnel-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">
            <rect class="track" width="100" height="10" rx="2"/>
            <rect class="fill" width="<?= max(0.8, round($count / $first * 100, 1)) ?>" height="10" rx="2"/>
          </svg>
          <span class="funnel-value"><?= $count ?> <small><?= round($count / $first * 100) ?>%</small></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="hint">Of those who replied: <?= $funnel['split']['yes'] ?> coming, <?= $funnel['split']['no'] ?> not coming,
      <?= $funnel['split']['maybe'] ?> not sure yet. "Visited" counts guests who have opened their invitation page.</p>
  </section>

  <div class="insight-lists">
    <section class="card">
      <h2>Visited, not replied <span class="count"><?= count($visitedNoReply) ?></span></h2>
      <p class="hint">They've looked at the site but haven't answered. The best people for a gentle nudge.</p>
      <?php if (!$visitedNoReply): ?><p class="empty">Nobody right now.</p><?php else: ?>
      <ul class="people-list">
        <?php foreach ($visitedNoReply as $g): ?>
          <li><span><?= h($g['name']) ?></span><span class="hint">last visit <?= $ago($g['last_visit_at']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Emailed, not visited <span class="count"><?= count($emailedNotVisited) ?></span></h2>
      <p class="hint">The email may be in their spam folder, or they've not seen it yet.</p>
      <?php if (!$emailedNotVisited): ?><p class="empty">Nobody right now.</p><?php else: ?>
      <ul class="people-list">
        <?php foreach ($emailedNotVisited as $g): ?>
          <li><span><?= h($g['name']) ?></span><span class="hint">emailed <?= $ago($g['invited_at']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Not sure yet <span class="count"><?= count($notSure) ?></span></h2>
      <p class="hint">Longest waiting first.</p>
      <?php if (!$notSure): ?><p class="empty">Nobody right now.</p><?php else: ?>
      <ul class="people-list">
        <?php foreach ($notSure as $g): ?>
          <li><span><?= h($g['name']) ?></span><span class="hint">said so <?= $ago($g['responded_at']) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
  </div>
  <p class="hint insight-tip">To nudge people, use the <a href="/admin.php?tab=updates">Updates</a> tab: "Not replied yet" or "Not sure yet" with the "Reminder to reply" or "Not sure yet nudge" template.</p>

  <section class="card">
    <div class="row-between">
      <h2>Headcount and catering</h2>
      <a class="link" href="/admin.php?export=caterer">Download for the caterer (CSV)</a>
    </div>
    <section class="stats headcount">
      <div><strong><?= $hc['confirmed'] ?><?= $hc['confirmed_exact'] ? '' : '+' ?></strong><span>Confirmed</span></div>
      <div><strong>+<?= $hc['maybe'] ?></strong><span>Maybe (not sure yet)</span></div>
      <div><strong>+<?= $hc['no_reply'] ?></strong><span>Not replied</span></div>
      <div><strong><?= $hc['confirmed'] ?>–<?= $hc['confirmed'] + $hc['maybe'] + $hc['no_reply'] ?></strong><span>Final number range</span></div>
    </section>
    <p class="hint">The range runs from the people confirmed to everyone who could still come.
      <?php if ($hc['plus_ones_offered']): ?>Plus-ones: <?= $hc['plus_ones_coming'] ?> of <?= $hc['plus_ones_offered'] ?> coming.<?php endif; ?>
      <?php if (!$hc['confirmed_exact']): ?>"+" means a family hasn't said how many are coming.<?php endif; ?></p>

    <h3 class="sub">Dietary requirements by person</h3>
    <?php if (!$catering): ?>
      <p class="empty">None yet.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Person</th><th>Invitation</th><th>Requirements</th></tr></thead>
        <tbody>
        <?php foreach ($catering as $r): ?>
          <tr>
            <td class="name-cell"><?= h($r['person']) ?></td>
            <td class="name-cell hint"><?= h($r['invitation']) ?></td>
            <td class="dietary-cell">
              <?php foreach ($r['needs'] as $need): ?>
                <span class="need<?= is_medical_need($need) ? ' medical' : '' ?>"><?php if (is_medical_need($need)): ?><span class="sr-only">Allergy or medical: </span>⚠ <?php endif; ?><?= h($need) ?></span>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">⚠ marks allergies and medical needs (listed first). Double-check these with guests before the day.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Replies over time</h2>
    <?php if (count($timeline['days']) < 2): ?>
      <p class="empty">This chart appears once replies start coming in.</p>
    <?php else: ?>
      <?= reply_timeline_svg($timeline, count($guests)) ?>
      <p class="hint"><span class="key-line"></span> Total invitations replied &nbsp; <span class="key-send"></span> Invitations or updates sent (hover for details)</p>
      <details class="timeline-table">
        <summary>Show as a table</summary>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Day</th><th>New replies</th><th>Total</th><th>Sent</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($timeline['days'], true) as $day => $d): if (!$d['new'] && !isset($timeline['sends'][$day])) continue; ?>
              <tr>
                <td><?= h(date('D j M', strtotime($day))) ?></td>
                <td><?= $d['new'] ?: '—' ?></td>
                <td><?= $d['total'] ?></td>
                <td class="dietary-cell"><?= isset($timeline['sends'][$day]) ? h(implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($timeline['sends'][$day]), $timeline['sends'][$day]))) : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'email'): ?>

  <section class="card">
    <h2>Invitation email</h2>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <div class="field-row">
        <label>From name<input type="text" name="from_name" value="<?= h($mail['from_name']) ?>"></label>
        <label>From address<input type="email" name="from" value="<?= h($mail['from']) ?>"></label>
        <label>Replies go to<input type="email" name="reply_to" value="<?= h($mail['reply_to']) ?>" placeholder="you@example.com"></label>
      </div>
      <p class="hint">The From address should be an email account on your wedding domain, so emails aren't marked as spam. Replies and tests go to the "Replies go to" address.</p>
      <label>Tagline<input type="text" name="tagline" value="<?= h(setting('tagline')) ?>" maxlength="60"
        placeholder="<?= h(DEFAULT_TAGLINE) ?>" aria-describedby="tagline-hint"></label>
      <p class="hint" id="tagline-hint">The short line above your names, on the page where guests enter their code and at
        the top of this email. Leave it empty for "<?= h(DEFAULT_TAGLINE) ?>". For example: <em>Save the date</em>,
        <em>Join us</em> or <em>We're getting married</em>.</p>
      <label>Subject<input type="text" name="subject" value="<?= h($mail['subject']) ?>"></label>
      <label>Message
        <textarea name="body" rows="12"><?= h($mail['body']) ?></textarea>
      </label>
      <p class="hint"><code>{name}</code>, <code>{link}</code> and <code>{code}</code> are filled in for each guest. In the email, the link appears as a button.</p>
      <div class="actions">
        <button type="submit" name="action" value="save_email" class="secondary">Save</button>
        <button type="submit" name="action" value="preview" class="secondary" formtarget="_blank">Preview</button>
        <button type="submit" name="action" value="send_test" class="secondary">Send test to me</button>
        <button type="submit" name="action" value="send_all" <?= $unsent ? '' : 'disabled' ?>
                data-confirm="Send the invitation to <?= $unsent ?> <?= $unsent === 1 ? 'guest' : 'guests' ?>?">
          Send to <?= $unsent ?> not yet emailed
        </button>
      </div>
    </form>
  </section>

<?php elseif ($tab === 'updates'):
    $templates = all_templates();
    $draft = $_SESSION['update_draft'] ?? null;
    if ($t = find_template((int)input($_GET, 'template'))) {
        $draft = ['audience' => $draft['audience'] ?? 'all', 'subject' => $t['subject'], 'body' => $t['body'], 'template_id' => (int)$t['id']];
        $_SESSION['update_draft'] = $draft;
    }
    $draft = $draft ?? ['audience' => 'all', 'subject' => '', 'body' => '', 'template_id' => 0];
    $current = find_template((int)$draft['template_id']);
    $history = recent_notifications();
    $optedOut = (int)db()->query('SELECT COUNT(*) FROM guests WHERE notify = 0')->fetchColumn();
?>

  <section class="card">
    <h2>Send an update</h2>
    <p class="hint">Emails everyone in the group you choose who has an email address and hasn't turned updates off<?= $optedOut ? " ($optedOut " . ($optedOut === 1 ? 'guest has' : 'guests have') . ' turned them off)' : '' ?>.
       Each email has its own unsubscribe link.</p>
    <p class="add-section">Start from:
      <?php foreach ($templates as $t): ?>
        <a class="chip-link<?= $current && (int)$current['id'] === (int)$t['id'] ? ' active' : '' ?>" href="/admin.php?tab=updates&amp;template=<?= (int)$t['id'] ?>"><?= h($t['name']) ?></a>
      <?php endforeach; ?>
    </p>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <input type="hidden" name="template_id" value="<?= (int)$draft['template_id'] ?>">
      <label>Send to
        <select name="audience" class="party-size">
          <?php foreach (audiences() as $key => $label): $n = count(audience_guests($key)); ?>
            <option value="<?= $key ?>" <?= $draft['audience'] === $key ? 'selected' : '' ?>><?= h($label) ?> (<?= $n ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Subject<input type="text" name="subject" value="<?= h($draft['subject']) ?>"></label>
      <label>Message
        <textarea name="body" rows="12"><?= h($draft['body']) ?></textarea>
      </label>
      <p class="hint"><code>{name}</code>, <code>{link}</code> and <code>{code}</code> are filled in for each guest. In the email, the link appears as a button.</p>
      <div class="actions">
        <button type="submit" name="action" value="notify_preview" class="secondary" formtarget="_blank">Preview</button>
        <button type="submit" name="action" value="notify_test" class="secondary">Send test to me</button>
        <button type="submit" name="action" value="notify_send" data-confirm="Send this update to everyone in the chosen group?">Send update</button>
      </div>
      <details class="save-template">
        <summary>Save as a template</summary>
        <div class="actions">
          <?php if ($current): ?>
            <button type="submit" name="action" value="template_save" class="secondary">Update "<?= h($current['name']) ?>"</button>
          <?php endif; ?>
          <input type="text" name="template_name" placeholder="New template name">
          <button type="submit" name="action" value="template_save_new" class="secondary">Save as new template</button>
        </div>
      </details>
    </form>
  </section>

  <section class="card">
    <h2>Templates</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Subject</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <td><?= h($t['name']) ?></td>
            <td class="dietary-cell"><?= h($t['subject']) ?></td>
            <td class="row-actions">
              <a class="link small" href="/admin.php?tab=updates&amp;template=<?= (int)$t['id'] ?>">Use</a>
              <form method="post" class="inline" data-confirm="Delete the &quot;<?= h($t['name']) ?>&quot; template?">
                <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
                <button class="link small danger" name="action" value="template_delete">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <h2>Sent updates</h2>
    <?php if (!$history): ?>
      <p class="hint">Nothing sent yet.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Sent</th><th>Subject</th><th>To</th><th>Delivered</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($history as $n): ?>
          <tr>
            <td><?= h(date('j M, H:i', strtotime($n['sent_at'] . ' UTC'))) ?></td>
            <td class="dietary-cell"><?= h($n['subject']) ?></td>
            <td><?= h($n['audience']) ?></td>
            <td><?= (int)$n['sent'] ?><?= $n['failed'] ? ' <span class="pill no">' . (int)$n['failed'] . ' failed</span>' : '' ?></td>
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
      <h2>Feedback from guests</h2>
      <?php if ($unreadFeedback): ?>
        <form method="post"><?= csrf_field() ?><button class="link" name="action" value="feedback_read_all">Mark all as read</button></form>
      <?php endif; ?>
    </div>
    <p class="hint">Sent from the "Send us feedback" button on the guest page.</p>
    <?php if (!$messages): ?>
      <p>No feedback yet.</p>
    <?php endif; ?>
    <?php foreach ($messages as $f): ?>
      <article class="feedback-item<?= $f['read_at'] ? '' : ' unread' ?>">
        <header>
          <strong><?= h($f['guest_name']) ?></strong>
          <span class="hint"><?= h(date('j M Y, H:i', strtotime($f['created_at'] . ' UTC'))) ?></span>
          <?php if (!$f['read_at']): ?><span class="pill maybe">New</span><?php endif; ?>
        </header>
        <p class="feedback-text"><?= nl2br(h($f['message'])) ?></p>
        <div class="row-actions">
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <button class="link small" name="action" value="<?= $f['read_at'] ? 'feedback_unread' : 'feedback_read' ?>"><?= $f['read_at'] ? 'Mark as unread' : 'Mark as read' ?></button>
          </form>
          <form method="post" class="inline" data-confirm="Delete this message from <?= h($f['guest_name']) ?>?"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <button class="link small danger" name="action" value="feedback_delete">Delete</button>
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
        if ($editing && $draft && (int)$draft['id'] === (int)$editing['id'] && !isset($templates[$newTemplate])) {
            $editing = array_merge($editing, $draft);
        }
    }
    if (!$editing) {
        unset($_SESSION['section_draft']);
    }
?>

  <?php if ($editing): ?>
  <section class="card" id="editor">
    <h2><?= $editing['id'] ? 'Edit section' : 'New section' ?></h2>
    <?php if (input($_GET, 'show_preview') === '1'): ?>
      <div class="section-preview">
        <p class="hint">Preview (not saved yet)</p>
        <div class="card rich">
          <h2><?= h($editing['title'] !== '' ? $editing['title'] : 'Untitled') ?></h2>
          <?= render_markdown($editing['body']) ?>
        </div>
      </div>
    <?php endif; ?>
    <form method="post" class="email-form section-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <label>Title<input type="text" name="title" value="<?= h($editing['title']) ?>" placeholder="e.g. Where to stay" required></label>
      <label for="section-body">Text</label>
      <div class="md-toolbar" role="toolbar" aria-label="Formatting">
        <button type="button" data-md="bold" title="Bold"><strong>B</strong></button>
        <button type="button" data-md="italic" title="Italic"><em>I</em></button>
        <button type="button" data-md="heading" title="Subheading">Heading</button>
        <button type="button" data-md="list" title="Bullet list">&bull; List</button>
        <button type="button" data-md="link" title="Link">Link</button>
        <select data-md-size aria-label="Text size" title="Text size">
          <option value="">Text size…</option>
          <option value="small">Small</option>
          <option value="large">Large</option>
          <option value="xlarge">Extra large</option>
        </select>
      </div>
      <textarea id="section-body" name="body" rows="14" data-md-target><?= h($editing['body']) ?></textarea>
      <p class="hint">
        A blank line starts a new paragraph. <code>## </code> at the start of a line makes a subheading,
        <code>- </code> makes a bullet point, <code>**bold**</code>, <code>*italic*</code>,
        links look like <code>[Book a room](https://example.com)</code>, and
        <code>[large]text[/large]</code> changes the size (also <code>small</code> and <code>xlarge</code>).
      </p>
      <label class="check"><input type="checkbox" name="visible" value="1" <?= $editing['visible'] ? 'checked' : '' ?>> Show on the guest page</label>
      <div class="actions">
        <button type="submit" name="action" value="section_save">Save</button>
        <button type="submit" name="action" value="section_preview" class="secondary">Preview</button>
        <a class="link" href="/admin.php?tab=content">Cancel</a>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="row-between">
      <h2>Guest page sections</h2>
      <a class="link" href="/admin.php?tab=content&amp;section=new#editor">+ Blank section</a>
    </div>
    <p class="hint">These appear as cards under the RSVP on the guest page, in this order.</p>
    <?php if (!$sections): ?>
      <p>No sections yet.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="sections-table">
        <thead><tr><th>Section</th><th>Status</th><th>Last changed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sections as $i => $s): ?>
          <tr <?= $editing && (int)$editing['id'] === (int)$s['id'] ? 'class="editing"' : '' ?>>
            <td class="name-cell"><strong><?= h($s['title']) ?></strong></td>
            <td><span class="pill <?= $s['visible'] ? 'yes' : 'waiting' ?>"><?= $s['visible'] ? 'Shown' : 'Hidden' ?></span></td>
            <td><?= h(date('j M', strtotime($s['updated_at'] . ' UTC'))) ?><?= $s['updated_by'] ? ' by ' . h($s['updated_by']) : '' ?></td>
            <td class="row-actions">
              <a class="link small" href="/admin.php?tab=content&amp;section=<?= (int)$s['id'] ?>#editor">Edit</a>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="link small" name="action" value="section_toggle"><?= $s['visible'] ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="link small" name="action" value="section_move" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move <?= h($s['title']) ?> up">&uarr;</button>
              </form>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="link small" name="action" value="section_move" <?= $i === count($sections) - 1 ? 'disabled' : '' ?> aria-label="Move <?= h($s['title']) ?> down">&darr;</button>
              </form>
              <form method="post" class="inline" data-confirm="Delete &quot;<?= h($s['title']) ?>&quot;? This can't be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="link small danger" name="action" value="section_delete">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="add-section">Add a section:
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
    <h2>Setup link for <?= h($link['name']) ?></h2>
    <?php if (!empty($link['emailed'])): ?>
      <p>Emailed to <?= h($link['emailed']) ?>. Here it is too, in case it doesn't arrive.
         It works once and expires in <?= SETUP_LINK_DAYS ?> days, and isn't shown again.</p>
    <?php else: ?>
      <p>Send this to <?= h($link['name']) ?>. They open it, choose their own password, and are signed in.
         It works once and expires in <?= SETUP_LINK_DAYS ?> days. It isn't shown again, so copy it now.</p>
    <?php endif; ?>
    <p><code class="link-box"><?= h($link['url']) ?></code>
       <button type="button" class="secondary" data-copy="<?= h($link['url']) ?>">Copy link</button></p>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>People who can sign in</h2>
    <?php $editUser = find_user((int)input($_GET, 'edit_user')); if ($editUser): ?>
      <form method="post" class="edit-guest" id="edit-user">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
        <p class="edit-title">Editing <?= h($editUser['name']) ?> <span class="hint">(username <?= h($editUser['username']) ?> stays the same)</span></p>
        <label>Name<input type="text" name="name" value="<?= h($editUser['name']) ?>" required></label>
        <label>Email<input type="email" name="email" value="<?= h($editUser['email']) ?>" placeholder="For sign-in links"></label>
        <button type="submit" name="action" value="user_update">Save</button>
        <a class="link" href="/admin.php?tab=people">Cancel</a>
      </form>
    <?php endif; ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Status</th><th>Last signed in</th><th></th></tr></thead>
        <tbody>
          <tr>
            <td>You (owner)</td><td><code><?= OWNER_USERNAME ?></code></td><td>—</td>
            <td><span class="pill yes">Active</span></td><td>—</td>
            <td class="hint">Password set in config.php</td>
          </tr>
          <?php foreach ($users as $u):
              $pending = !$u['password_hash'];
              $expired = $pending && (!$u['setup_expires'] || $u['setup_expires'] < time()); ?>
          <tr>
            <td><?= h($u['name']) ?></td>
            <td><code><?= h($u['username']) ?></code></td>
            <td class="email-cell"><?= $u['email'] ? h($u['email']) : '—' ?></td>
            <td><span class="pill <?= $pending ? 'waiting' : 'yes' ?>"><?= $pending ? ($expired ? 'Link expired' : 'Waiting for setup') : 'Active' ?></span>
              <?php $keys = count(user_passkeys((int)$u['id'])); if ($keys): ?><span class="who"><?= $keys ?> passkey<?= $keys === 1 ? '' : 's' ?></span><?php endif; ?>
              <?php if ((int)($u['notify_feedback'] ?? 0) === 1 && valid_email($u['email'])): ?><span class="who">Gets feedback emails</span><?php endif; ?></td>
            <td><?= $u['last_login'] ? h(date('j M, H:i', strtotime($u['last_login'] . ' UTC'))) : '—' ?></td>
            <td class="row-actions">
              <a class="link small" href="/admin.php?tab=people&amp;edit_user=<?= (int)$u['id'] ?>#edit-user">Edit</a>
              <?php if (valid_email($u['email'])): ?>
              <form method="post" class="inline" data-confirm="Email <?= h($u['name']) ?> a new link at <?= h($u['email']) ?>?<?= $pending ? '' : ' Their current password will stop working.' ?>">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="send_email" value="1">
                <button class="link small" name="action" value="user_reset">Email <?= $pending ? 'setup' : 'reset' ?> link</button>
              </form>
              <?php endif; ?>
              <form method="post" class="inline" data-confirm="Create a new setup link for <?= h($u['name']) ?>? Their current password<?= user_passkeys((int)$u['id']) ? ' and passkeys' : '' ?> will stop working.">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="link small" name="action" value="user_reset"><?= $pending ? 'New setup link' : 'Reset password' ?></button>
              </form>
              <form method="post" class="inline" data-confirm="Remove <?= h($u['name']) ?>? They won't be able to sign in.">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="link small danger" name="action" value="user_remove">Remove</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <h2>Add a person</h2>
    <form method="post" class="email-form">
      <?= csrf_field() ?>
      <div class="field-row">
        <label>Name<input type="text" name="name" placeholder="Sam" required></label>
        <label>Username<input type="text" name="username" placeholder="sam" autocapitalize="none" spellcheck="false" required></label>
        <label>Email<input type="email" name="email" placeholder="sam@example.com"></label>
      </div>
      <label class="check"><input type="checkbox" name="send_email" value="1" checked> Email them the setup link</label>
      <p class="hint">They can do everything in admin except manage logins. With an email address they can also reset their own password from the sign-in page.</p>
      <button type="submit" name="action" value="user_add">Add person</button>
    </form>
  </section>

<?php elseif ($tab === 'account'): $mine = user_passkeys((int)$me['id']); $origin = passkey_origin(); ?>

  <h2 class="page-title">Your account</h2>
  <?php $contact = admin_contact((int)$me['id']); ?>
  <section class="card">
    <h2>Email and notifications</h2>
    <form method="post" class="email-form account-contact">
      <?= csrf_field() ?>
      <label>Your email address<input type="email" name="email" value="<?= h($contact['email']) ?>" placeholder="you@example.com" autocomplete="email"></label>
      <?php if ($me['id']): ?>
        <label>Current password <span class="hint-inline">(only needed to change your email address)</span>
          <input type="password" name="current_password" autocomplete="current-password"></label>
        <p class="hint">Also used if you ever need to reset your password.</p>
      <?php else: ?>
        <p class="hint">Only used for the emails below. Your password is set in config.php.</p>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="notify_feedback" value="1" <?= $contact['notify'] ? 'checked' : '' ?>>
        Email me when a guest sends a message</label>
      <p class="hint">You'll get their name and message, with a link to the Feedback tab. If their invitation has an email
        address, replying to the email goes straight to them.</p>
      <button type="submit" name="action" value="account_contact">Save</button>
    </form>
  </section>

  <section class="card">
    <h2>Passkeys</h2>
    <p class="hint">A passkey lets you sign in with Face ID, a fingerprint or your device's PIN instead of typing your
      password, and it can't be stolen by a fake site. Once you've added one, <strong>you'll need it every time you sign in</strong>:
      on its own, or after your password. Add one on each device you use, or use a password manager that syncs them.</p>
    <?php if (is_owner() && PASSKEY_RECOVERY): ?>
      <p class="notice" role="status">PASSKEY_RECOVERY is on in config.php, so the owner can sign in with just the password.
        Once you've added a new passkey, set it back to <code>false</code>.</p>
    <?php endif; ?>
    <?php if ($mine): ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Passkey</th><th>Added</th><th>Last used</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($mine as $p): ?>
            <tr>
              <td><?= h($p['name']) ?></td>
              <td><?= h(date('j M Y', strtotime($p['created_at'] . ' UTC'))) ?></td>
              <td><?= $p['last_used_at'] ? h(date('j M, H:i', strtotime($p['last_used_at'] . ' UTC'))) : '—' ?></td>
              <td>
                <form method="post" class="inline" data-confirm="Remove the passkey &quot;<?= h($p['name']) ?>&quot;?<?= count($mine) === 1 ? ' It\'s your only one, so you\'ll be able to sign in with just your password again.' : '' ?>">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="link small danger" name="action" value="passkey_remove">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p>You don't have a passkey yet, so you sign in with your password only.</p>
    <?php endif; ?>

    <?php if (!$origin): ?>
      <p class="hint">Passkeys can only be added on the live site, <?= h(SITE_URL) ?>.</p>
    <?php else: ?>
      <form method="post" class="passkey-add">
        <?= csrf_field() ?>
        <label for="passkey-name">Name it, so you can tell your passkeys apart</label>
        <div class="csv-row">
          <input id="passkey-name" name="passkey_name" type="text" maxlength="60" value="<?= $mine ? '' : 'My phone' ?>" placeholder="e.g. Work laptop">
          <button type="button" data-passkey="register" hidden>Add a passkey</button>
        </div>
        <p class="error" data-passkey-unsupported hidden>This browser can't create passkeys. Try Safari, Chrome, Edge or Firefox on an up-to-date device.</p>
        <p class="error" role="alert" data-passkey-message hidden></p>
      </form>
    <?php endif; ?>
  </section>

<?php endif; ?>
</main>
<?php if ($tab === 'account'): ?><script src="<?= h(asset_url('/assets/passkey.js')) ?>"></script><?php endif; ?>
<script src="<?= h(asset_url('/assets/admin.js')) ?>"></script>
<?php page_footer();
