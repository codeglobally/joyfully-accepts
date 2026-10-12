<?php
// Invitation emails. The template lives in the settings table and is
// filled in per guest; {name}, {link} and {code} are replaced.

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

function mail_settings(): array
{
    $host = preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST));
    $date = preg_replace('/^\w+day /', '', WEDDING_DATE);
    return [
        'from_name' => setting('mail_from_name', COUPLE_NAMES),
        'from'      => setting('mail_from', 'invitations@' . $host),
        'reply_to'  => setting('mail_reply_to'),
        'subject'   => setting('mail_subject', "You're invited: " . COUPLE_NAMES . ', ' . $date),
        'body'      => setting('mail_body', "Dear {name},\n\n"
            . "We're getting married, and we'd love you to be there.\n\n"
            . "Please let us know if you can come by visiting our wedding website:\n\n"
            . "{link}\n\n"
            . "Your invitation code is {code}, in case you need to type it in.\n\n"
            . "We'll add details about the day, hotels and travel to the site over the coming months.\n\n"
            . "With love,\n" . COUPLE_NAMES),
    ];
}

function valid_email(?string $email): bool
{
    return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// The photo at the top of the email. It's embedded in the email itself
// (so mail apps show it without asking to "load images"); the preview in
// admin loads it from the website instead.
const EMAIL_IMAGE_CID = 'couple@wedding';

function email_image_path(): string
{
    return email_photo_url() !== '' ? photo_file(email_photo_url()) : '';
}

// Returns [subject, plain text, html] for one guest. $imageSrc is where the
// html loads the photo from: the embedded copy by default, or a URL.
function render_invite(array $guest, array $settings, string $imageSrc = 'cid:' . EMAIL_IMAGE_CID): array
{
    return render_email($guest, $settings['subject'], $settings['body'], $imageSrc, 'You&rsquo;re invited');
}

// The branded email used for invitations and updates. $eyebrow is the small
// heading above the names (already HTML). $footerHtml / $footerText are
// added at the bottom, e.g. an unsubscribe link.
function render_email(array $guest, string $subjectTemplate, string $bodyTemplate,
                      string $imageSrc = 'cid:' . EMAIL_IMAGE_CID, string $eyebrow = 'You&rsquo;re invited',
                      string $footerHtml = '', string $footerText = ''): array
{
    $settings = ['subject' => $subjectTemplate, 'body' => $bodyTemplate];
    if (email_photo_url() === '') {
        $imageSrc = '';   // no photo configured
    }
    $link = invite_link($guest['code']);
    $vars = ['{name}' => $guest['name'], '{link}' => $link, '{code}' => $guest['code']];

    $subject = str_replace(["\r", "\n"], ' ', strtr($settings['subject'], $vars));
    $text = strtr($settings['body'], $vars) . ($footerText !== '' ? "\n\n--\n" . $footerText : '');

    $font = "'Helvetica Neue',Helvetica,Arial,sans-serif";
    $button = '</p><table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px">'
        . '<tr><td style="background:#c2410c;border-radius:8px">'
        . '<a href="' . h($link) . '" style="display:inline-block;padding:15px 32px;font:500 16px ' . $font
        . ';color:#ffffff;text-decoration:none;border-radius:8px">View your invitation</a>'
        . '</td></tr></table><p style="margin:0 0 16px">';
    $code = '<span style="display:inline-block;padding:2px 10px;background:#fff7f1;border:1px solid #f3dccb;'
        . 'border-radius:6px;font-weight:600;letter-spacing:0.12em;color:#2a211c">' . h($guest['code']) . '</span>';
    // Blank lines in the message become paragraphs; single line breaks stay as breaks.
    $paragraphs = preg_split('/\n\s*\n/', trim(str_replace("\r", '', $settings['body'])));
    $body = '';
    foreach ($paragraphs as $para) {
        $body .= '<p style="margin:0 0 16px">' . nl2br(strtr(h($para), [
            '{name}' => h($guest['name']),
            '{code}' => $code,
            '{link}' => $button,
        ])) . '</p>';
    }
    $body = str_replace('<p style="margin:0 0 16px"></p>', '', $body);

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light only"><title>' . h($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#fff7f1">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff7f1">'
        . '<tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden">'
        // Photo
        . ($imageSrc === '' ? '' : '<tr><td><img src="' . h($imageSrc) . '" width="600" alt="' . h(COUPLE_NAMES) . '" '
        . 'style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>')
        // Names, date and venue
        . '<tr><td align="center" style="padding:32px 28px 8px;font-family:' . $font . ';color:#2a211c">'
        . '<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.2em;text-transform:uppercase;color:#8a6552">' . $eyebrow . '</p>'
        . '<p style="margin:0;font-size:34px;line-height:1.15;font-weight:300;letter-spacing:-0.02em">' . h(COUPLE_NAMES) . '</p>'
        . '<p style="margin:12px 0 0;font-size:16px;line-height:1.5;color:#8a6552">' . h(WEDDING_DATE) . '<br>' . place_html() . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px"><div style="height:1px;background:#f3dccb;margin:24px 0"></div></td></tr>'
        // Message
        . '<tr><td style="padding:0 28px 16px;font:16px/1.6 ' . $font . ';color:#2a211c">' . $body . '</td></tr>'
        . '</table>'
        // Footer
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        . '<tr><td style="padding:20px 28px;font:12px/1.6 ' . $font . ';color:#8a6552;text-align:center">'
        . 'If the button doesn&rsquo;t work, copy this link into your browser:<br>'
        . '<a href="' . h($link) . '" style="color:#8a6552;word-break:break-all">' . h($link) . '</a>'
        . $footerHtml
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';

    return [$subject, $text, $html];
}

function encode_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $value)) . '?=';
}

function send_email(string $to, string $toName, string $subject, string $text, string $html, array $settings,
                    array $extraHeaders = []): bool
{
    // Addresses must be single, valid addresses; this also blocks header injection.
    if (!valid_email($to) || !valid_email($settings['from'])
        || ($settings['reply_to'] !== '' && !valid_email($settings['reply_to']))) {
        return false;
    }
    foreach ($extraHeaders as $header) {
        if (preg_match('/[\r\n]/', $header)) {
            return false;
        }
    }

    // Structure: plain text OR (html + the embedded photo it refers to).
    //   multipart/alternative
    //     text/plain
    //     multipart/related
    //       text/html
    //       image/jpeg  (Content-ID referenced by the html as cid:...)
    $outer = 'a' . bin2hex(random_bytes(12));
    $inner = 'r' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . encode_header($settings['from_name']) . ' <' . $settings['from'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $outer . '"',
    ];
    if ($settings['reply_to'] !== '') {
        $headers[] = 'Reply-To: ' . $settings['reply_to'];
    }
    $headers = array_merge($headers, $extraHeaders);
    $part = fn(string $type, string $content, string $extra = '') =>
        "Content-Type: $type\r\nContent-Transfer-Encoding: base64\r\n$extra\r\n"
        . chunk_split(base64_encode($content)) . "\r\n";

    $body = "--$outer\r\n" . $part('text/plain; charset=UTF-8', $text);
    $image = is_readable(email_image_path()) ? file_get_contents(email_image_path()) : false;
    if ($image !== false && strpos($html, 'cid:' . EMAIL_IMAGE_CID) !== false) {
        $body .= "--$outer\r\nContent-Type: multipart/related; boundary=\"$inner\"\r\n\r\n"
            . "--$inner\r\n" . $part('text/html; charset=UTF-8', $html)
            . "--$inner\r\n" . $part('image/jpeg; name="photo.jpg"', $image,
                "Content-ID: <" . EMAIL_IMAGE_CID . ">\r\nContent-Disposition: inline; filename=\"photo.jpg\"\r\n")
            . "--$inner--\r\n";
    } else {
        // No photo file found: send the html without it rather than a broken image.
        $html = preg_replace('#<tr><td><img src="cid:[^>]*></td></tr>#', '', $html);
        $body .= "--$outer\r\n" . $part('text/html; charset=UTF-8', $html);
    }
    $body .= "--$outer--\r\n";
    $toHeader = encode_header($toName) . ' <' . $to . '>';

    // Running on your own computer (php -S): write to a log instead of sending.
    if (PHP_SAPI === 'cli-server') {
        $entry = "==== " . date('c') . "\r\nTo: $toHeader\r\nSubject: " . encode_header($subject) . "\r\n"
            . implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return file_put_contents(dirname(DB_PATH) . '/mail.log', $entry, FILE_APPEND) !== false;
    }

    $sent = mail($toHeader, encode_header($subject), $body, implode("\r\n", $headers), '-f' . $settings['from']);
    // Some hosts refuse the -f (envelope sender) option; try once without it.
    return $sent || mail($toHeader, encode_header($subject), $body, implode("\r\n", $headers));
}

function send_invite(array $guest, array $settings): bool
{
    [$subject, $text, $html] = render_invite($guest, $settings);
    if (!send_email((string)$guest['email'], $guest['name'], $subject, $text, $html, $settings)) {
        return false;
    }
    if (isset($guest['id'])) {
        db()->prepare('UPDATE guests SET invited_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$guest['id']]);
    }
    return true;
}
