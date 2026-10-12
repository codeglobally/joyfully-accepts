<?php
// "Add to calendar" for the wedding day: an .ics file (Apple Calendar,
// Outlook app and most others) plus Google Calendar and Outlook web links.
// The day is an all-day event, read from WEDDING_DATE in config.php.

// The wedding date as a DateTime, or null if WEDDING_DATE can't be read.
function wedding_day(): ?DateTimeImmutable
{
    $time = strtotime(WEDDING_DATE);
    return $time ? (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('Europe/London'))->setTime(0, 0) : null;
}

function calendar_title(): string
{
    return stripos(COUPLE_NAMES, 'wedding') !== false ? COUPLE_NAMES : COUPLE_NAMES . "'s wedding";
}

// Escaping and 75-octet line folding as the iCalendar standard requires.
function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
}

function ics_line(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;   // don't split a UTF-8 character
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

function wedding_ics(string $personalLink): string
{
    $day = wedding_day();
    $host = preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST)) ?: 'wedding';
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . $host . '//Wedding//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:wedding-' . $day->format('Ymd') . '@' . $host,
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART;VALUE=DATE:' . $day->format('Ymd'),
        'DTEND;VALUE=DATE:' . $day->modify('+1 day')->format('Ymd'),
        'SUMMARY:' . ics_text(calendar_title()),
        'LOCATION:' . ics_text(WEDDING_PLACE),
        'DESCRIPTION:' . ics_text("Details, timings and your RSVP:\n" . $personalLink),
        'URL:' . $personalLink,
        'TRANSP:OPAQUE',
        // Reminders: 9am a week before, and 9am the day before.
        'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text(calendar_title() . ' is in a week'), 'TRIGGER:-P6DT15H', 'END:VALARM',
        'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text(calendar_title() . ' is tomorrow'), 'TRIGGER:-PT15H', 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR',
    ];
    return implode('', array_map('ics_line', $lines));
}

// Web calendar links. These carry only the plain site address, not the
// guest's invitation code, since they go to Google / Microsoft.
function google_calendar_link(): string
{
    $day = wedding_day();
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => calendar_title(),
        'dates' => $day->format('Ymd') . '/' . $day->modify('+1 day')->format('Ymd'),
        'location' => WEDDING_PLACE,
        'details' => 'Details and RSVP: ' . SITE_URL,
    ]);
}

function outlook_calendar_link(): string
{
    $day = wedding_day();
    return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query([
        'path' => '/calendar/action/compose',
        'rru' => 'addevent',
        'subject' => calendar_title(),
        'startdt' => $day->format('Y-m-d'),
        'enddt' => $day->modify('+1 day')->format('Y-m-d'),
        'allday' => 'true',
        'location' => WEDDING_PLACE,
        'body' => 'Details and RSVP: ' . SITE_URL,
    ]);
}
