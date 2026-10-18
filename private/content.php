<?php
// Guest-page content sections, edited in admin on the Content tab.
// Bodies are written in a small, safe Markdown subset; see render_markdown().

function content_db(): PDO
{
    static $ready = false;
    $pdo = db();
    if (!$ready) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT \'\',
            visible INTEGER NOT NULL DEFAULT 1,
            position INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by TEXT
        )');
        // Other languages' versions of a section (the main language is in sections).
        $pdo->exec('CREATE TABLE IF NOT EXISTS section_translations (
            section_id INTEGER NOT NULL,
            language TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT \'\',
            body TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by TEXT,
            PRIMARY KEY (section_id, language)
        )');
        seed_sections($pdo);
        $ready = true;
    }
    return $pdo;
}

// First run only: bring over what used to live in private/sections/.
function seed_sections(PDO $pdo): void
{
    $done = $pdo->query("SELECT value FROM settings WHERE key = 'sections_seeded'")->fetchColumn();
    if ($done) {
        return;
    }
    $map = 'https://www.google.com/maps/search/?api=1&query=' . urlencode(WEDDING_PLACE);
    $insert = $pdo->prepare('INSERT INTO sections (title, body, visible, position) VALUES (?, ?, ?, ?)');
    [$dayTitle, $dayBody] = in_language(site_language(), fn() => [t('admin.stpl.day.title'),
        t('admin.stpl.day.body', ['date' => WEDDING_DATE, 'place' => WEDDING_PLACE, 'map' => $map])]);
    $insert->execute([$dayTitle, $dayBody, 1, 1]);
    $hotels = section_templates()['hotels'];
    $insert->execute([$hotels['title'], $hotels['body'], 0, 2]);
    $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('sections_seeded', '1')");
}

function all_sections(bool $visibleOnly = false): array
{
    $sql = 'SELECT * FROM sections' . ($visibleOnly ? ' WHERE visible = 1' : '') . ' ORDER BY position, id';
    return content_db()->query($sql)->fetchAll();
}

function find_section(int $id): ?array
{
    $q = content_db()->prepare('SELECT * FROM sections WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

// --- Translations ----------------------------------------------------------
// Each section is written in the site's own language; other languages can have
// their own title and text. Anything left empty shows the main-language version.

function section_translation(int $id, string $language): ?array
{
    $q = content_db()->prepare('SELECT * FROM section_translations WHERE section_id = ? AND language = ?');
    $q->execute([$id, $language]);
    return $q->fetch() ?: null;
}

// The languages a section has been translated into.
function section_languages(int $id): array
{
    $q = content_db()->prepare('SELECT language FROM section_translations WHERE section_id = ? ORDER BY language');
    $q->execute([$id]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

// Saves a translation; both empty removes it.
function save_section_translation(int $id, string $language, string $title, string $body, string $by): void
{
    if ($title === '' && $body === '') {
        content_db()->prepare('DELETE FROM section_translations WHERE section_id = ? AND language = ?')->execute([$id, $language]);
        return;
    }
    content_db()->prepare('INSERT OR REPLACE INTO section_translations (section_id, language, title, body, updated_at, updated_by)
                           VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)')->execute([$id, $language, $title, $body, $by]);
}

// Sections as a guest reading $language sees them.
function localise_sections(array $sections, string $language): array
{
    if ($language === site_language()) {
        return $sections;
    }
    foreach ($sections as $i => $s) {
        $t = section_translation((int)$s['id'], $language);
        if ($t) {
            $sections[$i]['title'] = $t['title'] !== '' ? $t['title'] : $s['title'];
            $sections[$i]['body'] = $t['body'] !== '' ? $t['body'] : $s['body'];
        }
    }
    return $sections;
}

// Starting points offered by "Add a section".
function section_templates(): array
{
    // Labels in the admin's language; the starting text in the site's, as it
    // becomes the section's main version.
    $out = [];
    foreach (['hotels', 'things', 'travel', 'gifts', 'faq', 'blank'] as $key) {
        $out[$key] = ['label' => t("admin.stpl.$key.label")] + ($key === 'blank' ? ['title' => '', 'body' => ''] : in_language(site_language(),
            fn() => ['title' => t("admin.stpl.$key.title"), 'body' => t("admin.stpl.$key.body")]));
    }
    return $out;
}

// Markdown subset, safe by construction: everything is HTML-escaped first,
// then only these patterns are turned back into tags:
//   blank line        new paragraph         ## Heading   subheading
//   - item / * item   bullet list           **bold**  *italic*
//   [text](https://…) link (http, https and mailto only)
//   [small]…[/small]  [large]…[/large]  [xlarge]…[/xlarge]   text size
function render_markdown(string $text): string
{
    $html = '';
    $blocks = preg_split('/\n\s*\n/', trim(str_replace("\r", '', $text)));
    foreach ($blocks as $block) {
        $lines = explode("\n", trim($block));
        if ($lines === ['']) {
            continue;
        }
        // A block may start with a heading line, followed by normal text.
        if (preg_match('/^#{2,3}\s+(.*)$/', $lines[0], $m)) {
            $html .= '<h3>' . md_inline($m[1]) . '</h3>';
            array_shift($lines);
            if (!$lines) {
                continue;
            }
        }
        if (count(preg_grep('/^\s*[-*]\s+/', $lines)) === count($lines)) {
            $items = array_map(fn($l) => '<li>' . md_inline(preg_replace('/^\s*[-*]\s+/', '', $l)) . '</li>', $lines);
            $html .= '<ul>' . implode('', $items) . '</ul>';
        } else {
            $html .= '<p>' . implode('<br>', array_map('md_inline', $lines)) . '</p>';
        }
    }
    return $html;
}

function md_inline(string $text): string
{
    $s = h($text);
    $s = preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:)[^\s)]+)\)/', function ($m) {
        $external = strpos($m[2], 'mailto:') !== 0;
        return '<a href="' . $m[2] . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
    }, $s);
    // Keep UK postcodes (e.g. SW1A 1AA) on one line.
    $s = preg_replace('/\b([A-Z]{1,2}\d[A-Z\d]?) (\d[A-Z]{2})\b/', "$1\u{00A0}$2", $s);
    $s = preg_replace('/\[(small|large|xlarge)\](.+?)\[\/\1\]/', '<span class="text-$1">$2</span>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $s);
    return $s;
}
