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
foreach (['content', 'mailer', 'notify', 'users', 'insights', 'calendar', 'passkeys'] as $module) {
    require APP_DIR . "/$module.php";
}
ob_end_clean();

// --- A pretend passkey device -------------------------------------------------
// Makes real keys with openssl and builds exactly what a browser sends, so the
// passkey checks can be tested, including every way of tampering with it.

class CborText { public $text; function __construct($t) { $this->text = $t; } }

function cbor_encode($v): string
{
    $head = function (int $major, int $n): string {
        if ($n < 24) return chr($major << 5 | $n);
        if ($n < 256) return chr($major << 5 | 24) . chr($n);
        if ($n < 65536) return chr($major << 5 | 25) . pack('n', $n);
        return chr($major << 5 | 26) . pack('N', $n);
    };
    if ($v === false || $v === true || $v === null) return chr(0xe0 | ($v === null ? 22 : ($v ? 21 : 20)));
    if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
    if ($v instanceof CborText) return $head(3, strlen($v->text)) . $v->text;
    if (is_string($v)) return $head(2, strlen($v)) . $v;
    if (is_array($v) && $v && array_keys($v) === range(0, count($v) - 1)) {
        return $head(4, count($v)) . implode('', array_map('cbor_encode', $v));
    }
    $out = $head(5, count((array)$v));
    foreach ((array)$v as $k => $item) {
        $out .= (is_int($k) ? cbor_encode($k) : cbor_encode(new CborText($k))) . cbor_encode($item);
    }
    return $out;
}

function test_device(string $kind = 'ec', int $bits = 2048): array
{
    if ($kind === 'ec') {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key)['ec'];
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['x'], 32, "\0", STR_PAD_LEFT), -3 => str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
    } else {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        $d = openssl_pkey_get_details($key)['rsa'];
        $cose = [1 => 3, 3 => -257, -1 => $d['n'], -2 => $d['e']];
    }
    return ['key' => $key, 'cose' => $cose, 'id' => random_bytes(32), 'count' => 0, 'counts' => true];
}

// What navigator.credentials.create() returns: [clientDataJSON, attestationObject].
function test_register(array $device, string $challenge, string $origin, string $rpId, int $flags = 0x45, string $type = 'webauthn.create'): array
{
    $client = json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
    $auth = hash('sha256', $rpId, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16)
        . pack('n', strlen($device['id'])) . $device['id'] . cbor_encode($device['cose']);
    return [$client, cbor_encode(['fmt' => new CborText('none'), 'attStmt' => [], 'authData' => $auth])];
}

// What navigator.credentials.get() returns: [clientDataJSON, authenticatorData, signature].
function test_sign(array &$device, string $challenge, string $origin, string $rpId, int $flags = 0x05, string $type = 'webauthn.get'): array
{
    if ($device['counts']) {
        $device['count']++;
    }
    $client = json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin]);
    $auth = hash('sha256', $rpId, true) . chr($flags) . pack('N', $device['count']);
    openssl_sign($auth . hash('sha256', $client, true), $signature, $device['key'], OPENSSL_ALGO_SHA256);
    return [$client, $auth, $signature];
}


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

section('Names and greetings');
same('first names: one surname shared', 'Sarah, Tom and Lily', first_names('Sarah, Tom and Lily Jones'));
same('first names: a surname each', 'Ali & Jo', first_names('Ali Khan & Jo Smith'));
same('titles keep the surname', 'Mr & Mrs Smith', first_names('Mr & Mrs Smith'));
same('relations are first names', 'Uncle Bob', first_names('Uncle Bob'));
same('relation with a surname', 'Uncle Bob', first_names('Uncle Bob Jones'));
same('family names left alone', 'The Okafor Family', first_names('The Okafor Family'));
same('plus-one kept', 'Bob + guest', first_names('Bob Hope + guest'));
same('surname particles', 'Anna', first_names('Anna van der Berg'));
same('Irish surname', 'Seán', first_names('Seán Ó Briain'));
same('single names unchanged', 'Gran', first_names('Gran'));
same('surnames in bold', 'Ali <strong class="surname">Khan</strong> &amp; Jo <strong class="surname">Smith</strong>', name_html('Ali Khan & Jo Smith'));
same('a title\'s surname is bold too', 'Mr &amp; Mrs <strong class="surname">Smith</strong>', name_html('Mr & Mrs Smith'));
same('no bold without a surname', 'Uncle Bob', name_html('Uncle Bob'));
check('names are escaped', strpos(name_html('<b>Bob</b> Jones'), '<b>') === false);
same('greeting follows the site choice', 'Sarah Jones', greeting_name(['name' => 'Sarah Jones']));
same('invitation can ask for first names', 'Sarah', greeting_name(['name' => 'Sarah Jones', 'greeting_style' => 'first']));
same('avatar initials', ['SJ', 'A', 'ÉO', '?'], [initials('Sam Jones'), initials('Admin'), initials('élodie o\'neil'), initials('123')]);
same('a typed greeting wins', 'Granny Pat', greeting_name(['name' => 'Pat Jones', 'greeting_style' => 'first', 'greeting' => 'Granny Pat']));
[, $mailText, $mailHtml] = render_invite(['name' => 'Sarah Jones', 'greeting_style' => 'first', 'code' => 'K7MPX4QR'], mail_settings());
check('emails use the greeting for {name}', strpos($mailText, 'Dear Sarah,') === 0 && strpos($mailHtml, 'Dear Sarah,') !== false);

section('Passkeys');
same('base64url round trip', "\x00\xff\xfe binary", b64url_decode(b64url_encode("\x00\xff\xfe binary")));
check('base64url refuses other characters', b64url_decode('abc+/=') === null);
$pos = 0;
same('CBOR reads a map', [1 => 2, 'a' => "\x01\x02", -3 => [true, null]], cbor_read(cbor_encode([1 => 2, 'a' => "\x01\x02", -3 => [true, null]]), $pos));
foreach (["\x5a\xff\xff\xff\xff" => 'a byte string longer than the data', "\x9f" => 'indefinite lengths', "\x81\x81\x81\x81\x81\x81\x81\x81\x81\x81\x00" => 'very deep nesting',
          "\x1b\xff\xff\xff\xff\xff\xff\xff\xff" => 'a huge number', "\xc0\x00" => 'tags'] as $bad => $what) {
    $pos = 0;
    try { cbor_read($bad, $pos); $ok = false; } catch (RuntimeException $e) { $ok = true; }
    check("CBOR refuses $what", $ok);
}
$site = ['challenge' => 'Y2hhbGxlbmdlLW9uZQ', 'origin' => 'https://example.com', 'rp_id' => 'example.com'];
$device = test_device('ec');
[$cd, $att] = test_register($device, $site['challenge'], $site['origin'], $site['rp_id']);
$reg = verify_passkey_registration($site, $cd, $att);
check('a new passkey (P-256) is accepted', is_array($reg) && $reg['credential_id'] === b64url_encode($device['id']) && strpos($reg['public_key'], 'BEGIN PUBLIC KEY') !== false);
$rsa = test_device('rsa');
[$cd2, $att2] = test_register($rsa, $site['challenge'], $site['origin'], $site['rp_id']);
check('a new passkey (RSA) is accepted', is_array(verify_passkey_registration($site, $cd2, $att2)));
$refusals = [
    'a different challenge' => test_register($device, 'c29tZXRoaW5nLWVsc2U', $site['origin'], $site['rp_id']),
    'a look-alike site' => test_register($device, $site['challenge'], 'https://examp1e.com', $site['rp_id']),
    'another site\'s passkey' => test_register($device, $site['challenge'], $site['origin'], 'evil.example'),
    'no Face ID/fingerprint/PIN' => test_register($device, $site['challenge'], $site['origin'], $site['rp_id'], 0x41),
    'a sign-in posing as a new passkey' => test_register($device, $site['challenge'], $site['origin'], $site['rp_id'], 0x45, 'webauthn.get'),
    'a weak RSA key' => test_register(test_device('rsa', 1024), $site['challenge'], $site['origin'], $site['rp_id']),
];
$unsupported = $device;
$unsupported['cose'] = [1 => 1, 3 => -8, -1 => 6, -2 => str_repeat("\1", 32)];   // Ed25519: not offered
$refusals['an unsupported key type'] = test_register($unsupported, $site['challenge'], $site['origin'], $site['rp_id']);
$refusals['unreadable data'] = [$cd, "\xa1\x63abc"];
foreach ($refusals as $what => [$c, $a]) {
    check("new passkey refused: $what", is_string(verify_passkey_registration($site, $c, $a)));
}

$stored = ['public_key' => $reg['public_key'], 'sign_count' => 0];
[$c, $ad, $sig] = test_sign($device, $site['challenge'], $site['origin'], $site['rp_id']);
same('passkey sign-in accepted', 1, verify_passkey_assertion($site, $stored, $c, $ad, $sig));
$stored['sign_count'] = 1;
check('the same sign-in can\'t be replayed', is_string(verify_passkey_assertion($site, $stored, $c, $ad, $sig)));
[$c, $ad, $sig] = test_sign($device, $site['challenge'], $site['origin'], $site['rp_id']);
same('the next sign-in is accepted', 2, verify_passkey_assertion($site, $stored, $c, $ad, $sig));
$synced = test_device('ec');
$synced['counts'] = false;   // synced passkeys (Apple, Google) always report 0
[, $att3] = test_register($synced, $site['challenge'], $site['origin'], $site['rp_id']);
$syncedStored = ['public_key' => verify_passkey_registration($site, test_register($synced, $site['challenge'], $site['origin'], $site['rp_id'])[0], $att3)['public_key'], 'sign_count' => 0];
[$c, $ad, $sig] = test_sign($synced, $site['challenge'], $site['origin'], $site['rp_id']);
same('synced passkeys that don\'t count are accepted', 0, verify_passkey_assertion($site, $syncedStored, $c, $ad, $sig));
$stored['sign_count'] = 2;
$attacks = [
    'a different challenge' => test_sign($device, 'b3RoZXI', $site['origin'], $site['rp_id']),
    'a look-alike site' => test_sign($device, $site['challenge'], 'https://examp1e.com', $site['rp_id']),
    'another site\'s passkey' => test_sign($device, $site['challenge'], $site['origin'], 'evil.example'),
    'no Face ID/fingerprint/PIN' => test_sign($device, $site['challenge'], $site['origin'], $site['rp_id'], 0x01),
    'a new-passkey answer posing as a sign-in' => test_sign($device, $site['challenge'], $site['origin'], $site['rp_id'], 0x05, 'webauthn.create'),
    'someone else\'s key' => test_sign($rsa, $site['challenge'], $site['origin'], $site['rp_id']),
];
[$c, $ad, $sig] = test_sign($device, $site['challenge'], $site['origin'], $site['rp_id']);
$attacks['tampered device data'] = [$c, substr($ad, 0, 32) . chr(ord($ad[32]) | 0x40) . substr($ad, 33), $sig];
$attacks['tampered browser data'] = [str_replace('"origin"', '"origin" ', $c), $ad, $sig];
$attacks['a counter going backwards'] = (function () use ($device, $site) { $d = $device; $d['count'] = 0; return test_sign($d, $site['challenge'], $site['origin'], $site['rp_id']); })();
foreach ($attacks as $what => [$c, $ad, $sig]) {
    check("passkey sign-in refused: $what", is_string(verify_passkey_assertion($site, $stored, $c, $ad, $sig)));
}

section('Feedback emails');
save_admin_contact(0, '', false);
same('nobody opted in, nobody emailed', [], feedback_recipients());
save_admin_contact(0, 'couple@example.com', true);
users_db()->prepare('INSERT INTO users (name, username, email, notify_feedback, password_hash) VALUES (?, ?, ?, 1, ?)')
    ->execute(['Pat', 'pat', 'pat@example.com', password_hash('x', PASSWORD_DEFAULT)]);
users_db()->prepare('INSERT INTO users (name, username, email, notify_feedback, password_hash) VALUES (?, ?, ?, 0, ?)')
    ->execute(['Kim', 'kim', 'kim@example.com', password_hash('x', PASSWORD_DEFAULT)]);
users_db()->prepare('INSERT INTO users (name, username, email, notify_feedback) VALUES (?, ?, ?, 1)')
    ->execute(['New', 'new', 'new@example.com']);
users_db()->prepare('INSERT INTO users (name, username, email, notify_feedback, password_hash) VALUES (?, ?, ?, 1, ?)')
    ->execute(['Same', 'same', 'COUPLE@example.com', password_hash('x', PASSWORD_DEFAULT)]);
same('opted-in people who can sign in, one email per address', ['couple@example.com', 'pat@example.com'],
    array_column(feedback_recipients(), 'email'));
check('owner contact saved', admin_contact(0) === ['email' => 'couple@example.com', 'notify' => true]);
users_db()->exec("DELETE FROM users WHERE username IN ('pat', 'kim', 'new', 'same')");
save_admin_contact(0, '', false);

section('Tagline');
save_setting('tagline', '');
same('default tagline', "You're invited", tagline());
save_setting('tagline', 'Join us <for> the day');
same('custom tagline', 'Join us <for> the day', tagline());
[, , $taglineHtml] = render_invite(['name' => 'Sarah Jones', 'code' => 'K7MPX4QR'], mail_settings());
check('the invitation email shows it, escaped', strpos($taglineHtml, 'Join us &lt;for&gt; the day') !== false && strpos($taglineHtml, '<for>') === false);
save_setting('tagline', '');

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
unset($_SESSION['admin_user']);
same('a session without a person is nobody, not the owner', false, current_admin()['owner']);
$ownerKey = admin_session_key(0);
same('owner session key stays the same', $ownerKey, admin_session_key(0));
save_setting('owner_fingerprint', 'from an older password');
check('owner session key changes with ADMIN_PASSWORD', admin_session_key(0) !== $ownerKey);
check('owner password fingerprint isn\'t the password', strpos(setting('owner_fingerprint'), ADMIN_PASSWORD) === false
    && setting('owner_fingerprint') !== hash('sha256', ADMIN_PASSWORD));
$samKey = admin_session_key((int)$sam['id']);
set_password((int)$sam['id'], 'another-long-password');
check('a new password changes the session key', admin_session_key((int)$sam['id']) !== $samKey && admin_session_key((int)$sam['id']) !== '');

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
    check('code page shows the default tagline', strpos($home['body'], '<p class="eyebrow">You&#039;re invited</p>') !== false);
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
    check('RSVP stays open and wiggles until they reply', strpos($guestPage['body'], 'class="card collapsible nudge" id="rsvp" data-key="rsvp" data-open="1"') !== false);
    check('guest page shows the date', strpos($guestPage['body'], WEDDING_DATE) !== false);
    check('guest page shows RSVP with a tick box per person', $guestPage['status'] === 200 && substr_count($guestPage['body'], 'name="coming[]"') === 2);
    $saved = $get('/guest.php', ['csrf' => $csrf($guestPage['body']), 'attending' => 'yes', 'coming' => ['0'], 'notify' => '1']);
    check('RSVP saved', $saved['status'] === 302 && strpos($saved['headers'], 'saved=1') !== false);
    check('after replying, RSVP can be closed and stay closed', strpos($get('/guest.php')['body'], 'data-open="1"') === false);
    $form = $get('/guest.php');
    $get('/guest.php', ['csrf' => $csrf($form['body']), 'attending' => 'maybe', 'notify' => '1']);
    check('"not sure yet": RSVP open, no wiggle straight after saving', (bool)preg_match('#class="card collapsible" id="rsvp" data-key="rsvp" data-open="1"#', $get('/guest.php?saved=1')['body']));
    check('"not sure yet": wiggles on the next visit', strpos($get('/guest.php')['body'], 'collapsible nudge" id="rsvp"') !== false);
    $form = $get('/guest.php');
    $get('/guest.php', ['csrf' => $csrf($form['body']), 'attending' => 'yes', 'coming' => ['0'], 'notify' => '1']);
    check('calendar download', stripos($get('/calendar.php')['headers'], 'content-type: text/calendar') !== false);
    check('admin needs sign-in', strpos($get('/admin.php?tab=insights')['body'], 'name="password"') !== false);
    check('caterer CSV needs sign-in', stripos($get('/admin.php?export=caterer')['headers'], 'text/csv') === false);

    // Admin: sign in as the owner, upload a CSV, then email a couple with an address each.
    $login = $get('/admin.php');
    $signedIn = $get('/admin.php', ['csrf' => $csrf($login['body']), 'username' => 'admin', 'password' => ADMIN_PASSWORD, 'login' => '1']);
    check('owner can sign in', $signedIn['status'] === 302);
    check('signing in gives a fresh form token', $csrf($get('/admin.php?tab=guests')['body']) !== $csrf($login['body']));

    // Another admin's session ends when their password changes or is reset.
    $jar2 = tempnam(sys_get_temp_dir(), 'jar');
    $as2 = function (string $path, ?array $post = null) use ($base, $jar2) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar2, CURLOPT_COOKIEFILE => $jar2]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string)curl_exec($ch);
        return ['status' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => substr($raw, curl_getinfo($ch, CURLINFO_HEADER_SIZE))];
    };
    users_db()->prepare('INSERT INTO users (name, username) VALUES (?, ?)')->execute(['Jo', 'jo']);
    $joId = (int)users_db()->lastInsertId();
    set_password($joId, 'jo-first-long-password');
    $joLogin = $as2('/admin.php');
    $as2('/admin.php', ['csrf' => $csrf($joLogin['body']), 'username' => 'jo', 'password' => 'jo-first-long-password', 'login' => '1']);
    check('another admin can sign in', strpos($as2('/admin.php?tab=guests')['body'], 'aria-label="Account: Jo (no passkey yet)"') !== false);
    set_password($joId, 'jo-second-long-password');
    check('changing their password signs out old sessions', $as2('/admin.php?tab=guests')['status'] === 302
        && strpos($as2('/admin.php')['body'], 'name="password"') !== false);
    $joLogin = $as2('/admin.php');
    $as2('/admin.php', ['csrf' => $csrf($joLogin['body']), 'username' => 'jo', 'password' => 'jo-second-long-password', 'login' => '1']);
    users_db()->prepare('UPDATE users SET password_hash = NULL WHERE id = ?')->execute([$joId]);   // what "Email reset link" does
    check('a reset signs them out straight away', $as2('/admin.php?tab=guests')['status'] === 302
        && strpos($as2('/admin.php')['body'], 'name="password"') !== false);
    $forged = $as2('/admin.php', ['csrf' => $csrf($joLogin['body']), 'action' => 'user_remove', 'id' => $joId]);
    check('a signed-out session can\'t act', $forged['status'] !== 200 || strpos($forged['body'], 'Removed') === false);
    users_db()->prepare('DELETE FROM users WHERE id = ?')->execute([$joId]);

    // Feedback emails: the owner opts in; a guest's message reaches opted-in admins only.
    $account = $get('/admin.php?tab=account');
    $get('/admin.php', ['csrf' => $csrf($account['body']), 'action' => 'account_contact', 'email' => 'couple@example.com', 'notify_feedback' => '1']);
    check('owner can turn on feedback emails', strpos($get('/admin.php?tab=account')['body'], 'get an email when a guest sends a message') !== false
        && admin_contact(0) === ['email' => 'couple@example.com', 'notify' => true]);
    users_db()->prepare('INSERT INTO users (name, username, email, notify_feedback, password_hash) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Lee', 'lee', 'lee@example.com', 1, password_hash('lee-long-password', PASSWORD_DEFAULT)]);
    users_db()->prepare('INSERT INTO users (name, username, email, notify_feedback, password_hash) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Kim', 'kim', 'kim@example.com', 0, password_hash('kim-long-password', PASSWORD_DEFAULT)]);
    $mailLog = dirname(DB_PATH) . '/mail.log';
    @unlink($mailLog);
    $guestForm = $get('/guest.php');
    $get('/guest.php', ['csrf' => $csrf($guestForm['body']), 'action' => 'feedback', 'message' => "Is there <b>parking</b> & a lift?"]);
    $log = (string)@file_get_contents($mailLog);
    preg_match_all('#Content-Transfer-Encoding: base64\r\n(?:[^\r\n]+\r\n)*\r\n([A-Za-z0-9+/=\r\n]+?)\r\n(?:--|$)#', $log, $parts);
    $decoded = implode("\n", array_map(fn($b) => base64_decode(str_replace("\r\n", '', $b)), $parts[1]));
    check('feedback emailed to opted-in admins only', substr_count($log, '==== ') === 2 && strpos($log, '<couple@example.com>') !== false
        && strpos($log, '<lee@example.com>') !== false && strpos($log, 'kim@example.com') === false);
    check('replying goes to the guest', substr_count($log, 'Reply-To: sarah@example.com') === 2);
    check('the email has their message, safely', strpos($decoded, 'Is there <b>parking</b> & a lift?') !== false
        && strpos($decoded, 'Is there &lt;b&gt;parking&lt;/b&gt; &amp; a lift?') !== false && strpos($decoded, 'tab=feedback') !== false);
    check('the message is still saved for the Feedback tab', (int)db()->query("SELECT COUNT(*) FROM feedback WHERE message LIKE 'Is there%'")->fetchColumn() === 1);
    @unlink($mailLog);

    // Other admins need their password to change their email address (it's used for resets).
    $leeId = (int)users_db()->query("SELECT id FROM users WHERE username = 'lee'")->fetchColumn();
    $leeLogin = $as2('/admin.php');
    $as2('/admin.php', ['csrf' => $csrf($leeLogin['body']), 'username' => 'lee', 'password' => 'lee-long-password', 'login' => '1']);
    $leeAccount = $as2('/admin.php?tab=account');
    $as2('/admin.php', ['csrf' => $csrf($leeAccount['body']), 'action' => 'account_contact', 'email' => 'attacker@example.com', 'notify_feedback' => '1']);
    check('changing an admin\'s email needs their password', admin_contact($leeId)['email'] === 'lee@example.com');
    $leeAccount = $as2('/admin.php?tab=account');
    $as2('/admin.php', ['csrf' => $csrf($leeAccount['body']), 'action' => 'account_contact', 'email' => 'lee2@example.com',
        'notify_feedback' => '1', 'current_password' => 'lee-long-password']);
    check('with their password it changes', admin_contact($leeId)['email'] === 'lee2@example.com');
    $leeAccount = $as2('/admin.php?tab=account');
    $as2('/admin.php', ['csrf' => $csrf($leeAccount['body']), 'action' => 'account_contact', 'email' => 'lee2@example.com']);
    check('turning feedback emails off needs no password', admin_contact($leeId)['notify'] === false);

    // A password reset link mustn't get past someone's passkey.
    passkeys_db()->prepare('INSERT INTO passkeys (user_id, credential_id, public_key, name) VALUES (?, ?, ?, ?)')
        ->execute([$leeId, 'test-credential-lee', 'not a real key', 'Lee phone']);
    $resetUrl = new_setup_link($leeId);
    $jar4 = tempnam(sys_get_temp_dir(), 'jar');
    $fresh = function (string $path, ?array $post = null) use ($base, $jar4) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar4, CURLOPT_COOKIEFILE => $jar4]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        return (string)curl_exec($ch);
    };
    $resetPath = '/admin.php?setup=' . urlencode(substr($resetUrl, strpos($resetUrl, 'setup=') + 6));
    $resetPage = $fresh($resetPath);
    $fresh($resetPath, ['csrf' => $csrf($resetPage), 'password' => 'lee-new-long-password', 'confirm' => 'lee-new-long-password']);
    $after = $fresh('/admin.php?tab=guests');
    check('a password reset still asks for their passkey', strpos($after, 'confirm it\'s you with your passkey') !== false && strpos($after, 'Invitations (') === false);
    @unlink($jar4);
    passkeys_db()->exec("DELETE FROM passkeys WHERE user_id = $leeId");
    users_db()->exec("DELETE FROM users WHERE username IN ('lee', 'kim')");
    save_admin_contact(0, '', false);
    @unlink($jar2);

    // Tagline: set on the Invitation email tab, shown on the code page.
    $emailTab = $get('/admin.php?tab=email');
    $get('/admin.php', ['csrf' => $csrf($emailTab['body']), 'action' => 'save_email', 'tagline' => "  Save   the\ndate  "] + mail_settings());
    $jar3 = tempnam(sys_get_temp_dir(), 'jar');
    $ch = curl_init($base . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar3, CURLOPT_COOKIEFILE => $jar3]);
    check('a new tagline shows on the code page, tidied to one line', strpos((string)curl_exec($ch), '<p class="eyebrow">Save the date</p>') !== false);
    @unlink($jar3);
    $emailTab = $get('/admin.php?tab=email');
    $get('/admin.php', ['csrf' => $csrf($emailTab['body']), 'action' => 'save_email', 'tagline' => ''] + mail_settings());
    check('an empty tagline goes back to the default', tagline() === "You're invited");

    // Greetings: switch the site to first names, then give one invitation its own greeting.
    $form = $get('/admin.php?tab=guests');
    check('Add invitations can be collapsed', (bool)preg_match('#data-key="add-invitations">\s*<h2 class="card-heading"><button type="button" class="card-toggle" aria-expanded="true" aria-controls="add-invitations-body">#', $form['body'])
        && strpos($form['body'], 'id="add-invitations-body"') !== false);
    check('surnames shown in bold', strpos($form['body'], 'Sarah &amp; Tom <strong class="surname">Jones</strong>') !== false);
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'greeting_style', 'style' => 'first']);
    check('guest page greets by first names', strpos($get('/guest.php')['body'], '<h1>Sarah &amp; Tom</h1>') !== false);
    $jones = db()->prepare('SELECT id FROM guests WHERE code = ?');
    $jones->execute([$code]);
    $jones = $jones->fetchColumn();
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'update', 'id' => $jones, 'name' => 'Sarah & Tom Jones',
        'email' => 'sarah@example.com', 'greeting_style' => '', 'greeting' => "The\nJoneses"]);
    check('a typed greeting is used on their page', strpos($get('/guest.php')['body'], '<h1>The Joneses</h1>') !== false);
    check('admin shows who is greeted differently', strpos($get('/admin.php?tab=guests')['body'], 'Greeted as “The Joneses”') !== false);
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'greeting_style', 'style' => 'full']);
    check('greeting choice saved site-wide', site_greeting_style() === 'full');
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

    // Tick several invitations and send them together.
    db()->prepare('INSERT INTO guests (code, name, email) VALUES (?, ?, NULL)')->execute([generate_code(), 'Gran No-Email']);
    $gran = db()->query("SELECT id FROM guests WHERE name = 'Gran No-Email'")->fetchColumn();
    $ken = db()->query("SELECT id FROM guests WHERE name = 'Uncle Ken'")->fetchColumn();
    $form = $get('/admin.php?tab=guests');
    check('tick boxes say who has been emailed', (bool)preg_match('#value="' . (int)$khan . '"[^>]*data-sendable="1" data-sent="1"#', $form['body']));
    @unlink($mailLog);
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'send_selected', 'ids' => [$khan, $ken, $gran]]);
    $log = (string)@file_get_contents($mailLog);
    check('ticked invitations sent, each address once', substr_count($log, '==== ') === 3 && strpos($log, '<ken@example.com>') !== false);
    check('send message counts resends and no-email guests',
        strpos($get('/admin.php?tab=guests')['body'], 'Sent 2 invitations (1 of them again). No email address for: Gran No-Email.') !== false);
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'send_selected']);
    check('nothing ticked, nothing sent', strpos($get('/admin.php?tab=guests')['body'], 'Tick the invitations you want to send first.') !== false);
    @unlink($mailLog);

    // Tick several invitations and delete them together.
    $form = $get('/admin.php?tab=guests');
    check('each invitation has a tick box', substr_count($form['body'], 'name="ids[]"') === (int)db()->query('SELECT COUNT(*) FROM guests')->fetchColumn());
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'delete_selected', 'ids' => [$khan, $ken]]);
    check('ticked invitations deleted together', strpos($get('/admin.php?tab=guests')['body'], 'Deleted 2 invitations.') !== false
        && !db()->query("SELECT COUNT(*) FROM guests WHERE id IN (" . (int)$khan . ', ' . (int)$ken . ')')->fetchColumn());
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'delete_selected', 'ids' => range(100000, 100899)]);
    check('hundreds ticked at once works (in batches under SQLite\'s limit)', strpos($get('/admin.php?tab=guests')['body'], 'Deleted 0 invitations.') !== false);
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'delete_selected', 'ids' => range(100000, 101199)]);
    check('too many ticked for PHP to receive: refused, not half done',
        strpos($get('/admin.php?tab=guests')['body'], 'more invitations than can be sent in one go') !== false);
    $form = $get('/admin.php?tab=guests');
    $get('/admin.php', ['csrf' => $csrf($form['body']), 'action' => 'delete_selected']);
    check('nothing ticked, nothing deleted', strpos($get('/admin.php?tab=guests')['body'], 'Tick the invitations you want to delete first.') !== false);
    $survivor = db()->query('SELECT id FROM guests LIMIT 1')->fetchColumn();
    check('deleting needs a CSRF token', $get('/admin.php', ['action' => 'delete_selected', 'ids' => [$survivor]])['status'] === 400
        && db()->query('SELECT COUNT(*) FROM guests WHERE id = ' . (int)$survivor)->fetchColumn());

    // Passkeys, end to end with the pretend device: add one, then it's needed to sign in.
    $origin = rtrim($base, '/');
    $rpId = (string)parse_url($base, PHP_URL_HOST);
    $options = function (string $purpose, string $page = '/admin.php?tab=account') use ($get, $csrf) {
        $form = $get($page);
        $r = $get('/admin.php?passkey_options=1', ['csrf' => $csrf($form['body']), 'purpose' => $purpose]);
        return [json_decode($r['body'], true) ?: [], $csrf($form['body']), $r['status']];
    };
    [$o, $token] = $options('register');
    check('passkey options name this site', ($o['rp']['id'] ?? '') === $rpId && ($o['authenticatorSelection']['userVerification'] ?? '') === 'required');
    $key = test_device('ec');
    [$cd, $att] = test_register($key, $o['challenge'], $origin, $rpId);
    $get('/admin.php', ['csrf' => $token, 'action' => 'passkey_register', 'passkey_name' => 'Test phone',
        'credential_id' => b64url_encode($key['id']), 'client_data' => b64url_encode($cd), 'attestation' => b64url_encode($att)]);
    $account = $get('/admin.php?tab=account')['body'];
    check('passkey added', strpos($account, 'need it to sign in') !== false && strpos($account, 'Test phone') !== false);
    [$o, $token] = $options('register');
    [$cd, $att] = test_register($key, $o['challenge'], $origin, $rpId);
    $get('/admin.php', ['csrf' => $token, 'action' => 'passkey_register', 'passkey_name' => 'Again',
        'credential_id' => b64url_encode($key['id']), 'client_data' => b64url_encode($cd), 'attestation' => b64url_encode($att)]);
    check('the same passkey can\'t be added twice', strpos($get('/admin.php?tab=account')['body'], 'already been added') !== false);

    $signOut = function () use ($get, $csrf) {
        $page = $get('/admin.php?tab=account');
        $get('/admin.php', ['csrf' => $csrf($page['body']), 'action' => 'logout']);
    };
    $signOut();
    $login = $get('/admin.php');
    $get('/admin.php', ['csrf' => $csrf($login['body']), 'username' => 'admin', 'password' => ADMIN_PASSWORD, 'login' => '1']);
    $step = $get('/admin.php?tab=guests')['body'];
    check('with a passkey, the password alone isn\'t enough', strpos($step, 'confirm it\'s you with your passkey') !== false && strpos($step, 'Invitations (') === false);
    [$o, $token, $status] = $options('register', '/admin.php');
    check('half signed in can\'t add a passkey', $status === 403);
    [$o, $token] = $options('confirm', '/admin.php');
    check('the confirm step asks for this person\'s passkey', ($o['allowCredentials'][0]['id'] ?? '') === b64url_encode($key['id']));
    [$c, $ad, $sig] = test_sign($key, $o['challenge'], $origin, $rpId);
    $confirmPost = ['csrf' => $token, 'action' => 'passkey_confirm', 'credential_id' => b64url_encode($key['id']),
        'client_data' => b64url_encode($c), 'authenticator_data' => b64url_encode($ad), 'signature' => b64url_encode($sig)];
    $get('/admin.php', $confirmPost);
    check('password then passkey signs in', strpos($get('/admin.php?tab=guests')['body'], 'Invitations (') !== false);

    $signOut();
    [$o, $token] = $options('login', '/admin.php');
    [$c, $ad, $sig] = test_sign($key, $o['challenge'], $origin, $rpId);
    $passkeyPost = ['csrf' => $token, 'action' => 'passkey_login', 'credential_id' => b64url_encode($key['id']),
        'client_data' => b64url_encode($c), 'authenticator_data' => b64url_encode($ad), 'signature' => b64url_encode($sig)];
    $get('/admin.php', $passkeyPost);
    check('a passkey on its own signs in', strpos($get('/admin.php?tab=guests')['body'], 'Invitations (') !== false);

    $signOut();
    $page = $get('/admin.php');
    $passkeyPost['csrf'] = $csrf($page['body']);
    $replay = $get('/admin.php', $passkeyPost);
    check('a captured passkey sign-in can\'t be replayed', strpos($replay['body'], 'didn&#039;t work') !== false
        && strpos($get('/admin.php?tab=guests')['body'], 'Invitations (') === false);
    $impostor = test_device('ec');
    [$o, $token] = $options('login', '/admin.php');
    [$c, $ad, $sig] = test_sign($impostor, $o['challenge'], $origin, $rpId);
    $get('/admin.php', ['csrf' => $token, 'action' => 'passkey_login', 'credential_id' => b64url_encode($key['id']),
        'client_data' => b64url_encode($c), 'authenticator_data' => b64url_encode($ad), 'signature' => b64url_encode($sig)]);
    check('a different key using this passkey\'s id is refused', strpos($get('/admin.php?tab=guests')['body'], 'Invitations (') === false);

    // Tidy up: sign back in with the passkey and remove it, so the password works alone again.
    [$o, $token] = $options('login', '/admin.php');
    [$c, $ad, $sig] = test_sign($key, $o['challenge'], $origin, $rpId);
    $get('/admin.php', ['csrf' => $token, 'action' => 'passkey_login', 'credential_id' => b64url_encode($key['id']),
        'client_data' => b64url_encode($c), 'authenticator_data' => b64url_encode($ad), 'signature' => b64url_encode($sig)]);
    $account = $get('/admin.php?tab=account');
    preg_match('/name="id" value="(\d+)">\s*<button class="link small danger" name="action" value="passkey_remove"/', $account['body'], $m);
    $get('/admin.php', ['csrf' => $csrf($account['body']), 'action' => 'passkey_remove', 'id' => $m[1] ?? 0]);
    check('removing the last passkey says the password works alone', strpos($get('/admin.php?tab=account')['body'], 'just your password') !== false);
    $signOut();
    $login = $get('/admin.php');
    $get('/admin.php', ['csrf' => $csrf($login['body']), 'username' => 'admin', 'password' => ADMIN_PASSWORD, 'login' => '1']);
    check('without a passkey the password works alone again', strpos($get('/admin.php?tab=guests')['body'], 'Invitations (') !== false);
    @unlink($jar);
}

// --- Result -------------------------------------------------------------------

@unlink(DB_PATH);
echo "\n" . ($failed ? '✗' : '✓') . " $passed passed, " . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  FAIL: $f\n";
}
exit($failed ? 1 : 0);
