<?php
// ---------------------------------------------------------------
// Copy this file to config.php and fill it in. config.php is kept
// out of git (it holds your admin password) and deploy.sh never
// uploads it, so set it up on your server once by hand.
// ---------------------------------------------------------------

// --- The wedding ------------------------------------------------

const COUPLE_NAMES = 'Alex & Sam';
// Written the way you'd like it shown. It's also used for "Add to calendar",
// so keep it in a form like "Saturday 9 September 2028".
const WEDDING_DATE = 'Saturday 9 September 2028';
const WEDDING_PLACE = 'The Old Barn, Example Lane, Exampleton, AB1 2CD';

// --- The website ------------------------------------------------

// Full public address of the site, no trailing slash. Used in invite links.
const SITE_URL = 'https://example.com';

// Password for the owner's admin sign-in (username "admin"). Use something
// long. The admin page stays locked until you change it.
const ADMIN_PASSWORD = 'change-me-please';

// Where the SQLite database lives. private/ is kept out of the web root.
const DB_PATH = __DIR__ . '/data/wedding.sqlite';

// Wrong-code attempts allowed per IP address in a 15 minute window.
const MAX_FAILED_ATTEMPTS = 10;

// --- Look and feel (all optional) -------------------------------
// You can put these here, or in private/site.php (which deploy.sh does
// upload, handy if you tweak them often). Anything left out uses the
// defaults in private/defaults.php.

// Your photo: put it in public_html/assets/photos/ (kept out of git).
// const PHOTO = '/assets/photos/us.jpg';          // '' for no photo
// const PHOTO_EMAIL = '/assets/photos/us-small.jpg'; // optional smaller copy for emails

// Caption over the photo's top-right corner. null = plain text,
// a URL = a hidden link that looks like plain text.
// const PHOTO_CAPTION = ['Lake Bled' => 'https://en.wikipedia.org/wiki/Lake_Bled', 'Slovenia' => null];

// Hidden links over parts of the photo. x/y/w/h are fractions of the photo
// (0.5 = halfway). Tip: open the photo in any editor, note the pixel box,
// and divide by the photo's width and height.
// const PHOTO_HOTSPOTS = [
//     ['x' => 0.80, 'y' => 0.10, 'w' => 0.15, 'h' => 0.20,
//      'url' => 'https://en.wikipedia.org/wiki/Bled_Castle', 'title' => 'Where we got engaged'],
// ];

// On tablets the invitation panel starts this far down the photo. Lower it
// (e.g. 0.7) if the panel covers faces.
// const PHOTO_PANEL_START = 0.8;

// The home page shows only your names, so the date stays private to guests
// who've entered their code. Set this to true to show the date there too.
// const DATE_ON_HOME_PAGE = true;

// Footer line "Made with ♥ in …" (hidden if empty), optionally a hidden link.
// const MADE_IN = 'Ljubljana';
// const MADE_IN_URL = 'https://en.wikipedia.org/wiki/Ljubljana';
