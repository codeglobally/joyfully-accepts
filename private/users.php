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
        return ADMIN_PASSWORD !== 'change-me-please' && hash_equals(ADMIN_PASSWORD, $password)
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
    $_SESSION['is_admin'] = true;
    $_SESSION['admin_seen'] = time();
    $_SESSION['admin_user'] = $user;
}

function current_admin(): array
{
    return $_SESSION['admin_user'] ?? ['id' => 0, 'name' => 'Admin', 'owner' => true];
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
    users_db()->prepare('UPDATE users SET password_hash = ?, setup_hash = NULL, setup_expires = NULL WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
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
    $what = $reset ? 'reset your password' : 'set up your sign-in';
    $subject = 'Your ' . COUPLE_NAMES . ' admin link';
    $text = "Hi {$user['name']},\n\n"
        . "Use this link to $what for the wedding website admin:\n\n$url\n\n"
        . "Your username is: {$user['username']}\n\n"
        . 'The link works once and expires in ' . SETUP_LINK_DAYS . " days. If you didn't ask for this, you can ignore this email.\n";
    $font = "'Helvetica Neue',Helvetica,Arial,sans-serif";
    $html = '<!doctype html><html><body style="margin:0;padding:24px 12px;background:#f4f4f5">'
        . '<div style="max-width:520px;margin:0 auto;padding:28px;background:#ffffff;border-radius:12px;font:16px/1.6 ' . $font . ';color:#18181b">'
        . '<p style="margin:0 0 16px">Hi ' . h($user['name']) . ',</p>'
        . '<p style="margin:0 0 20px">Use this button to ' . $what . ' for the wedding website admin.</p>'
        . '<p style="margin:0 0 20px"><a href="' . h($url) . '" style="display:inline-block;padding:13px 26px;background:#52525b;'
        . 'color:#ffffff;text-decoration:none;border-radius:8px;font-weight:500">' . ($reset ? 'Reset password' : 'Set up sign-in') . '</a></p>'
        . '<p style="margin:0 0 16px">Your username is <strong>' . h($user['username']) . '</strong>.</p>'
        . '<p style="margin:0;font-size:13px;color:#6b6b73">The link works once and expires in ' . SETUP_LINK_DAYS
        . " days. If you didn't ask for this, you can ignore this email.</p>"
        . '</div></body></html>';
    return send_email($user['email'], $user['name'], $subject, $text, $html, mail_settings());
}

