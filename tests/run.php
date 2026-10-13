<?php
// Automated tests. No dependencies beyond PHP itself.
//
//   php tests/run.php                       unit tests
//   php tests/run.php --http=http://host    also end-to-end checks against a
//                                           site running with tests/config.php
//
// Exits with status 1 if anything fails (so CI marks the run as failed).

putenv('WEDDING_CONFIG=' . __DIR__ . '/config.php');
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__) . '/public_html';
ob_start();   // lib.php sends headers; keep CLI output clean
require dirname(__DIR__) . '/private/lib.php';
foreach (['content', 'mailer', 'notify', 'users', 'insights', 'calendar'] as $module) {
    require APP_DIR . "/$module.php";
}
ob_end_clean();

$passed = 0;
$failed = [];
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
    } else {
        $failed[] = $name . ($detail !== '' ? " — $detail" : '');
    }
}
function same(string $name, $expected, $actual): void
{
    check($name, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}
function section(string $title): void
{
    echo "· $title\n";
}

// --- Guests and invitations ------------------------------------------

section('Invitation names → people');
same('single person', ['Uncle Bob'], party_members('Uncle Bob'));
same('couple with &', ['Sarah', 'Tom Jones'], party_members('Sarah & Tom Jones'));
same('list with and', ['Sarah', 'Tom', 'Lily'], party_members('Sarah, Tom and Lily'));
same('titles borrow surname', ['Mr Smith', 'Mrs Smith'], party_members('Mr & Mrs Smith'));
same('plus-one', ['Bob', 'Guest'], party_members('Bob + guest'));
same('plus one in words', ['Bob', 'Guest'], party_members('Bob plus one'));
same('family is unknown size', [], party_members("The O'Brien Family"));
same('"and" inside a word is not split', ['Alexandra Sanderson'], party_members('Alexandra Sanderson'));

section('Add-invitations lines');
same('semicolon separates email', ['Sarah, Tom and Lily', 'sarah@example.com'], split_guest_line('Sarah, Tom and Lily; sarah@example.com'));
same('commas stay in the name', ['Sarah, Tom and Lily', ''], split_guest_line('Sarah, Tom and Lily'));
same('old comma style with @', ['Mr & Mrs Smith', 'smiths@example.com'], split_guest_line('Mr & Mrs Smith, smiths@example.com'));
same('whitespace trimmed', ['Bob', 'bob@example.com'], split_guest_line('  Bob ;  bob@example.com '));
same('a couple with an address each', ['Ali & Jo Khan', 'ali@example.com, jo@example.com'], split_guest_line('Ali & Jo Khan; ali@example.com; jo@example.com'));
same('commas in the name, two addresses', ['Sarah, Tom and Lily', 'sarah@example.com, tom@example.com'],
    split_guest_line('Sarah, Tom and Lily; sarah@example.com, tom@example.com'));
same('old comma style, two addresses', ['Mr & Mrs Smith', 'a@example.com, b@example.com'], split_guest_line('Mr & Mrs Smith, a@example.com, b@example.com'));
same('non-address after a semicolon kept (so it gets flagged)', ['Bob', 'not-an-email'], split_guest_line('Bob; not-an-email'));

section('Uploading a CSV');
same('headings, two email columns, notes ignored', [['Ali & Jo Khan', 'ali@example.com, jo@example.com'], ['Uncle Bob', '']],
    parse_guest_csv("Name,Email,Email 2,Notes\nAli & Jo Khan,ali@example.com,jo@example.com,Vegan\nUncle Bob,,,\n"));
same('headings in another order', [['Gran', 'gran@example.com']],
    parse_guest_csv("Email address,Phone,Full name\ngran@example.com,07700 900000,Gran\n"));
same('Excel: semicolons and byte-order mark', [['Sarah Jones', 'sarah@example.com']],
    parse_guest_csv("\xEF\xBB\xBFName;Email\r\nSarah Jones;sarah@example.com\r\n"));
same('no headings: quoted name with commas, then addresses', [['Sarah, Tom and Lily', 'sarah@example.com, tom@example.com'], ['Uncle Bob', '']],
    parse_guest_csv("\"Sarah, Tom and Lily\",sarah@example.com,tom@example.com\nUncle Bob\n"));
same('no headings: other cells without an @ ignored', [['Priya Shah', 'priya@example.com']],
    parse_guest_csv("Priya Shah,Table 4,priya@example.com\n"));
same('tab-separated', [['Grace Kim', 'grace@example.com']], parse_guest_csv("Name\tEmail\nGrace Kim\tgrace@example.com\n"));
same('a wrong address is kept so it can be flagged', [['Bob', 'not-an-email']], parse_guest_csv("Name,Email\nBob,not-an-email\n"));
same('blank rows and blank names skipped', [['Ken', '']], parse_guest_csv("Name,Email\n\n,lost@example.com\nKen,\n"));
if (function_exists('mb_convert_encoding')) {
    same('older Excel encoding converted', [['Zoë', 'zoe@example.com']], parse_guest_csv("Name,Email\nZo\xEB,zoe@example.com\n"));
}

section('Invitation codes');
$code = generate_code();
check('8 characters', strlen($code) === 8);
check('no look-alike characters (0 O 1 I L)', !preg_match('/[01OIL]/', $code));
same('codes normalised for entry', 'K7MPX4QR', normalise_code(' k7mp-x4qr '));
same('symbols stripped', 'ABCD1234', normalise_code("ABCD'1234\";"));
check('invite link contains code', invite_link('K7MPX4QR') === 'https://example.com/?code=K7MPX4QR');

// --- Safety -------------------------------------------------------------

section('Formatting is safe');
same('bold, italic, link', '<p><strong>Hi</strong> <em>there</em> <a href="https://example.com" target="_blank" rel="noopener">site</a></p>',
    render_markdown('**Hi** *there* [site](https://example.com)'));
check('script tags escaped', strpos(render_markdown('<script>alert(1)</script>'), '<script>') === false);
check('img onerror escaped', strpos(render_markdown('<img src=x onerror=alert(1)>'), '<img') === false);
check('javascript: links refused', strpos(render_markdown('[x](javascript:alert(1))'), '<a ') === false);
check('data: links refused', strpos(render_markdown('[x](data:text/html,hi)'), '<a ') === false);
check('quotes cannot break out of href', !preg_match('/href="[^"]*"[^>]*onmouseover/', render_markdown('[x](https://e.com"onmouseover=alert(1))')));
same('headings and lists', '<h3>Hotels</h3><ul><li>One</li><li>Two</li></ul>', render_markdown("## Hotels\n- One\n- Two"));
same('text sizes', '<p><span class="text-large">Big</span></p>', render_markdown('[large]Big[/large]'));
same('unknown size left as text', '<p>[huge]x[/huge]</p>', render_markdown('[huge]x[/huge]'));
same('h() escapes quotes', '&quot;&#039;&lt;&gt;&amp;', h('"\'<>&'));

section('CSV export is formula-safe');
same('formula neutralised', "'=HYPERLINK(1)", csv_cell('=HYPERLINK(1)'));
same('plus neutralised', "'+1", csv_cell('+1'));
same('normal text unchanged', 'Sarah & Tom', csv_cell('Sarah & Tom'));

section('Input type confusion');
same('array input becomes empty string', '', input(['code' => ['x']], 'code'));
same('missing input is empty string', '', input([], 'code'));

section('Email');
check('valid address accepted', valid_email('alex@example.com'));
check('header injection rejected', !valid_email("alex@example.com\r\nBcc: x@example.com"));
check('two addresses rejected', !valid_email('a@example.com, b@example.com'));
same('addresses split on commas, semicolons and spaces', ['a@example.com', 'b@example.com', 'c@example.com'], email_list(' a@example.com;b@example.com , c@example.com '));
same('addresses tidied and repeats dropped', 'a@example.com, b@example.com', clean_emails('a@example.com; b@example.com, a@example.com'));
same('only good addresses are emailed', ['a@example.com'], sendable_emails('a@example.com, not-an-email'));
check('a bad address is noticed', has_bad_email('a@example.com, not-an-email') && !has_bad_email('a@example.com, b@example.com') && !has_bad_email(''));
check('no line breaks reach a mail header', !preg_grep('/[\r\n]/', sendable_emails("a@example.com\r\nBcc: x@example.com")));
same('header encoding', '=?UTF-8?B?' . base64_encode('Zoë') . '?=', encode_header('Zoë'));

// --- Dietary, RSVP and insights ---------------------------------------

section('Dietary choices');
same('cleaned, deduped, commas removed', ['Vegan', 'Nut allergy here'], clean_dietary_picks(['Vegan', 'Vegan', 'Nut, allergy here', ['nested']]));
check('at most 12 kept', count(clean_dietary_picks(array_map(fn($i) => "item $i", range(1, 20)))) === 12);
check('60 characters max', mb_strlen(clean_dietary_picks([str_repeat('é', 100)])[0]) === 60);

$guest = ['name' => 'Sarah & Tom Jones', 'attending' => RSVP_YES, 'attendees' => json_encode(['Sarah']), 'party_size' => null,
    'dietary' => json_encode([['name' => 'Sarah', 'needs' => 'Vegan'], ['name' => 'Tom Jones', 'needs' => 'Gluten-free']])];
same('only people coming count', ['names' => ['Sarah'], 'count' => 1, 'known' => true], attendees($guest));
same('dietary summary skips people not coming', 'Sarah: Vegan', dietary_summary($guest));
same('status: not sure yet', 'maybe', rsvp_status(['attending' => RSVP_MAYBE]));
same('status: no reply', 'waiting', rsvp_status(['attending' => null]));
$family = ['name' => 'The Okafor Family', 'attending' => RSVP_YES, 'attendees' => null, 'party_size' => 4, 'dietary' => null];
same('family party size', 4, attendees($family)['count']);
check('allergies flagged as medical', is_medical_need('Peanut allergy (EpiPen)') && is_medical_need('Coeliac') && !is_medical_need('Vegetarian'));
$h = headcount([$guest, $family, ['name' => 'Uncle Ken', 'attending' => null], ['name' => 'Jo & Ann', 'attending' => RSVP_MAYBE]]);
same('headcount', [5, 2, 1], [$h['confirmed'], $h['maybe'], $h['no_reply']]);

// --- Calendar ------------------------------------------------------------

section('Calendar file');
$ics = wedding_ics('https://example.com/?code=K7MPX4QR');
check('all-day event on the right date', strpos($ics, "DTSTART;VALUE=DATE:20280909\r\n") !== false && strpos($ics, "DTEND;VALUE=DATE:20280910\r\n") !== false);
check('two reminders', substr_count($ics, 'BEGIN:VALARM') === 2);
check('commas escaped', strpos($ics, 'The Old Barn\, Example Lane') !== false);
check('lines at most 75 octets', max(array_map('strlen', explode("\r\n", $ics))) <= 75);
check('web links carry no invitation code', strpos(google_calendar_link() . outlook_calendar_link(), 'K7MPX4QR') === false);

// --- Admin logins ----------------------------------------------------------

section('Admin logins');
check('owner signs in with config password', (check_login('ADMIN', 'test-admin-password-123')['owner'] ?? false) === true);
check('wrong password refused', check_login('admin', 'nope') === null);
check('"admin" cannot be taken by others', !valid_username('admin'));
users_db()->prepare('INSERT INTO users (name, username, email) VALUES (?, ?, ?)')->execute(['Sam', 'sam', 'sam@example.com']);
$sam = find_user_by_login('SAM@example.com');
$link = new_setup_link((int)$sam['id']);
$token = substr($link, strpos($link, 'setup=') + 6);
check('setup link stored only as a hash', users_db()->query('SELECT setup_hash FROM users WHERE id = ' . (int)$sam['id'])->fetchColumn() === hash('sha256', $token));
check('setup token finds the person', (user_for_setup_token($token)['username'] ?? '') === 'sam');
set_password((int)$sam['id'], 'a-long-password-here');
check('setup link works once', user_for_setup_token($token) === null);
check('new password works', (check_login('sam', 'a-long-password-here')['name'] ?? '') === 'Sam');
check('password stored hashed', strpos((string)users_db()->query("SELECT password_hash FROM users WHERE username = 'sam'")->fetchColumn(), '$2y$') === 0);

// --- End-to-end (optional) ---------------------------------------------------

foreach ($argv as $arg) {
    if (strpos($arg, '--http=') !== 0) {
        continue;
    }
    $base = rtrim(substr($arg, 7), '/');
    section("End-to-end against $base");
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $get = function (string $path, ?array $post = null, array $opts = []) use ($base, $jar) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false] + $opts);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string)curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $r = ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
        return $r;
    };
    $csrf = fn($body) => preg_match('/name="csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';

    $home = $get('/');
    check('home page loads', $home['status'] === 200 && strpos($home['body'], 'Alex &amp; Sam') !== false);
    check('date kept off the public home page', strpos($home['body'], WEDDING_DATE) === false);
    check('Content-Security-Policy sent', stripos($home['headers'], "content-security-policy: default-src 'self'") !== false);
    check('framing blocked', stripos($home['headers'], 'x-frame-options: deny') !== false);
    $missing = $get('/404.php');
    check('not-found page says 404 with a way back', $missing['status'] === 404 && strpos($missing['body'], 'Page not found') !== false && strpos($missing['body'], 'class="button" href="/"') !== false);
    $lost = $get('/no-such-page.txt');
    check('missing files get the 404 page with security headers', $lost['status'] === 404 && strpos($lost['body'], 'Page not found') !== false && stripos($lost['headers'], "content-security-policy: default-src 'self'") !== false);
    $plain = $get('/assets/style.css')['headers'];
    check('plain files sent with nosniff', stripos($plain, 'x-content-type-options: nosniff') !== false);
    check('plain files don\'t reveal the PHP version', stripos($plain, 'x-powered-by') === false);
    check('not-found page has no admin link', strpos($missing['body'], '/admin.php') === false && strpos($home['body'], 'href="/admin.php"') !== false);
    check('unused HTTP methods refused', $get('/', null, [CURLOPT_CUSTOMREQUEST => 'DELETE'])['status'] === 405);
    check('PHP version not advertised', stripos($home['headers'], 'x-powered-by') === false);
    check('unused browser features turned off', stripos($home['headers'], 'permissions-policy: camera=()') !== false);
    check('cross-origin isolation headers sent', stripos($home['headers'], 'cross-origin-opener-policy: same-origin') !== false);
    check('session cookie is HttpOnly and SameSite', (bool)preg_match('/^set-cookie: PHPSESSID=[^\r\n]*HttpOnly; SameSite=Lax/mi', $home['headers']));
    $theme = $get('/theme.css.php');
    check('theme stylesheet served as CSS', stripos($theme['headers'], 'content-type: text/css') !== false);
    check('cacheable stylesheet sets no cookie', stripos($theme['headers'], 'set-cookie') === false);
    check('photo preloaded as WebP', (bool)preg_match('#<link rel="preload" as="image"[^>]+\.webp\?v=\d+" type="image/webp">#', $home['body']));
    check('WebP photo offered with a JPEG fallback', strpos($theme['body'], "type('image/webp'), url('/assets/sample-photo.jpg?v=") !== false);
    check('stylesheet address carries its file time', (bool)preg_match('#/assets/style\.css\?v=\d{9,}"#', $home['body']));
    check('hidden photo link rendered', strpos($home['body'], 'photo-hotspot-0') !== false);

    $code = db()->query('SELECT code FROM guests LIMIT 1')->fetchColumn();
    if (!$code) {
        $code = generate_code();
        db()->prepare('INSERT INTO guests (code, name, email) VALUES (?, ?, ?)')->execute([$code, 'Sarah & Tom Jones', 'sarah@example.com']);
    }
    check('forged code form rejected', $get('/', ['code' => $code])['status'] === 400);
    $wrong = $get('/', ['csrf' => $csrf($home['body']), 'code' => 'ZZZZZZZZ']);
    check('wrong code refused', strpos($wrong['body'], "doesn&#039;t match") !== false);
    $right = $get('/', ['csrf' => $csrf($wrong['body']), 'code' => strtolower($code)]);
    check('right code signs in', $right['status'] === 302 && strpos($right['headers'], '/guest.php') !== false);
    $guestPage = $get('/guest.php');
    check('guest page shows the date', strpos($guestPage['body'], WEDDING_DATE) !== false);
    check('guest page shows RSVP with a tick box per person', $guestPage['status'] === 200 && substr_count($guestPage['body'], 'name="coming[]"') === 2);
    $saved = $get('/guest.php', ['csrf' => $csrf($guestPage['body']), 'attending' => 'yes', 'coming' => ['0'], 'notify' => '1']);
    check('RSVP saved', $saved['status'] === 302 && strpos($saved['headers'], 'saved=1') !== false);
    check('calendar download', stripos($get('/calendar.php')['headers'], 'content-type: text/calendar') !== false);
    check('admin needs sign-in', strpos($get('/admin.php?tab=insights')['body'], 'name="password"') !== false);
    check('caterer CSV needs sign-in', stripos($get('/admin.php?export=caterer')['headers'], 'text/csv') === false);

    // Admin: sign in as the owner, upload a CSV, then email a couple with an address each.
    $login = $get('/admin.php');
    $signedIn = $get('/admin.php', ['csrf' => $csrf($login['body']), 'username' => 'admin', 'password' => ADMIN_PASSWORD, 'login' => '1']);
    check('owner can sign in', $signedIn['status'] === 302);
    $csvFile = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($csvFile, "Name,Email,Email 2\nAli & Jo Khan,ali@example.com,jo@example.com\nUncle Ken,ken@example.com,\n");
    $upload = function () use ($get, $csrf, $csvFile) {
        $form = $get('/admin.php?tab=guests');
        return $get('/admin.php', null, [CURLOPT_POSTFIELDS => ['csrf' => $csrf($form['body']), 'action' => 'upload_csv',
            'csv' => new CURLFile($csvFile, 'text/csv', 'guests.csv')]]);
    };
    $upload();
    $list = $get('/admin.php?tab=guests')['body'];
    check('CSV upload adds invitations', strpos($list, 'Added 2 invitations.') !== false && strpos($list, 'Uncle Ken') !== false);
    check('both addresses shown for a couple', (bool)preg_match('#<span class="email-address">ali@example\.com</span>\s*<span class="email-address">jo@example\.com</span>#', $list));
    $upload();
    check('uploading the same file again skips them', strpos($get('/admin.php?tab=guests')['body'], 'Skipped 2 already on the list') !== false);
    $forgedUpload = $get('/admin.php', null, [CURLOPT_POSTFIELDS => ['action' => 'upload_csv', 'csv' => new CURLFile($csvFile, 'text/csv', 'guests.csv')]]);
    check('upload needs a CSRF token', $forgedUpload['status'] === 400);
    @unlink($csvFile);

    $mailLog = dirname(DB_PATH) . '/mail.log';
    @unlink($mailLog);
    $khan = db()->query("SELECT id FROM guests WHERE name = 'Ali & Jo Khan'")->fetchColumn();
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'send_one', 'id' => $khan]);
    $log = (string)@file_get_contents($mailLog);
    check('invitation sent to each address', substr_count($log, '==== ') === 2 && strpos($log, '<ali@example.com>') !== false && strpos($log, '<jo@example.com>') !== false);
    check('sending marks the invitation as emailed', db()->query("SELECT invited_at FROM guests WHERE id = " . (int)$khan)->fetchColumn() !== null);
    @unlink($mailLog);
    @unlink($jar);
}

// --- Result -------------------------------------------------------------------

@unlink(DB_PATH);
echo "\n" . ($failed ? '✗' : '✓') . " $passed passed, " . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  FAIL: $f\n";
}
exit($failed ? 1 : 0);
