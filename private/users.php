<?php
// Admin logins. The owner signs in as "admin" with ADMIN_PASSWORD from
// config.php. Other people (e.g. your partner) are added on the People tab: they get
// a one-time setup link and choose their own password, which is stored only
// as a bcrypt hash.

const OWNER_USERNAME = 'admin';
const SETUP_LINK_DAYS = 7;
const MIN_PASSWORD_LENGTH = 12;

function users_db(): PDO
{
    static $ready = false;
    $pdo = db();
    if (!$ready) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT,
            setup_hash TEXT,
            setup_expires INTEGER,
            last_login TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('email', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
        }
        if (!in_array('session_key', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN session_key TEXT');   // see admin_session_key()
        }
        if (!in_array('language', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN language TEXT');   // their admin language; NULL = the site's
        }
        if (!in_array('notify_feedback', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN notify_feedback INTEGER NOT NULL DEFAULT 0');   // 1 = email them guests' messages
        }
        $ready = true;
    }
    return $pdo;
}

function all_users(): array
{
    return users_db()->query('SELECT * FROM users ORDER BY name COLLATE NOCASE')->fetchAll();
}

function normalise_username(string $username): string
{
    return strtolower(trim($username));
}

function valid_username(string $username): bool
{
    return (bool)preg_match('/^[a-z0-9._-]{2,30}$/', $username) && $username !== OWNER_USERNAME;
}

// Returns ['id' => …, 'name' => …, 'owner' => bool] or null.
function check_login(string $username, string $password): ?array
{
    $username = normalise_username($username);
    if ($username === OWNER_USERNAME) {
        return ADMIN_PASSWORD !== 'change-me-please' && hash_equals(hash('sha256', ADMIN_PASSWORD), hash('sha256', $password))
            ? ['id' => 0, 'name' => 'Admin', 'owner' => true]
            : null;
    }
    $q = users_db()->prepare('SELECT * FROM users WHERE username = ?');
    $q->execute([$username]);
    $user = $q->fetch();
    if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        // Same work either way, so timing doesn't reveal which usernames exist.
        // (A throwaway hash of random data, not anyone's password.)
        if (!$user || !$user['password_hash']) {
            password_verify($password, '$2y$12$iFX0CU9xMNgg2t/3A2ro9eaMIRiIXAagvzhN3ZcgX7Qfz47x1fsc6');
        }
        return null;
    }
    users_db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]);
    return ['id' => (int)$user['id'], 'name' => $user['name'], 'owner' => false];
}

function sign_in(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']);   // a fresh form token for the signed-in session
    $_SESSION['is_admin'] = true;
    $_SESSION['admin_seen'] = $_SESSION['admin_since'] = time();
    $_SESSION['admin_user'] = $user;
    $_SESSION['admin_key'] = admin_session_key((int)$user['id']);
}

// Someone who has given the right password but still needs to confirm with
// their passkey (for a few minutes), or null.
function pending_admin(): ?array
{
    $p = $_SESSION['pending_admin'] ?? null;
    if (!is_array($p) || ($p['until'] ?? 0) < time() || !is_array($p['user'] ?? null)) {
        unset($_SESSION['pending_admin']);
        return null;
    }
    return $p['user'];
}

// The person a passkey belongs to, ready for sign_in(), or null if they can
// no longer sign in (removed, or waiting to choose a new password).
function admin_by_id(int $id): ?array
{
    if ($id === 0) {
        return ['id' => 0, 'name' => 'Admin', 'owner' => true];
    }
    $q = users_db()->prepare('SELECT * FROM users WHERE id = ? AND password_hash IS NOT NULL');
    $q->execute([$id]);
    $user = $q->fetch();
    if (!$user) {
        return null;
    }
    users_db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
    return ['id' => (int)$user['id'], 'name' => $user['name'], 'owner' => false];
}

// The signed-in person: ['id' => …, 'name' => …, 'owner' => bool]. A session
// without one counts as nobody, never as the owner.
function current_admin(): array
{
    $user = $_SESSION['admin_user'] ?? null;
    return is_array($user) ? $user : ['id' => -1, 'name' => '', 'owner' => false];
}

// A random key that changes whenever someone's password does. Sign-in stores
// it in the session and admin.php checks it on every request, so changing a
// password (a reset link, a new password, or a new ADMIN_PASSWORD in
// config.php) signs out every session that used the old one.
function admin_session_key(int $id): string
{
    if ($id === 0) {
        // The owner's password lives in config.php. Notice when it changes by
        // keeping a keyed fingerprint of it (never the password) in the database.
        $secret = setting('owner_secret');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            save_setting('owner_secret', $secret);
        }
        $fingerprint = hash_hmac('sha256', ADMIN_PASSWORD, $secret);
        if (!hash_equals(setting('owner_fingerprint'), $fingerprint) || setting('owner_session_key') === '') {
            save_setting('owner_fingerprint', $fingerprint);
            save_setting('owner_session_key', bin2hex(random_bytes(16)));
        }
        return setting('owner_session_key');
    }
    $q = users_db()->prepare('SELECT session_key FROM users WHERE id = ? AND password_hash IS NOT NULL');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) {
        return '';   // removed, or waiting to choose a new password
    }
    return (string)$row['session_key'] !== '' ? (string)$row['session_key'] : new_session_key($id);
}

// Gives someone a new session key, signing out their existing sessions.
function new_session_key(int $id): string
{
    $key = bin2hex(random_bytes(16));
    users_db()->prepare('UPDATE users SET session_key = ? WHERE id = ?')->execute([$key, $id]);
    return $key;
}

// True while the session's person still exists with the same password, and
// it's within the time limits (2 hours idle, checked by is_admin(), and 12
// hours in all).
function admin_session_valid(): bool
{
    $me = current_admin();
    if ($me['id'] < 0 || time() - (int)($_SESSION['admin_since'] ?? 0) > 12 * 60 * 60) {
        return false;
    }
    $key = admin_session_key((int)$me['id']);
    return $key !== '' && hash_equals($key, (string)($_SESSION['admin_key'] ?? ''));
}

function is_owner(): bool
{
    return !empty(current_admin()['owner']);
}

// Creates (or replaces) a setup link for a user and returns its URL.
// Only a hash of the token is stored, so the link can't be recovered later.
function new_setup_link(int $userId): string
{
    $token = bin2hex(random_bytes(24));
    users_db()->prepare('UPDATE users SET setup_hash = ?, setup_expires = ? WHERE id = ?')
        ->execute([hash('sha256', $token), time() + SETUP_LINK_DAYS * 86400, $userId]);
    return SITE_URL . '/admin.php?setup=' . $token;
}

function user_for_setup_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
        return null;
    }
    $q = users_db()->prepare('SELECT * FROM users WHERE setup_hash = ? AND setup_expires > ?');
    $q->execute([hash('sha256', $token), time()]);
    return $q->fetch() ?: null;
}

function set_password(int $userId, string $password): void
{
    users_db()->prepare('UPDATE users SET password_hash = ?, setup_hash = NULL, setup_expires = NULL, session_key = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), bin2hex(random_bytes(16)), $userId]);
}

function find_user(int $id): ?array
{
    $q = users_db()->prepare('SELECT * FROM users WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

// True if a setup/reset link was created for this person in the last few
// minutes, so "Forgot password?" can't be used to flood their inbox.
function setup_link_recent(array $user, int $minutes = 5): bool
{
    $created = (int)$user['setup_expires'] - SETUP_LINK_DAYS * 86400;
    return $user['setup_expires'] && time() - $created < $minutes * 60;
}

// For "Forgot password?": match a username or an email address.
function find_user_by_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $q = users_db()->prepare('SELECT * FROM users WHERE username = ? OR lower(email) = lower(?) LIMIT 1');
    $q->execute([normalise_username($login), $login]);
    return $q->fetch() ?: null;
}

// Emails a setup or password-reset link. Needs mailer.php and a valid
// From address on the Invitation email tab.
function send_setup_email(array $user, string $url, bool $reset): bool
{
    if (!valid_email($user['email'] ?? null)) {
        return false;
    }
    $language = valid_language($user['language'] ?? null) ? $user['language'] : site_language();
    [$subject, $text, $html] = in_language($language, fn() => render_setup_email($user, $url, $reset));
    return send_email($user['email'], $user['name'], $subject, $text, $html, mail_settings());
}

// The setup or reset email in the current language: [subject, text, html].
function render_setup_email(array $user, string $url, bool $reset): array
{
    $subject = t('admin_mail.link_subject', ['couple' => COUPLE_NAMES]);
    $expires = t('admin_mail.expires', ['count' => SETUP_LINK_DAYS]) . ' ' . t('admin_mail.ignore');
    $text = t('admin_mail.hi', ['name' => $user['name']]) . "\n\n"
        . t($reset ? 'admin_mail.reset_text' : 'admin_mail.setup_text') . "\n\n$url\n\n"
        . t('admin_mail.username', ['username' => $user['username']]) . "\n\n" . $expires . "\n";
    $html = '<!doctype html><html lang="' . h(current_language()) . '" dir="' . (is_rtl() ? 'rtl' : 'ltr') . '"><body style="margin:0;padding:24px 12px;background:#f4f4f5">'
        . '<div style="max-width:520px;margin:0 auto;padding:28px;background:#ffffff;border-radius:12px;font:16px/1.6 ' . email_font() . ';color:#18181b">'
        . '<p style="margin:0 0 16px">' . th('admin_mail.hi', ['name' => $user['name']]) . '</p>'
        . '<p style="margin:0 0 20px">' . th($reset ? 'admin_mail.reset_html' : 'admin_mail.setup_html') . '</p>'
        . '<p style="margin:0 0 20px"><a href="' . h($url) . '" style="display:inline-block;padding:13px 26px;background:#52525b;'
        . 'color:#ffffff;text-decoration:none;border-radius:8px;font-weight:500">' . th($reset ? 'admin_mail.reset_button' : 'admin_mail.setup_button') . '</a></p>'
        . '<p style="margin:0 0 16px">' . t_html('admin_mail.username', ['username' => '<strong dir="ltr">' . h($user['username']) . '</strong>']) . '</p>'
        . '<p style="margin:0;font-size:13px;color:#6b6b73">' . h($expires) . '</p>'
        . '</div></body></html>';
    return [$subject, $text, $html];
}


// --- Email address and feedback emails ---------------------------------
// Everyone in admin can give an email address and choose to be emailed when
// a guest sends a message. Other admins' addresses live in the users table
// (they're also used for password resets); the owner's, which isn't, in
// settings.

// ['email' => …, 'notify' => bool] for one admin (0 = the owner).
function admin_contact(int $id): array
{
    if ($id === 0) {
        return ['email' => setting('owner_email'), 'notify' => setting('owner_notify_feedback') === '1'];
    }
    $q = users_db()->prepare('SELECT email, notify_feedback FROM users WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch() ?: [];
    return ['email' => (string)($row['email'] ?? ''), 'notify' => (int)($row['notify_feedback'] ?? 0) === 1];
}

function save_admin_contact(int $id, string $email, bool $notify): void
{
    if ($id === 0) {
        save_setting('owner_email', $email);
        save_setting('owner_notify_feedback', $notify ? '1' : '0');
        return;
    }
    users_db()->prepare('UPDATE users SET email = ?, notify_feedback = ? WHERE id = ?')
        ->execute([$email !== '' ? $email : null, $notify ? 1 : 0, $id]);
}

// An admin's language (0 = the owner): what they chose, or the site's.
function admin_language(int $id): string
{
    if ($id === 0) {
        $code = setting('owner_language');
    } else {
        $q = users_db()->prepare('SELECT language FROM users WHERE id = ?');
        $q->execute([$id]);
        $code = $q->fetchColumn();
    }
    return valid_language(is_string($code) ? $code : null) ? $code : site_language();
}

function save_admin_language(int $id, string $code): void
{
    $code = valid_language($code) ? $code : '';
    if ($id === 0) {
        save_setting('owner_language', $code);
        return;
    }
    users_db()->prepare('UPDATE users SET language = ? WHERE id = ?')->execute([$code !== '' ? $code : null, $id]);
}

// Who to email when a guest sends a message: [['name' => …, 'email' => …, 'language' => …], …].
// Only people who can still sign in, have opted in and have a valid address.
function feedback_recipients(): array
{
    $people = [];
    $owner = admin_contact(0);
    if ($owner['notify'] && valid_email($owner['email'])) {
        $people[] = ['name' => COUPLE_NAMES, 'email' => $owner['email'], 'language' => admin_language(0)];
    }
    $q = users_db()->query('SELECT id, name, email FROM users WHERE notify_feedback = 1 AND password_hash IS NOT NULL');
    foreach ($q->fetchAll() as $u) {
        if (valid_email($u['email'])) {
            $people[] = ['name' => $u['name'], 'email' => $u['email'], 'language' => admin_language((int)$u['id'])];
        }
    }
    // One email per address, even if two people share one.
    $unique = [];
    foreach ($people as $p) {
        $unique[strtolower($p['email'])] = $unique[strtolower($p['email'])] ?? $p;
    }
    return array_values($unique);
}

// True if $password is this admin's current password (bcrypt, constant time).
function password_matches(int $id, string $password): bool
{
    $q = users_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $q->execute([$id]);
    $hash = (string)$q->fetchColumn();
    return $hash !== '' && password_verify($password, $hash);
}
