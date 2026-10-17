<?php
// Update emails ("notifications") sent from the admin Updates tab to guests
// who haven't opted out. Needs mailer.php.

function notify_db(): PDO
{
    static $ready = false;
    $pdo = db();
    if (!$ready) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS email_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            subject TEXT NOT NULL,
            body TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            audience TEXT NOT NULL,
            sent INTEGER NOT NULL,
            failed INTEGER NOT NULL,
            sent_by TEXT,
            sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $columns = $pdo->query('PRAGMA table_info(email_templates)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('translations', $columns, true)) {
            // Other languages' versions: {"es": {"subject": …, "body": …}, …}
            $pdo->exec('ALTER TABLE email_templates ADD COLUMN translations TEXT');
        }
        if (!$pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn()
            && !$pdo->query("SELECT value FROM settings WHERE key = 'templates_seeded'")->fetchColumn()) {
            $insert = $pdo->prepare('INSERT INTO email_templates (name, subject, body, translations) VALUES (?, ?, ?, ?)');
            foreach (starter_templates() as $t) {
                $insert->execute([$t['name'], $t['subject'], $t['body'], json_encode($t['translations'], JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('templates_seeded', '1')");
        }
        // Sites from before translations: give the starter templates they
        // haven't changed their translations.
        if (!$pdo->query("SELECT value FROM settings WHERE key = 'templates_translated'")->fetchColumn()) {
            translate_starter_templates($pdo);
            $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('templates_translated', '1')");
        }
        $ready = true;
    }
    return $pdo;
}

// Gives starter templates saved before translations existed, and not changed
// since, the starters' translations.
function translate_starter_templates(PDO $pdo): void
{
    $update = $pdo->prepare('UPDATE email_templates SET translations = ? WHERE id = ?');
    foreach ($pdo->query('SELECT * FROM email_templates WHERE translations IS NULL')->fetchAll() as $row) {
        foreach (starter_templates() as $t) {
            if ($row['subject'] === $t['subject'] && str_replace("\r\n", "\n", $row['body']) === $t['body']) {
                $update->execute([json_encode($t['translations'], JSON_UNESCAPED_UNICODE), $row['id']]);
            }
        }
    }
}

// The starter templates in the site's language, each with its translations.
function starter_templates(): array
{
    $one = function (string $key, string $language): array {
        return in_language($language, fn() => [
            'name' => t("$key.name"),
            'subject' => t("$key.subject", ['couple' => COUPLE_NAMES]),
            'body' => t("$key.body", ['couple' => COUPLE_NAMES]),
        ]);
    };
    $out = [];
    foreach (['tpl.reminder', 'tpl.hotels', 'tpl.timings', 'tpl.nudge', 'tpl.blank'] as $key) {
        $t = $one($key, site_language());
        $t['translations'] = [];
        foreach (site_languages() as $code) {
            if ($code !== site_language()) {
                $tr = $one($key, $code);
                $t['translations'][$code] = ['subject' => $tr['subject'], 'body' => $tr['body']];
            }
        }
        $out[] = $t;
    }
    return $out;
}

// A template's other-language versions: [code => ['subject' => …, 'body' => …]].
function template_translations(?array $template): array
{
    $tr = json_decode((string)($template['translations'] ?? ''), true);
    return is_array($tr) ? clean_translations($tr) : [];
}

// Translations as sent from a form or stored: only offered languages, only
// text, one-line subjects, and languages with nothing in them dropped.
function clean_translations($raw): array
{
    $out = [];
    foreach ((array)$raw as $code => $t) {
        if (!valid_language((string)$code) || $code === site_language() || !is_array($t)) {
            continue;
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', is_string($t['subject'] ?? null) ? $t['subject'] : ''));
        $body = trim(str_replace("\r\n", "\n", is_string($t['body'] ?? null) ? $t['body'] : ''));
        if ($subject !== '' || $body !== '') {
            $out[$code] = ['subject' => $subject, 'body' => $body];
        }
    }
    return $out;
}

function all_templates(): array
{
    return notify_db()->query('SELECT * FROM email_templates ORDER BY id')->fetchAll();
}

function find_template(int $id): ?array
{
    $q = notify_db()->prepare('SELECT * FROM email_templates WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

// Who an update can go to. Guests who opted out, or have no valid email
// address, are always left out.
function audiences(): array
{
    return [
        'all' => 'Everyone',
        'coming' => 'Coming',
        'coming_or_maybe' => 'Coming or not sure yet',
        'no_reply' => 'Not replied yet',
        'maybe' => 'Not sure yet',
    ];
}

function audience_guests(string $audience): array
{
    $statuses = [
        'all' => ['yes', 'no', 'maybe', 'waiting'],
        'coming' => ['yes'],
        'coming_or_maybe' => ['yes', 'maybe'],
        'no_reply' => ['waiting'],
        'maybe' => ['maybe'],
    ][$audience] ?? [];
    return array_values(array_filter(
        db()->query('SELECT * FROM guests WHERE notify = 1 ORDER BY name COLLATE NOCASE')->fetchAll(),
        fn($g) => sendable_emails($g['email']) && in_array(rsvp_status($g), $statuses, true)
    ));
}

function unsubscribe_link(string $code): string
{
    return SITE_URL . '/unsubscribe.php?code=' . urlencode($code);
}

// Renders an update for one guest: [subject, text, html, extra headers], in
// the current language (render inside in_language()).
function render_update(array $guest, string $subject, string $body, string $imageSrc = 'cid:' . EMAIL_IMAGE_CID): array
{
    $unsub = unsubscribe_link($guest['code']);
    $footerHtml = '<br><br>' . th('email.updates_reason') . ' '
        . '<a href="' . h($unsub) . '" style="color:#8a6552">' . th('email.stop_updates') . '</a>';
    $footerText = t('email.updates_reason') . ' ' . t('email.stop_updates') . ": $unsub";
    [$subj, $text, $html] = render_email($guest, $subject, $body, $imageSrc,
        th('email.news_from', ['couple' => COUPLE_NAMES]), $footerHtml, $footerText);
    // Lets Gmail, Apple Mail etc. show their own one-click unsubscribe button.
    $headers = ['List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'];
    return [$subj, $text, $html, $headers];
}

// The version of an update for one language: [subject, text], from
// $translations where written, otherwise the main subject and text.
function update_version(string $language, string $subject, string $body, array $translations): array
{
    $mine = $translations[$language] ?? [];
    return [($mine['subject'] ?? '') !== '' ? $mine['subject'] : $subject, ($mine['body'] ?? '') !== '' ? $mine['body'] : $body];
}

// Sends the update to each of the guest's addresses, in their language. True
// only if every address got it.
function send_update(array $guest, string $subject, string $body, array $translations = []): bool
{
    $language = guest_email_language($guest);
    [$subject, $body] = update_version($language, $subject, $body, $translations);
    [$subj, $text, $html, $headers] = in_language($language, fn() => render_update($guest, $subject, $body));
    $to = sendable_emails($guest['email']);
    $sent = 0;
    foreach ($to as $address) {
        $sent += send_email($address, $guest['name'], $subj, $text, $html, mail_settings(), $headers) ? 1 : 0;
    }
    return $to && $sent === count($to);
}

function log_notification(string $subject, string $audience, int $sent, int $failed, string $by): void
{
    notify_db()->prepare('INSERT INTO notifications (subject, audience, sent, failed, sent_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$subject, $audience, $sent, $failed, $by]);
}

function recent_notifications(int $limit = 20): array
{
    return notify_db()->query('SELECT * FROM notifications ORDER BY id DESC LIMIT ' . (int)$limit)->fetchAll();
}
