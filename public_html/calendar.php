<?php
// Downloads the wedding as a calendar file (.ics), with reminders.
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';
require APP_DIR . '/calendar.php';

$guest = require_guest();
set_language(guest_language($guest));
if (!wedding_day()) {
    http_response_code(404);
    exit(th('cal.no_date'));
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="wedding.ics"');
echo wedding_ics(invite_link($guest['code']));
