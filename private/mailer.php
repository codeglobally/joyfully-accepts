<?php
// Invitation emails. The template lives in the settings table and is
// filled in per guest; {name}, {link} and {code} are replaced.

// The invitation email's settings, with the subject and text for guests
// reading $language (the current language if not given).
function mail_settings(?string $language = null): array
{
    $language = $language ?? current_language();
    $host = preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST));
    return in_language($language, fn() => [
        'from_name' => setting('mail_from_name', COUPLE_NAMES),
        'from'      => setting('mail_from', 'invitations@' . $host),
        'reply_to'  => setting('mail_reply_to'),
        'subject'   => own_wording('mail_subject', $language) ?: default_wording('mail_subject'),
        'body'      => own_wording('mail_body', $language) ?: default_wording('mail_body'),
        'language'  => $language,
    ]);
}

function valid_email(?string $email): bool
{
    return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// An invitation can have more than one address (say, a couple with an email
// each). They're kept together in the guest's email column, separated by
// commas: "sarah@example.com, tom@example.com".

// Splits typed addresses apart, whether separated by commas, semicolons or spaces.
function email_list(?string $emails): array
{
    return array_values(array_filter(preg_split('/[\s,;]+/', (string)$emails), 'strlen'));
}

// Tidies typed addresses into the stored form ('' for none).
function clean_emails(string $emails): string
{
    return implode(', ', array_unique(email_list($emails)));
}

// The addresses that can actually be emailed (any that look wrong are left out).
function sendable_emails(?string $emails): array
{
    return array_values(array_filter(email_list($emails), 'valid_email'));
}

// True if any of the addresses looks wrong.
function has_bad_email(?string $emails): bool
{
    return count(sendable_emails($emails)) < count(email_list($emails));
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
    return render_email($guest, $settings['subject'], $settings['body'], $imageSrc, h(tagline()));
}

// The font stack for an email in the current language. Latin text keeps
// Helvetica; Arabic and Chinese fall through to their own fonts.
function email_font(): string
{
    $scriptFonts = [
        'ar' => "'SF Arabic','Geeza Pro','Segoe UI',Tahoma,",
        'zh-Hans' => "'PingFang SC','Microsoft YaHei',",
        'zh-Hant' => "'PingFang TC','Microsoft JhengHei',",
    ];
    return "'Helvetica Neue',Helvetica,Arial," . ($scriptFonts[current_language()] ?? '') . 'sans-serif';
}

// The branded email used for invitations and updates. $eyebrow is the small
// heading above the names (already HTML). $footerHtml / $footerText are
// added at the bottom, e.g. an unsubscribe link.
function render_email(array $guest, string $subjectTemplate, string $bodyTemplate,
                      string $imageSrc = 'cid:' . EMAIL_IMAGE_CID, ?string $eyebrow = null,
                      string $footerHtml = '', string $footerText = ''): array
{
    // Built in the current language: render emails inside in_language().
    $eyebrow = $eyebrow ?? h(tagline());
    $rtl = is_rtl();
    $dir = $rtl ? 'rtl' : 'ltr';
    $spacing = $rtl ? 'normal' : '0.2em';   // spacing letters apart breaks joined-up Arabic
    $settings = ['subject' => $subjectTemplate, 'body' => $bodyTemplate];
    if (email_photo_url() === '') {
        $imageSrc = '';   // no photo configured
    }
    $link = invite_link($guest['code']);
    $vars = ['{name}' => greeting_name($guest), '{link}' => $link, '{code}' => $guest['code']];

    $subject = str_replace(["\r", "\n"], ' ', strtr($settings['subject'], $vars));
    $text = strtr($settings['body'], $vars) . ($footerText !== '' ? "\n\n--\n" . $footerText : '');

    $font = email_font();
    $button = '</p><table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px">'
        . '<tr><td style="background:#c2410c;border-radius:8px">'
        . '<a href="' . h($link) . '" style="display:inline-block;padding:15px 32px;font:500 16px ' . $font
        . ';color:#ffffff;text-decoration:none;border-radius:8px">' . th('email.view_invitation') . '</a>'
        . '</td></tr></table><p style="margin:0 0 16px">';
    $code = '<span style="display:inline-block;padding:2px 10px;background:#fff7f1;border:1px solid #f3dccb;'
        . 'border-radius:6px;font-weight:600;letter-spacing:0.12em;color:#2a211c">' . h($guest['code']) . '</span>';
    // Blank lines in the message become paragraphs; single line breaks stay as breaks.
    $paragraphs = preg_split('/\n\s*\n/', trim(str_replace("\r", '', $settings['body'])));
    $body = '';
    foreach ($paragraphs as $para) {
        $body .= '<p style="margin:0 0 16px">' . nl2br(strtr(h($para), [
            '{name}' => h(greeting_name($guest)),
            '{code}' => $code,
            '{link}' => $button,
        ])) . '</p>';
    }
    $body = str_replace('<p style="margin:0 0 16px"></p>', '', $body);

    $html = '<!doctype html><html lang="' . h(current_language()) . '" dir="' . $dir . '"><head><meta charset="utf-8">'
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
        . '<p style="margin:0 0 8px;font-size:' . ($rtl ? '14' : '12') . 'px;letter-spacing:' . $spacing . ';text-transform:uppercase;color:#8a6552">' . $eyebrow . '</p>'
        . '<p style="margin:0;font-size:34px;line-height:1.15;font-weight:300;letter-spacing:-0.02em">' . h(COUPLE_NAMES) . '</p>'
        . '<p style="margin:12px 0 0;font-size:16px;line-height:1.5;color:#8a6552">' . h(wedding_date_text()) . '<br>' . place_html() . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px"><div style="height:1px;background:#f3dccb;margin:24px 0"></div></td></tr>'
        // Message
        . '<tr><td dir="' . $dir . '" style="padding:0 28px 16px;font:' . ($rtl ? '17px/1.8 ' : '16px/1.6 ') . $font . ';color:#2a211c;text-align:' . ($rtl ? 'right' : 'left') . '">' . $body . '</td></tr>'
        . '</table>'
        // Footer
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
        . '<tr><td style="padding:20px 28px;font:12px/1.6 ' . $font . ';color:#8a6552;text-align:center">'
        . th('email.link_help') . '<br>'
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

// Sends the invitation to each of the guest's addresses. Returns true only if
// every one went; it counts as sent (invited_at) once any of them has.
// In the guest's language (their saved one, or the site's), with the subject
// and text written for that language.
function send_invite(array $guest, array $settings): bool
{
    $language = valid_language($guest['language'] ?? null) ? $guest['language'] : site_language();
    $local = mail_settings($language);
    $settings['subject'] = $local['subject'];
    $settings['body'] = $local['body'];
    [$subject, $text, $html] = in_language($language, fn() => render_invite($guest, $settings));
    $to = sendable_emails($guest['email'] ?? null);
    $sent = 0;
    foreach ($to as $address) {
        $sent += send_email($address, $guest['name'], $subject, $text, $html, $settings) ? 1 : 0;
    }
    if ($sent && isset($guest['id'])) {
        db()->prepare('UPDATE guests SET invited_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$guest['id']]);
    }
    return $to && $sent === count($to);
}

// Tells opted-in admins that a guest has sent a message (see
// feedback_recipients()). Replies go straight to the guest when their
// invitation has an email address. Returns how many were sent.
function send_feedback_notice(array $guest, string $message): int
{
    $settings = mail_settings();
    if (!valid_email($settings['from'])) {
        return 0;
    }
    $guestEmail = sendable_emails($guest['email'] ?? null)[0] ?? '';
    $settings['reply_to'] = $guestEmail;
    $sent = 0;
    foreach (feedback_recipients() as $person) {
        [$subject, $text, $html] = in_language($person['language'],
            fn() => render_feedback_notice((string)$guest['name'], $message, $guestEmail !== ''));
        $sent += send_email($person['email'], $person['name'], $subject, $text, $html, $settings) ? 1 : 0;
    }
    return $sent;
}

// The feedback notice in the current language: [subject, text, html].
function render_feedback_notice(string $from, string $message, bool $canReply): array
{
    $inbox = SITE_URL . '/admin.php?tab=feedback';
    $account = SITE_URL . '/admin.php?tab=account';
    $subject = t('admin_mail.feedback_subject', ['name' => $from]);
    $replyNote = t($canReply ? 'admin_mail.reply_direct' : 'admin_mail.no_reply');
    $text = t('admin_mail.feedback_intro', ['name' => $from]) . "\n\n" . $message . "\n\n"
        . "$replyNote\n" . t('admin_mail.see_all') . ": $inbox\n\n--\n"
        . t('admin_mail.feedback_reason') . ' ' . t('admin_mail.turn_off') . ": $account";
    $rtl = is_rtl();
    $html = '<!doctype html><html lang="' . h(current_language()) . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><title>' . h($subject) . '</title></head>'
        . '<body style="margin:0;padding:24px;background:#faf7f4;font:16px/1.5 ' . email_font() . ';color:#2a211c">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #eadfd6;border-radius:12px;padding:24px">'
        . '<p style="margin:0 0 12px;color:#8a6552;font-size:13px;' . ($rtl ? '' : 'letter-spacing:0.12em;text-transform:uppercase') . '">' . th('admin_mail.feedback_eyebrow') . '</p>'
        . '<p style="margin:0 0 16px">' . t_html('admin_mail.feedback_intro', ['name' => '<strong>' . h($from) . '</strong>']) . '</p>'
        . '<blockquote style="margin:0 0 20px;padding:12px 16px;background:#fff7f1;border-' . ($rtl ? 'right' : 'left') . ':3px solid #c2410c;border-radius:4px" dir="auto">'
        . nl2br(h($message)) . '</blockquote>'
        . '<p style="margin:0 0 16px">' . h($replyNote) . ' <a href="' . h($inbox) . '" style="color:#c2410c">' . th('admin_mail.see_all') . '</a></p>'
        . '<p style="margin:24px 0 0;font-size:13px;color:#8a6552">' . th('admin_mail.feedback_reason') . ' '
        . '<a href="' . h($account) . '" style="color:#8a6552">' . th('admin_mail.turn_off') . '</a></p></div></body></html>';
    return [$subject, $text, $html];
}
