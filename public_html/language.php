<?php
// Saves a language chosen from the language menu (or turns down the
// suggestion to switch): in a cookie (for the code
// page, before signing in) and, for a signed-in guest, on their invitation.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}
check_csrf();
$code = offered_language(input($_POST, 'lang'));
$dismiss = offered_language(input($_POST, 'dismiss'));
if ($dismiss !== null) {
    // "No thanks" to switching: don't suggest this language again here.
    setcookie(LANGUAGE_HINT_COOKIE, $dismiss, ['expires' => time() + 365 * 86400, 'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true, 'samesite' => 'Lax']);
} elseif ($code !== null) {
    remember_language_cookie($code);
    $guest = current_guest();
    if ($guest) {
        db()->prepare("UPDATE guests SET language = ?, language_set_by = 'guest' WHERE id = ?")->execute([$code, $guest['id']]);
    }
}
// Back to the page they were on: only a path on this site.
header('Location: ' . back_path(input($_POST, 'back')));
