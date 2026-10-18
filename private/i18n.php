<?php
// Languages. Everything a guest or admin reads goes through t(), which looks
// the phrase up in private/lang/<code>.php for the current language and falls
// back to English. Phrase files other than English are drafts: corrections
// are welcome through the "Translation correction" issue form.
//
// Three settings decide which language is used:
//   SITE_LANGUAGE   the couple's own language (config.php): what their content
//                   is written in, and the fallback for anything untranslated
//   each guest's    saved on their invitation, from their browser on their
//   language        first visit or chosen from the footer
//   each admin's    chosen on "Your account"

const LANGUAGE_NAMES = [
    'en' => 'English',
    'es' => 'Español',
    'de' => 'Deutsch',
    'fr' => 'Français',
    'ar' => 'العربية',
    'zh-Hans' => '简体中文',
    'zh-Hant' => '繁體中文',
];
const RTL_LANGUAGES = ['ar'];

// The languages this site offers: LANGUAGES from config.php, kept to ones
// there are phrase files for, always including SITE_LANGUAGE.
function site_languages(): array
{
    $offered = array_values(array_filter((array)LANGUAGES, fn($code) => isset(LANGUAGE_NAMES[$code])));
    $main = site_language();
    return in_array($main, $offered, true) ? $offered : array_merge([$main], $offered);
}

function site_language(): string
{
    return isset(LANGUAGE_NAMES[SITE_LANGUAGE]) ? SITE_LANGUAGE : 'en';
}

function valid_language(?string $code): bool
{
    return $code !== null && in_array($code, site_languages(), true);
}

// The language for this request, and changing it (also used to render an
// email in its recipient's language, then switch back).
function current_language(): string
{
    return $GLOBALS['current_language'] ?? site_language();
}

function set_language(string $code): void
{
    $GLOBALS['current_language'] = isset(LANGUAGE_NAMES[$code]) ? $code : site_language();
}

// Runs $fn with another language switched on, then switches back.
function in_language(string $code, callable $fn)
{
    $before = current_language();
    set_language($code);
    try {
        return $fn();
    } finally {
        set_language($before);
    }
}

function is_rtl(?string $code = null): bool
{
    return in_array($code ?? current_language(), RTL_LANGUAGES, true);
}

// --- Choosing a language from the browser --------------------------------
// Picks the best of $available from an Accept-Language header such as
// "en-GB,en;q=0.9,fr;q=0.8", or null if none match. Chinese is matched by
// script: zh-TW, zh-HK and zh-MO read Traditional; zh, zh-CN and zh-SG Simplified.
function negotiate_language(string $header, array $available): ?string
{
    $wanted = [];
    foreach (explode(',', $header) as $i => $part) {
        $bits = array_map('trim', explode(';', $part));
        $tag = strtolower($bits[0]);
        if ($tag === '' || !preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$|^\*$/', $tag)) {
            continue;
        }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $param) {
            if (preg_match('/^q=([01](?:\.\d{0,3})?)$/', $param, $m)) {
                $q = (float)$m[1];
            }
        }
        if ($q > 0) {
            $wanted[] = [$tag, $q, $i];
        }
    }
    // Highest q first; ties keep the browser's order.
    usort($wanted, fn($a, $b) => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
    foreach ($wanted as [$tag]) {
        $code = language_for_tag($tag);
        if ($code !== null && in_array($code, $available, true)) {
            return $code;
        }
    }
    return null;
}

// "en-GB" -> en, "zh-tw" -> zh-Hant, "zh-hans-cn" -> zh-Hans.
function language_for_tag(string $tag): ?string
{
    $parts = explode('-', strtolower($tag));
    if ($parts[0] === 'zh') {
        $rest = array_slice($parts, 1);
        if (array_intersect($rest, ['hant', 'tw', 'hk', 'mo'])) {
            return 'zh-Hant';
        }
        return 'zh-Hans';
    }
    foreach (array_keys(LANGUAGE_NAMES) as $code) {
        if ($parts[0] === $code) {
            return $code;
        }
    }
    return null;
}

// A language typed in a spreadsheet: a code ("es", "zh-TW"), its own name
// ("Español") or its English name ("Spanish"). Null if it isn't one we have.
function language_from_text(string $text): ?string
{
    $text = trim($text);
    $folded = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    $english = ['english' => 'en', 'spanish' => 'es', 'german' => 'de', 'french' => 'fr', 'arabic' => 'ar',
        'chinese' => 'zh-Hans', 'simplified chinese' => 'zh-Hans', 'traditional chinese' => 'zh-Hant'];
    foreach (LANGUAGE_NAMES as $code => $name) {
        if ($folded === (function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name))) {
            return $code;
        }
    }
    if (isset($english[$folded])) {
        return $english[$folded];
    }
    return preg_match('/^[a-z]{2,3}([-_][a-z0-9]{2,8})*$/i', $text) ? language_for_tag(str_replace('_', '-', $text)) : null;
}

function browser_language(): ?string
{
    return negotiate_language((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), site_languages());
}

// --- Phrases --------------------------------------------------------------

function phrases(string $code): array
{
    static $loaded = [];
    if (!isset($loaded[$code])) {
        // Guest phrases in lang/xx.php, the admin area's in lang/admin/xx.php.
        $loaded[$code] = [];
        foreach ([__DIR__ . "/lang/$code.php", __DIR__ . "/lang/admin/$code.php"] as $file) {
            if (isset(LANGUAGE_NAMES[$code]) && is_file($file)) {
                $loaded[$code] += (array)require $file;
            }
        }
    }
    return $loaded[$code];
}

// The phrase for $key in the current language (English if it's missing),
// with {placeholders} filled in from $vars. Returns plain text: escape it
// with h() like anything else. A 'count' in $vars picks the plural form.
function t(string $key, array $vars = []): string
{
    $code = current_language();
    $phrase = phrases($code)[$key] ?? phrases('en')[$key] ?? $key;
    if (is_array($phrase)) {
        $count = (int)($vars['count'] ?? 0);
        $phrase = $phrase[plural_form($code, $count)] ?? $phrase['other'] ?? reset($phrase);
    }
    if (isset($vars['count'])) {
        $vars['count'] = number_text((int)$vars['count']);
    }
    $replace = [];
    foreach ($vars as $name => $value) {
        $replace['{' . $name . '}'] = (string)$value;
    }
    return strtr((string)$phrase, $replace);
}

// Like t(), escaped for HTML, for the common case in templates.
function th(string $key, array $vars = []): string
{
    return h(t($key, $vars));
}

// CLDR plural categories for the languages we have.
function plural_form(string $code, int $n): string
{
    switch ($code) {
        case 'ar':
            $mod = $n % 100;
            if ($n === 0) return 'zero';
            if ($n === 1) return 'one';
            if ($n === 2) return 'two';
            if ($mod >= 3 && $mod <= 10) return 'few';
            if ($mod >= 11 && $mod <= 99) return 'many';
            return 'other';
        case 'fr':
            return $n === 0 || $n === 1 ? 'one' : 'other';
        case 'zh-Hans':
        case 'zh-Hant':
            return 'other';
        default:
            return $n === 1 ? 'one' : 'other';
    }
}

// --- Numbers and dates ---------------------------------------------------

// Digits as the current language writes them (Arabic can use ٠-٩, see
// ARABIC_DIGITS in config.php).
function number_text($value): string
{
    $text = (string)$value;
    if (current_language() === 'ar' && ARABIC_DIGITS === 'eastern') {
        $text = strtr($text, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
    return $text;
}

// A date in the current language: "Saturday 9 September 2028",
// "sábado, 9 de septiembre de 2028", "2028年9月9日 星期六" and so on.
function format_date(DateTimeInterface $date): string
{
    $days = (array)t_list('date.weekdays');
    $months = (array)t_list('date.months');
    return t('date.long', [
        'weekday' => $days[(int)$date->format('w')] ?? $date->format('l'),
        'day' => number_text($date->format('j')),
        'month' => $months[(int)$date->format('n') - 1] ?? $date->format('F'),
        'year' => number_text($date->format('Y')),
    ]);
}

// A short date for admin tables, such as "9 Sep" or "9 Sep 2028, 14:05".
// $style: 'day', 'year', 'time' (day and time), 'full' (with year and time)
// or 'weekday'.
function admin_date(int $time, string $style = 'day'): string
{
    $months = t_list('date.months_short');
    $vars = ['day' => number_text(date('j', $time)), 'month' => $months[(int)date('n', $time) - 1] ?? date('M', $time),
        'year' => number_text(date('Y', $time))];
    $date = t(in_array($style, ['year', 'full'], true) ? 'date.day_month_year' : 'date.day_month', $vars);
    if ($style === 'weekday') {
        return t('date.with_weekday', ['weekday' => t_list('date.weekdays_short')[(int)date('w', $time)] ?? '', 'date' => $date]);
    }
    return in_array($style, ['time', 'full'], true) ? t('date.with_time', ['date' => $date, 'time' => date('H:i', $time)]) : $date;
}

// "←" for "Back to …" links, pointing the other way in right-to-left text.
function back_arrow(): string
{
    return is_rtl() ? '&rarr;' : '&larr;';
}

// A list of names joined in the current language's way ("a, b" / "a、b").
function join_names(array $names, int $max = 0): string
{
    $more = $max && count($names) > $max ? count($names) - $max : 0;
    $list = implode(t('admin.comma'), $more ? array_slice($names, 0, $max) : $names);
    return $more ? t('admin.and_more', ['list' => $list, 'count' => $more]) : $list;
}

// A list-valued phrase (weekday and month names), falling back to English.
function t_list(string $key): array
{
    $value = phrases(current_language())[$key] ?? phrases('en')[$key] ?? [];
    return is_array($value) ? array_values($value) : [];
}

// The wedding date for display. In the site's own language it's exactly as
// written in config.php; in other languages it's formatted from that date
// (or, if it can't be read as a date, shown as written).
function wedding_date_text(): string
{
    $time = strtotime(WEDDING_DATE);
    if (current_language() === site_language() || !$time) {
        return WEDDING_DATE;
    }
    return format_date((new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Europe/London')));
}

// --- Choosing and remembering ---------------------------------------------
// A guest's language: saved on their invitation; until then (and before they
// sign in) a cookie from the footer switcher, then their browser, then the
// site's own language.

const LANGUAGE_COOKIE = 'lang';

function cookie_language(): ?string
{
    $code = (string)($_COOKIE[LANGUAGE_COOKIE] ?? '');
    return valid_language($code) ? $code : null;
}

function remember_language_cookie(string $code): void
{
    setcookie(LANGUAGE_COOKIE, $code, [
        'expires' => time() + 365 * 86400,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// The language for a visitor who isn't signed in.
function visitor_language(): string
{
    return cookie_language() ?? browser_language() ?? site_language();
}

// The language for a signed-in guest, saving one on their invitation the
// first time (from the switcher cookie or their browser).
function guest_language(array &$guest): string
{
    if (valid_language($guest['language'] ?? null)) {
        return $guest['language'];
    }
    $code = cookie_language();
    $by = $code !== null ? 'guest' : 'browser';
    $code = $code ?? browser_language() ?? site_language();
    db()->prepare('UPDATE guests SET language = ?, language_set_by = ? WHERE id = ?')->execute([$code, $by, $guest['id']]);
    $guest['language'] = $code;
    $guest['language_set_by'] = $by;
    return $code;
}

// The language to email a guest in: theirs if known, otherwise the site's.
function guest_email_language(array $guest): string
{
    return valid_language($guest['language'] ?? null) ? $guest['language'] : site_language();
}

// A phrase with pieces of HTML dropped in, such as the heart in "Made with
// {heart} in {place}". The phrase itself is escaped; $html values are not, so
// only pass HTML built by the site.
function t_html(string $key, array $html = [], array $vars = []): string
{
    $marks = [];
    foreach (array_keys($html) as $i => $name) {
        $vars[$name] = "\x01" . $i . "\x01";
        $marks["\x01" . $i . "\x01"] = $html[$name];
    }
    return strtr(h(t($key, $vars)), $marks);
}

// The language menu at the bottom of guest pages: each language in its own
// name and script. Posts to /language.php, which remembers the choice.
function language_menu(): string
{
    $codes = site_languages();
    if (count($codes) < 2) {
        return '';
    }
    $here = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $globe = '<svg class="icon" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false" fill="none" '
        . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/>'
        . '<path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>';
    $out = '<details class="language-menu"><summary aria-label="' . th('language.menu') . ': ' . h(LANGUAGE_NAMES[current_language()]) . '">'
        . $globe . ' <span lang="' . h(current_language()) . '">' . h(LANGUAGE_NAMES[current_language()]) . '</span></summary>'
        . '<form method="post" action="/language.php" class="language-list">' . csrf_field()
        . '<input type="hidden" name="back" value="' . h($here) . '">';
    foreach ($codes as $code) {
        $out .= '<button type="submit" name="lang" value="' . h($code) . '" lang="' . h($code) . '"'
            . (in_array($code, RTL_LANGUAGES, true) ? ' dir="rtl"' : '')
            . ($code === current_language() ? ' aria-current="true"' : '') . '>' . h(LANGUAGE_NAMES[$code]) . '</button>';
    }
    return $out . '</form></details>';
}

// True if there's a phrase for $key (in the current language or English).
function t_has(string $key): bool
{
    return isset(phrases(current_language())[$key]) || isset(phrases('en')[$key]);
}

// A dietary option shown in the current language. Options are saved in
// English so the caterer's list stays readable; anything a guest typed is
// shown as they typed it.
function diet_label(string $option): string
{
    return t_has('diet.' . $option) ? t('diet.' . $option) : $option;
}

// Phrases the page's JavaScript needs, as a data attribute on a hidden element:
// <div id="phrases" hidden data-phrases='{"chips.add":"Add “{text}”",…}'>.
function js_phrases(array $keys): string
{
    $out = [];
    foreach ($keys as $key) {
        // Plural phrases go as their forms; the script picks one with Intl.PluralRules.
        $phrase = phrases(current_language())[$key] ?? phrases('en')[$key] ?? $key;
        $out[$key] = is_array($phrase) && isset($phrase['other']) ? $phrase : t($key);
    }
    return '<div id="phrases" hidden data-phrases="' . h((string)json_encode($out, JSON_UNESCAPED_UNICODE)) . '"></div>';
}
