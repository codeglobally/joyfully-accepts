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
    $insert->execute(['The day',
        "**" . WEDDING_DATE . "**\n\n" . WEDDING_PLACE . "\n[Open in Maps]($map)\n\n"
        . "Add the timings for the day here, e.g. ceremony at 3pm, followed by drinks, dinner and dancing.", 1, 1]);
    $insert->execute(['Where to stay', section_templates()['hotels']['body'], 0, 2]);
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

// Starting points offered by "Add a section".
function section_templates(): array
{
    return [
        'hotels' => ['label' => 'Where to stay', 'title' => 'Where to stay', 'body' =>
            "We've found a few places to stay nearby.\n\n"
            . "## Hotel name\n10 minutes from the venue. Mention our wedding for the group rate.\n[Book a room](https://example.com)\n\n"
            . "## Another place\nA short description.\n[Website](https://example.com)"],
        'things' => ['label' => 'Things to do', 'title' => 'Things to do', 'body' =>
            "Making a weekend of it? Some of our favourite things nearby:\n\n"
            . "- **A beach or walk** - why you'll love it\n- **A pub or restaurant** - what to order\n- **A day out** - good for families"],
        'travel' => ['label' => 'Getting there', 'title' => 'Getting there', 'body' =>
            "## By car\nThere's parking at the venue.\n\n"
            . "## By train\nThe nearest station is ... We'll arrange taxis from there.\n\n"
            . "## Taxis\nLocal firms: ..."],
        'gifts' => ['label' => 'Gifts', 'title' => 'Gifts', 'body' =>
            "Having you there is the best gift. If you'd like to give something, ..."],
        'faq' => ['label' => 'FAQs', 'title' => 'Questions', 'body' =>
            "## Is there a dress code?\n...\n\n## Can I bring children?\n...\n\n## When should I reply by?\n..."],
        'blank' => ['label' => 'Blank section', 'title' => '', 'body' => ''],
    ];
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
