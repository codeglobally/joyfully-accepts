<?php
// Saves a language chosen from the footer menu: in a cookie (for the code
// page, before signing in) and, for a signed-in guest, on their invitation.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}
check_csrf();
$code = input($_POST, 'lang');
if (valid_language($code)) {
    remember_language_cookie($code);
    $guest = current_guest();
    if ($guest) {
        db()->prepare("UPDATE guests SET language = ?, language_set_by = 'guest' WHERE id = ?")->execute([$code, $guest['id']]);
    }
}
// Back to the page they were on: only a path on this site.
$back = input($_POST, 'back');
header('Location: ' . (preg_match('#^/(?![/\\\\])[A-Za-z0-9._/-]*$#', $back) ? $back : '/'));
