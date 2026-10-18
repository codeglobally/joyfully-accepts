<?php
// Settings: config.php (yours, private), then the optional site.php (look and
// feel; uploaded by deploy.sh), then defaults for anything not set.
// (WEDDING_CONFIG lets the automated tests use their own settings file.)
require_once getenv('WEDDING_CONFIG') ?: __DIR__ . '/config.php';
if (!getenv('WEDDING_CONFIG') && is_file(__DIR__ . '/site.php')) {
    require_once __DIR__ . '/site.php';
}
require_once __DIR__ . '/defaults.php';

// Never show error details (file paths etc.) to visitors; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

// The site only reads pages (GET, HEAD) and submits forms (POST).
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}

const APP_DIR = __DIR__;

// Semantic version, MAJOR.MINOR.PATCH, bumped with every change (see
// CHANGELOG.md): MAJOR when site owners must do something to upgrade, MINOR
// for anything new, PATCH for fixes and small tweaks. Shown in the footer.
const SITE_VERSION = '2.6.0';

// Credited in every site's footer: "© <year> global.ly", linking to the project.
const PROJECT_NAME = 'global.ly';
const PROJECT_URL = 'https://github.com/codeglobally/joyfully-accepts';

// Codes avoid look-alike characters (0/O, 1/I/L) so they're easy to type.
const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CODE_LENGTH = 8;

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Pages that need no sign-in (like the shared stylesheet) set NO_SESSION so
// they don't hand out a cookie on a response that browsers may cache.
if (!defined('NO_SESSION')) {
    session_start();
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// Turn off browser features the site never uses, and keep other sites'
// windows and pages from reaching into ours.
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// Tell browsers to only ever use HTTPS for this site (sent over HTTPS only).
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    header('Strict-Transport-Security: max-age=31536000');
}
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE IF NOT EXISTS guests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        email TEXT,
        attending INTEGER,          -- NULL = no reply yet, 1 = yes, 0 = no
        responded_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS failed_attempts (
        ip TEXT NOT NULL,
        at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    // Columns added after the first release; existing databases get them here.
    $columns = $pdo->query('PRAGMA table_info(guests)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('invited_at', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN invited_at TEXT');
    }
    if (!in_array('dietary', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN dietary TEXT');   // JSON list of {name, needs}
    }
    if (!in_array('attendees', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN attendees TEXT');     // JSON list of names coming
        $pdo->exec('ALTER TABLE guests ADD COLUMN party_size INTEGER'); // for "Family" invitations
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS feedback (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        guest_id INTEGER,
        guest_name TEXT NOT NULL,
        message TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        read_at TEXT
    )');
    if (!in_array('visits', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN first_visit_at TEXT');
        $pdo->exec('ALTER TABLE guests ADD COLUMN last_visit_at TEXT');
        $pdo->exec('ALTER TABLE guests ADD COLUMN visits INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE guests ADD COLUMN first_replied_at TEXT');
        // Replies from before this was tracked: best guess is their reply time.
        $pdo->exec('UPDATE guests SET first_replied_at = responded_at WHERE responded_at IS NOT NULL');
    }
    if (!in_array('notify', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN notify INTEGER NOT NULL DEFAULT 1');   // 0 = opted out of updates
    }
    if (!in_array('language', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN language TEXT');          // e.g. 'es'; NULL until their first visit
        $pdo->exec('ALTER TABLE guests ADD COLUMN language_set_by TEXT');   // 'browser', 'guest' or 'admin'
    }
    if (!in_array('greeting', $columns, true)) {
        $pdo->exec('ALTER TABLE guests ADD COLUMN greeting TEXT');         // typed greeting, used as it is
        $pdo->exec('ALTER TABLE guests ADD COLUMN greeting_style TEXT');   // 'full', 'first', or NULL for the site's choice
    }
    return $pdo;
}

// Site-wide settings (email template, greeting style, cached photo colours).
function setting(string $key, string $default = ''): string
{
    $q = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $q->execute([$key]);
    $value = $q->fetchColumn();
    return $value === false ? $default : $value;
}

function save_setting(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')
        ->execute([$key, $value]);
}

// Read a request value as a string. Anything else (e.g. an array sent as
// code[]=x) is treated as empty rather than crashing the page.
function input(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? $value : '';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// The venue address, escaped, with a UK postcode kept on one line.
function place_html(): string
{
    return preg_replace('/([A-Z]{1,2}\d[A-Z\d]?) (\d[A-Z]{2})$/', "$1\u{00A0}$2", h(WEDDING_PLACE));
}

// The people an invitation is for, worked out from its name:
//   "Uncle Bob" -> [Uncle Bob]          "Sarah & Tom Jones" -> [Sarah, Tom Jones]
//   "Sarah, Tom and Lily" -> 3 people   "Mr & Mrs Smith" -> [Mr Smith, Mrs Smith]
//   "Bob + guest" / "Bob +1" -> [Bob, Guest]
// Returns [] for "The O'Brien Family" etc., where the number isn't known.
function party_members(string $name): array
{
    if (is_family_name($name)) {
        return [];
    }
    $parts = array_map('trim', preg_split(NAME_SEPARATORS, trim($name), -1, PREG_SPLIT_NO_EMPTY));
    if (!$parts) {
        return [trim($name)];
    }
    // "Mr & Mrs Smith": a lone title borrows the last person's surname.
    $lastWords = preg_split('/\s+/', end($parts));
    $surname = count($lastWords) > 1 ? end($lastWords) : '';
    foreach ($parts as $i => $part) {
        if (preg_match('/^(guest|plus one|one|1|invitad[oa]|acompañante|gast|begleitung|invité|invitée|manuhiri|gwestai|ضيف|مرافق|同伴|嘉宾|嘉賓)$/iu', $part)) {
            $parts[$i] = 'Guest';
        } elseif ($surname !== '' && $i < count($parts) - 1
            && preg_match('/^(' . NAME_TITLES . ')\.?$/iu', $part)) {
            $parts[$i] = $part . ' ' . $surname;
        }
    }
    return array_values($parts);
}

// Choices offered in the dietary picker. Guests can also add their own.
const DIETARY_OPTIONS = [
    'Vegetarian', 'Vegan', 'Pescatarian', 'Gluten-free', 'Coeliac', 'Dairy-free',
    'Lactose intolerant', 'Nut allergy', 'Peanut allergy', 'Shellfish allergy',
    'Fish allergy', 'Egg allergy', 'Soy allergy', 'Sesame allergy', 'Halal', 'Kosher',
    'No pork', 'No alcohol', "Children's meal",
];

// Clean one person's picks from the form: strings only, no commas (they
// separate items when stored), at most 12 items of 60 characters each.
function clean_dietary_picks($picks): array
{
    $picks = is_array($picks) ? $picks : (is_string($picks) ? [$picks] : []);
    $clean = [];
    foreach ($picks as $pick) {
        if (!is_string($pick)) {
            continue;
        }
        $pick = trim(preg_replace('/[\s,]+/u', ' ', $pick));
        preg_match('/^.{0,60}/us', $pick, $m);   // cap length without needing mbstring
        if (($m[0] ?? '') !== '' && !in_array($m[0], $clean, true)) {
            $clean[] = $m[0];
        }
    }
    return array_slice($clean, 0, 12);
}

// Saved dietary answers for a guest, one entry per person.
function dietary_list(array $guest): array
{
    $list = json_decode((string)($guest['dietary'] ?? ''), true);
    return is_array($list) ? $list : [];
}

// guests.attending: NULL = no reply yet, 1 = yes, 0 = no, 2 = not sure yet.
const RSVP_NO = 0;
const RSVP_YES = 1;
const RSVP_MAYBE = 2;

function rsvp_status(array $guest): string
{
    if ($guest['attending'] === null) {
        return 'waiting';
    }
    return [RSVP_NO => 'no', RSVP_YES => 'yes', RSVP_MAYBE => 'maybe'][(int)$guest['attending']] ?? 'no';
}

// Who's coming on an accepted invitation:
//   ['names' => [...], 'count' => n, 'known' => bool]
// Named parties list the people ticked; "Family" invitations give a number.
// Replies from before people could be ticked count everyone as coming.
function attendees(array $guest): array
{
    if (rsvp_status($guest) !== 'yes') {
        return ['names' => [], 'count' => 0, 'known' => true];
    }
    $members = party_members($guest['name']);
    if (!$members) {
        $size = (int)($guest['party_size'] ?? 0);
        return ['names' => [], 'count' => max(1, $size), 'known' => $size > 0];
    }
    $saved = json_decode((string)($guest['attendees'] ?? ''), true);
    $names = is_array($saved) ? array_values(array_intersect($members, $saved)) : [];
    if (!$names) {
        $names = $members;   // older reply, or the invitation was renamed since
    }
    return ['names' => $names, 'count' => count($names), 'known' => true];
}

// "Sarah: vegetarian; Tom: nut allergy" (blank answers, and people who
// aren't coming, left out).
function dietary_summary(array $guest): string
{
    $coming = attendees($guest)['names'];
    $items = [];
    foreach (dietary_list($guest) as $entry) {
        if ($coming && !in_array($entry['name'], $coming, true)) {
            continue;
        }
        if (trim((string)($entry['needs'] ?? '')) !== '') {
            $items[] = $entry['name'] . ': ' . $entry['needs'];
        }
    }
    return implode('; ', $items);
}

// --- Names and greetings --------------------------------------------
// Each invitation has one name ("Sarah, Tom and Lily Jones"). Guests can be
// greeted by that full name or by first names only ("Sarah, Tom and Lily"):
// one choice for the whole site, which each invitation can override, or a
// greeting typed in for that invitation.

// What goes between people's names, in each language: "&", "+", commas
// (also the Chinese 、 and ， and the Arabic ،), "and", "plus", "y", "und",
// "et", "me" (te reo Māori), lowercase "a"/"ac" (Welsh; a capital A is
// an initial) and a separate "و". 和, 与, 與 and 及 only count between names of two
// or more characters, so a name like 王和平 stays whole.
const NAME_SEPARATORS = '/(\s*(?:&|＆|\+|＋|,|，|、|،|\b(?:and|plus|y|und|et|me)\b|(?-i:\b(?:a|ac)\b)|(?<=\s)و(?=\s)|(?<=\p{Han}{2})[和与與及](?=\p{Han}{2}))\s*)/iu';
// Words before a name that aren't first names. A title keeps the surname
// ("Mr Smith"); a relation is followed by a first name ("Uncle Bob").
const NAME_TITLES = 'mr|mrs|ms|miss|mx|dr|prof|sir|dame|lady|lord|rev|revd|capt|col|sr|sra|srta|herr|frau|mme|mlle';
const NAME_RELATIONS = 'uncle|auntie|aunty|aunt|gran|granny|grandma|grandpa|grandad|granddad|nan|nana|nanna|nanny|cousin|great|little|baby'
    . '|tío|tía|tio|tia|abuelo|abuela|primo|prima|onkel|tante|oma|opa|cousine|oncle|tata|mamie|papi|papy'
    . '|koro|kui|kuia|karani|whaea|matua|kōkā|pāpā'
    . '|ewythr|wncwl|modryb|anti|nain|taid|mamgu|tadcu|mam-gu|tad-cu'
    . '|عم|عمة|خال|خالة|جد|جدة|أبو|أم';

// One person's name split into [the rest, surname, whether a first-names
// greeting may drop it], or null when there's no surname to find:
//   "Lily Jones" -> ["Lily", "Jones", true]
//   "Mr Smith"   -> ["Mr", "Smith", false]   (titles keep the surname)
//   "Uncle Bob"  -> null                     (Bob is a first name)
//   "Gran"       -> null
function person_surname(string $person): ?array
{
    // Chinese names put the surname first with no space; rather than guess,
    // they're left whole.
    if (preg_match('/\p{Han}/u', $person)) {
        return null;
    }
    $words = preg_split('/\s+/u', trim($person), -1, PREG_SPLIT_NO_EMPTY);
    $titled = $words && preg_match('/^(' . NAME_TITLES . ')\.?$/iu', $words[0]);
    $first = $titled ? 1 : 0;
    while ($first < count($words) - 1 && preg_match('/^(' . NAME_RELATIONS . ')$/iu', $words[$first])) {
        $first++;
    }
    if (count($words) - $first < ($titled ? 1 : 2)) {
        return null;
    }
    // The last word, with any particles before it ("van der Berg", "de la Cruz", "Ó Briain").
    $start = count($words) - 1;
    while ($start - 1 > $first
        && preg_match('/^(van|von|de|der|den|da|di|du|la|le|del|della|dos|das|st\.?|ter|ten|ó|o|ní|nic|mac|mc|ap|bin|bint|ibn|al|el|بن|بنت|ابن|عبد|أبو|آل)$/iu', $words[$start - 1])) {
        $start--;
    }
    return [implode(' ', array_slice($words, 0, $start)), implode(' ', array_slice($words, $start)), !$titled];
}

// "The Smith Family" and similar are left exactly as written, and don't
// say how many people are coming.
function is_family_name(string $name): bool
{
    return (bool)preg_match('/\b(family|household|kids|children|familia|familie|famille)\b|whānau|whanau|teulu|عائلة|أسرة|一家|全家|家庭|家人/iu', $name);
}

// The name with surnames taken off: "Sarah, Tom and Lily Jones" ->
// "Sarah, Tom and Lily", "Ali Khan & Jo Smith" -> "Ali & Jo". Titles,
// relations and family names stay as they are.
function first_names(string $name): string
{
    if (is_family_name($name)) {
        return trim($name);
    }
    $pieces = preg_split(NAME_SEPARATORS, trim($name), -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($pieces as $i => $piece) {
        if ($i % 2 === 0 && ($split = person_surname($piece)) && $split[2]) {
            $pieces[$i] = $split[0];
        }
    }
    return implode('', $pieces);
}

// The name as HTML with each surname in bold, so the admin can see what a
// first-names greeting would leave out.
function name_html(string $name): string
{
    if (is_family_name($name)) {
        return h($name);
    }
    $html = '';
    foreach (preg_split(NAME_SEPARATORS, trim($name), -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $piece) {
        $split = $i % 2 === 0 ? person_surname($piece) : null;
        $html .= $split
            ? h($split[0]) . ($split[0] !== '' ? ' ' : '') . '<strong class="surname">' . h($split[1]) . '</strong>'
            : h($piece);
    }
    return $html;
}

// Up to two initials for an avatar: "Sam Jones" -> "SJ", "Admin" -> "A".
function initials(string $name): string
{
    $letters = [];
    foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (preg_match('/\p{L}/u', $word, $m)) {
            $letters[] = $m[0];   // first letter of each word ("O'Neil" -> O; "&" is skipped)
        }
    }
    $letters = $letters ?: ['?'];
    $pick = count($letters) > 1 ? $letters[0] . end($letters) : $letters[0];
    return function_exists('mb_strtoupper') ? mb_strtoupper($pick) : strtoupper($pick);
}

// The short line above the couple's names on the code-entry page and at the
// top of the invitation email. Set on the Invitation email tab.
function tagline(): string
{
    return own_wording('tagline', current_language()) ?: default_wording('tagline');
}

// --- Your wording in each language ----------------------------------------
// The tagline and the invitation email's subject and text start as built-in
// defaults, which are translated. Once you change them, your version is used:
// in a language you've written a version for, that version; otherwise your
// main-language version. Saved text that matches the default counts as the
// default, so it keeps translating.

// Your version of $key ('tagline', 'mail_subject', 'mail_body') for guests
// reading $language, or '' if it should be the (translated) default.
function own_wording(string $key, string $language): string
{
    if ($language !== site_language()) {
        $own = trim(setting($key . '.' . $language));
        if ($own !== '') {
            return $own;
        }
    }
    $main = trim(str_replace("\r\n", "\n", setting($key)));
    return $main !== '' && $main !== in_language(site_language(), fn() => default_wording($key)) ? $main : '';
}

// The built-in default for $key, in the current language.
function default_wording(string $key): string
{
    switch ($key) {
        case 'tagline':
            return t('home.tagline_default');
        case 'mail_subject':
            return t('email.invite_subject', ['couple' => COUPLE_NAMES, 'date' => subject_date()]);
        case 'mail_body':
            return t('email.invite_body', ['couple' => COUPLE_NAMES]);
    }
    return '';
}

// The wedding date without the weekday, for an email subject.
function subject_date(): string
{
    $time = strtotime(WEDDING_DATE);
    if (current_language() === site_language() || !$time) {
        return (string)preg_replace('/^\w+day,? /', '', WEDDING_DATE);
    }
    $date = (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Europe/London'));
    $months = t_list('date.months');
    return t('date.short', ['day' => number_text($date->format('j')), 'month' => $months[(int)$date->format('n') - 1] ?? '',
        'year' => number_text($date->format('Y'))]);
}

// Saves your version of $key for $language. Text matching the default is
// stored as '' so it keeps translating.
function save_wording(string $key, string $language, string $text): void
{
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($language === site_language()) {
        save_setting($key, $text === in_language($language, fn() => default_wording($key)) ? '' : $text);
    } else {
        save_setting($key . '.' . $language, $text);
    }
}

// The site-wide choice: 'full' (the default) or 'first'.
function site_greeting_style(): string
{
    return setting('greeting_style', 'full') === 'first' ? 'first' : 'full';
}

// What to call a guest in emails ({name}) and on their invitation page.
function greeting_name(array $guest): string
{
    $typed = trim((string)($guest['greeting'] ?? ''));
    if ($typed !== '') {
        return $typed;
    }
    $style = in_array($guest['greeting_style'] ?? '', ['full', 'first'], true) ? $guest['greeting_style'] : site_greeting_style();
    return $style === 'first' ? first_names((string)$guest['name']) : (string)$guest['name'];
}

// Splits a line from "Add invitations" into [name, emails]. The addresses
// start at the first part with an @ in it, so a couple can have one each.
//   "Sarah, Tom and Lily; sarah@example.com"             -> email after the semicolon
//   "Sarah & Tom; sarah@example.com; tom@example.com"    -> both addresses
//   "Sarah & Tom, sarah@example.com"                     -> old comma style still works
//   "Sarah, Tom and Lily"                                -> all of it is the name
function split_guest_line(string $line): array
{
    $line = trim($line);
    foreach ([';', ','] as $separator) {
        $parts = explode($separator, $line);
        for ($i = 1; $i < count($parts); $i++) {
            if (strpos($parts[$i], '@') !== false) {
                return [trim(implode($separator, array_slice($parts, 0, $i))), clean_emails(implode(',', array_slice($parts, $i)))];
            }
        }
    }
    if (strpos($line, ';') !== false) {
        // "Name; something" with no @: keep it, so it's flagged as a bad address.
        $at = strrpos($line, ';');
        return [trim(substr($line, 0, $at)), trim(substr($line, $at + 1))];
    }
    return [$line, ''];
}

// Reads an uploaded CSV of invitations into [[name, emails], …].
// One invitation per row: a name, then any email addresses. A first row of
// headings ("Name", "Email", "Email 2"…) is recognised: the name comes from
// the first column headed Name, Guest(s) or Invitation, the addresses from
// every column with "mail" in its heading, and anything else (notes, phone
// numbers) is ignored. Without headings, the first column is the name and any
// other cell containing an @ is an address. Works with commas, semicolons or
// tabs between columns, and with files saved from Excel or Numbers.
// Which older encoding a non-UTF-8 spreadsheet is probably in. Chinese ones
// come in pairs of high bytes. Big5 (Traditional) gives itself away with a
// second byte below 0x7F, or a first byte of A4-AF: common characters in
// Big5, but kana, Greek and box-drawing in GB18030 (Simplified), whose
// characters start at B0. Anything else is taken as Windows-1252, older
// Excel's Western European default.
function legacy_encoding(string $bytes): string
{
    // Accented letters in Windows-1252 are single high bytes ("Renée"), so
    // two high bytes side by side is what marks Chinese.
    $high = preg_match_all('/[\x80-\xFF]/', $bytes);
    $pairs = preg_match_all('/[\xA1-\xFE]{2}/', $bytes);
    if (!$pairs || $pairs * 2 < $high * 0.5) {
        return 'Windows-1252';
    }
    // Walk the characters in step (one byte for ASCII, two otherwise), so a
    // second byte is never mistaken for a first.
    for ($i = 0, $n = strlen($bytes); $i < $n - 1; $i++) {
        $lead = ord($bytes[$i]);
        if ($lead < 0x81) {
            continue;
        }
        $trail = ord($bytes[++$i]);
        if (($trail >= 0x40 && $trail <= 0x7E) || ($lead >= 0xA4 && $lead <= 0xAF && $trail >= 0xA1)) {
            return 'BIG-5';
        }
    }
    return mb_check_encoding($bytes, 'GB18030') ? 'GB18030' : 'BIG-5';
}

function parse_guest_csv(string $csv): array
{
    $csv = (string)preg_replace('/^\xEF\xBB\xBF/', '', $csv);   // Excel's byte-order mark
    if (!preg_match('//u', $csv) && function_exists('mb_convert_encoding')) {
        $csv = (string)mb_convert_encoding($csv, 'UTF-8', legacy_encoding($csv));   // older Excel
    }
    $first = strtok($csv, "\n") ?: '';
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    $delimiter = array_search(max($counts), $counts, true);

    $in = fopen('php://memory', 'r+');
    fwrite($in, $csv);
    rewind($in);
    $rows = [];
    $nameCol = 0;
    $emailCols = null;   // null = no headings: any other cell with an @
    $languageCol = null;
    $line = 0;
    while (($cells = fgetcsv($in, 0, (string)$delimiter, '"', '')) !== false) {
        $cells = array_map(fn($c) => trim((string)$c), $cells);
        $heading = '/^(full |guest |first |party )?(names?|guests?|invit\w*|e-?mails?( address(es)?)?)( ?\d+)?$/i';
        if ($line++ === 0 && !preg_grep('/@/', $cells) && preg_grep($heading, $cells)) {
            $emailCols = array_keys(preg_grep('/mail/i', $cells));
            $languageCol = array_keys(preg_grep('/^(lang(uage)?|idioma|sprache|langue|اللغة|语言|語言)$/iu', $cells))[0] ?? null;
            $named = array_keys(array_diff_key(preg_grep('/name|guest|invit/i', $cells), array_flip($emailCols)));
            $nameCol = $named ? $named[0] : 0;
            continue;
        }
        $name = $cells[$nameCol] ?? '';
        if ($emailCols === null && count($cells) === 1) {
            [$name, $emails] = split_guest_line($name);   // a single column of "Name; email" lines
        } else {
            $emails = [];
            foreach ($cells as $i => $cell) {
                if ($i !== $nameCol && $cell !== '' && ($emailCols === null ? strpos($cell, '@') !== false : in_array($i, $emailCols, true))) {
                    $emails[] = $cell;
                }
            }
            $emails = clean_emails(implode(',', $emails));
        }
        if ($name !== '') {
            $rows[] = $languageCol !== null ? [$name, $emails, language_from_text($cells[$languageCol] ?? '')] : [$name, $emails];
        }
    }
    fclose($in);
    return $rows;
}

// Adds invitations from [[name, emails, language or null], …], each with its own code.
// $skipExisting leaves out names already on the list (so uploading the same
// file twice doesn't double up). Returns [added, names with a bad address,
// names skipped].
function add_guests(array $rows, bool $skipExisting = false): array
{
    // Names compare without caring about capitals (mbstring copes with accents too).
    $key = fn(string $name) => function_exists('mb_strtolower') ? mb_strtolower(trim($name)) : strtolower(trim($name));
    $existing = [];
    if ($skipExisting) {
        foreach (db()->query('SELECT name FROM guests')->fetchAll() as $g) {
            $existing[$key($g['name'])] = true;
        }
    }
    $insert = db()->prepare('INSERT INTO guests (code, name, email, language, language_set_by) VALUES (?, ?, ?, ?, ?)');
    $added = 0;
    $bad = [];
    $skipped = [];
    foreach ($rows as $row) {
        [$name, $emails] = $row;
        $language = valid_language($row[2] ?? null) ? $row[2] : null;
        if (isset($existing[$key($name)])) {
            $skipped[] = $name;
            continue;
        }
        if (has_bad_email($emails)) {
            $bad[] = $name;
        }
        $insert->execute([generate_code(), $name, $emails !== '' ? $emails : null, $language, $language ? 'admin' : null]);
        if ($skipExisting) {
            $existing[$key($name)] = true;   // and a repeat within the same file
        }
        $added++;
    }
    return [$added, $bad, $skipped];
}

// Dietary requirements across everyone coming, for the admin summary.
// A person can pick several, so this counts mentions; people_with is how
// many people (a "Family" party counts once) have at least one.
//   ['mix' => [label => mentions, …] (most common first), 'people_with' => n, 'people_coming' => n]
function dietary_mix(array $guests): array
{
    $canonical = [];
    foreach (DIETARY_OPTIONS as $option) {
        $canonical[strtolower($option)] = $option;
    }
    $mix = [];
    $with = 0;
    $coming = 0;
    foreach ($guests as $g) {
        if (rsvp_status($g) !== 'yes') {
            continue;
        }
        $who = attendees($g);
        $coming += $who['count'];
        foreach (dietary_list($g) as $entry) {
            if ($who['names'] && !in_array($entry['name'], $who['names'], true)) {
                continue;   // not coming
            }
            $picks = array_filter(array_map('trim', explode(',', (string)($entry['needs'] ?? ''))));
            if (!$picks) {
                continue;
            }
            $with++;
            foreach ($picks as $pick) {
                $label = $canonical[strtolower($pick)] ?? ucfirst($pick);
                $mix[$label] = ($mix[$label] ?? 0) + 1;
            }
        }
    }
    uksort($mix, fn($a, $b) => [$mix[$b], $a] <=> [$mix[$a], $b]);
    return ['mix' => $mix, 'people_with' => $with, 'people_coming' => $coming];
}

// Keeps the five most common and folds the rest into "Other" (max 6 segments).
function top_with_other(array $mix, int $keep = 5): array
{
    if (count($mix) <= $keep + 1) {
        return $mix;
    }
    $top = array_slice($mix, 0, $keep, true);
    $top['Other'] = array_sum(array_slice($mix, $keep, null, true));
    return $top;
}

// The donut, drawn as SVG. Each segment is a stroked circle arc with a small
// surface-coloured gap between segments; <title> gives a hover tooltip.
function dietary_donut_svg(array $segments, int $peopleWith, int $peopleComing): string
{
    $colours = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'];   // validated categorical slots 1-5
    $other = '#a8a6a0';
    $total = array_sum($segments);
    $r = 70;
    $circ = 2 * M_PI * $r;
    $gap = count($segments) > 1 ? 2.5 : 0;
    $offset = 0;
    $arcs = '';
    $i = 0;
    foreach ($segments as $label => $count) {
        $len = $count / $total * $circ;
        $colour = $label === 'Other' ? $other : $colours[$i % count($colours)];
        $pct = round($count / $total * 100);
        $arcs .= '<circle class="seg" data-seg="' . $i . '" cx="90" cy="90" r="' . $r . '" fill="none" stroke="' . $colour . '" '
            . 'stroke-width="26" stroke-dasharray="' . round(max($len - $gap, 0.5), 2) . ' ' . round($circ, 2) . '" '
            . 'stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 90 90)">'
            . '<title>' . h(($label === 'Other' ? t('admin.diet.other') : diet_label($label)) . ': ' . $count . ' (' . $pct . '%)') . '</title></circle>';
        $offset += $len;
        $i++;
    }
    return '<svg class="donut" viewBox="0 0 180 180" width="180" height="180" role="img" '
        . 'aria-label="' . th('admin.diet.chart_label') . '">'
        . $arcs
        . '<text x="90" y="88" text-anchor="middle" class="donut-number">' . $peopleWith . '</text>'
        . '<text x="90" y="110" text-anchor="middle" class="donut-caption">' . th('admin.diet.of_coming', ['count' => $peopleComing]) . '</text>'
        . '</svg>';
}

function normalise_code(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

function generate_code(): string
{
    $max = strlen(CODE_ALPHABET) - 1;
    do {
        $code = '';
        for ($i = 0; $i < CODE_LENGTH; $i++) {
            $code .= CODE_ALPHABET[random_int(0, $max)];
        }
        $exists = db()->prepare('SELECT 1 FROM guests WHERE code = ?');
        $exists->execute([$code]);
    } while ($exists->fetchColumn());
    return $code;
}

function invite_link(string $code): string
{
    return SITE_URL . '/?code=' . urlencode($code);
}

// --- CSRF -----------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), input($_POST, 'csrf'))) {
        http_response_code(400);
        exit('Your session expired. Please go back and try again.');
    }
}

// --- Rate limiting for code guesses ---------------------------------

// IPv6 users are usually given a whole /64 block of addresses, so count
// attempts per block; otherwise one person could rotate through billions.
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && substr($packed, 0, 12) !== "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return bin2hex(substr($packed, 0, 8)) . '::/64';
        }
    }
    return $ip;
}

function too_many_attempts(): bool
{
    $window = time() - 15 * 60;
    db()->prepare('DELETE FROM failed_attempts WHERE at < ?')->execute([$window]);
    $q = db()->prepare('SELECT COUNT(*) FROM failed_attempts WHERE ip = ?');
    $q->execute([client_ip()]);
    return (int)$q->fetchColumn() >= MAX_FAILED_ATTEMPTS;
}

function record_failed_attempt(): void
{
    db()->prepare('INSERT INTO failed_attempts (ip, at) VALUES (?, ?)')->execute([client_ip(), time()]);
}

// --- Guest session --------------------------------------------------

function current_guest(): ?array
{
    if (empty($_SESSION['guest_id'])) {
        return null;
    }
    $q = db()->prepare('SELECT * FROM guests WHERE id = ?');
    $q->execute([$_SESSION['guest_id']]);
    return $q->fetch() ?: null;
}

function require_guest(): array
{
    $guest = current_guest();
    if (!$guest) {
        header('Location: /');
        exit;
    }
    return $guest;
}

// Admin sessions end after 2 hours without activity.
function is_admin(): bool
{
    if (empty($_SESSION['is_admin'])) {
        return false;
    }
    if (time() - ($_SESSION['admin_seen'] ?? 0) > 2 * 60 * 60) {
        unset($_SESSION['is_admin'], $_SESSION['admin_seen']);
        return false;
    }
    $_SESSION['admin_seen'] = time();
    return true;
}

// Stop spreadsheet apps treating a cell like "=HYPERLINK(...)" as a formula.
function csv_cell(?string $value): string
{
    $value = (string)$value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

// --- Page chrome ----------------------------------------------------

function page_header(string $title, string $bodyClass = ''): void
{
    ?><!doctype html>
<html lang="<?= h(current_language()) ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<?php if (has_photo() && strpos(' ' . $bodyClass . ' ', ' photo-bg ') !== false): ?>
<?php /* The photo is a CSS background, so let the browser start on it straight away. */ ?>
<link rel="preload" as="image" fetchpriority="high" href="<?= h(asset_url(photo_webp() ?: PHOTO)) ?>"<?= photo_webp() !== '' ? ' type="image/webp"' : '' ?>>
<?php endif; ?>
<link rel="stylesheet" href="<?= h(asset_url('/assets/style.css')) ?>">
<link rel="stylesheet" href="/theme.css.php?v=<?= SITE_VERSION ?>-<?= has_photo() ? filemtime(photo_file(PHOTO)) : 0 ?>-<?= photo_webp() !== '' ? filemtime(photo_file(photo_webp())) : 0 ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<?php
}

// A card heading that doubles as a show/hide button (see guest.js). Without
// JavaScript it's just a heading and the card stays open.
function card_heading(string $title, string $key): string
{
    $chevrons = '<svg class="chevrons" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
        . '<path d="M6 13l6-6 6 6M6 19l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round"/></svg>';
    return '<h2 class="card-heading"><button type="button" class="card-toggle" aria-expanded="true" '
        . 'aria-controls="' . h($key) . '-body"><span>' . h($title) . '</span>' . $chevrons . '</button></h2>';
}

// Small "Admin · v1.0", "Made with ♥ in …" and "© 2026 global.ly" lines at the bottom of the
// guest-facing lightbox.
function lightbox_footer(bool $adminLink = true): string
{
    $made = '';
    if (MADE_IN !== '') {
        $place = MADE_IN_URL !== ''
            ? '<a class="easter-egg" href="' . h(MADE_IN_URL) . '" target="_blank" rel="noopener">' . h(MADE_IN) . '</a>'
            : h(MADE_IN);
        $heart = '<svg class="heart" viewBox="0 0 24 24" width="14" height="14" role="img" aria-label="' . th('footer.love') . '">'
            . '<path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.8 4.5c2.1 0 3.6 1.1 4.2 2.6h2c.6-1.5 2.1-2.6 4.2-2.6 3.8 0 5.9 3.9 4.4 7.3C19.5 16.4 12 21 12 21z" fill="#d6283b"/>'
            . '</svg>';
        $made = '<br><span class="made-with">' . t_html('footer.made_with', ['heart' => $heart, 'place' => $place]) . '</span>';
    }
    $admin = $adminLink ? '<a href="/admin.php">' . th('footer.admin') . '</a> &middot; ' : '';
    // The version and "© year global.ly" stay left-to-right in right-to-left pages.
    return '<p class="site-footer">' . $admin . '<bdi dir="ltr">v' . h(SITE_VERSION) . '</bdi>' . $made
        . '<br><bdi class="copyright" dir="ltr">&copy; ' . date('Y') . ' <a href="' . h(PROJECT_URL) . '" target="_blank" rel="noopener">'
        . h(PROJECT_NAME) . '</a></bdi></p>' . language_menu();
}

function page_footer(): void
{
    echo "</body>\n</html>\n";
}

require_once __DIR__ . '/photo.php';
require_once __DIR__ . '/i18n.php';
