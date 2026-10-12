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
        if (!$pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn()
            && !$pdo->query("SELECT value FROM settings WHERE key = 'templates_seeded'")->fetchColumn()) {
            $insert = $pdo->prepare('INSERT INTO email_templates (name, subject, body) VALUES (?, ?, ?)');
            foreach (starter_templates() as $t) {
                $insert->execute($t);
            }
            $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('templates_seeded', '1')");
        }
        $ready = true;
    }
    return $pdo;
}

function starter_templates(): array
{
    $sign = "\n\nWith love,\n" . COUPLE_NAMES;
    return [
        ['Reminder to reply', 'Will you be joining us? ' . COUPLE_NAMES,
            "Dear {name},\n\nJust a gentle reminder to let us know if you can make it to our wedding.\n\n{link}\n\n"
            . "Your invitation code is {code}." . $sign],
        ['Hotels and travel', 'Where to stay for our wedding',
            "Dear {name},\n\nWe've added hotel and travel details to the wedding website.\n\n{link}\n\n"
            . "Your invitation code is {code}, in case you need it." . $sign],
        ['Timings for the day', 'Timings for the big day',
            "Dear {name},\n\nThe timings for the day are now on the wedding website.\n\n{link}\n\n"
            . "Your invitation code is {code}." . $sign],
        ['Not sure yet nudge', 'Any news? ' . COUPLE_NAMES,
            "Dear {name},\n\nYou said you'd let us know if you can come. No rush, but when you know, you can update your reply here:\n\n{link}\n\n"
            . "Your invitation code is {code}." . $sign],
        ['Blank', '', "Dear {name},\n\n\n\n{link}\n\nYour invitation code is {code}." . $sign],
    ];
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
        fn($g) => valid_email($g['email']) && in_array(rsvp_status($g), $statuses, true)
    ));
}

function unsubscribe_link(string $code): string
{
    return SITE_URL . '/unsubscribe.php?code=' . urlencode($code);
}

// Renders an update for one guest: [subject, text, html, extra headers].
function render_update(array $guest, string $subject, string $body, string $imageSrc = 'cid:' . EMAIL_IMAGE_CID): array
{
    $unsub = unsubscribe_link($guest['code']);
    $footerHtml = '<br><br>You&rsquo;re getting this because you&rsquo;re invited to our wedding. '
        . '<a href="' . h($unsub) . '" style="color:#8a6552">Stop these updates</a>';
    $footerText = "You're getting this because you're invited to our wedding. Stop these updates: $unsub";
    [$subj, $text, $html] = render_email($guest, $subject, $body, $imageSrc,
        'News from ' . h(COUPLE_NAMES), $footerHtml, $footerText);
    // Lets Gmail, Apple Mail etc. show their own one-click unsubscribe button.
    $headers = ['List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'];
    return [$subj, $text, $html, $headers];
}

function send_update(array $guest, string $subject, string $body): bool
{
    [$subj, $text, $html, $headers] = render_update($guest, $subject, $body);
    return send_email((string)$guest['email'], $guest['name'], $subj, $text, $html, mail_settings(), $headers);
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
