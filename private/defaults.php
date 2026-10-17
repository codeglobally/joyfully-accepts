<?php
// Defaults for every optional setting. Set your own in config.php (or
// site.php); anything you leave out falls back to the value here.

$defaults = [
    // The photo on the home page and in emails: a path under public_html.
    // Leave empty ('') for a plain background with no photo.
    'PHOTO' => '/assets/sample-photo.jpg',
    // A smaller copy for emails (keeps them light). Defaults to PHOTO.
    'PHOTO_EMAIL' => '',
    // Caption over the top-right corner of the photo, e.g. where it was taken.
    // Each part can be plain text (null) or a hidden link (a URL).
    //   ['Lake Bled' => 'https://en.wikipedia.org/wiki/Lake_Bled', 'Slovenia' => null]
    'PHOTO_CAPTION' => [],
    // Invisible links over parts of the photo ("easter eggs"). Positions are
    // fractions of the photo: x/y = top-left corner, w/h = size.
    //   [['x' => 0.885, 'y' => 0.80, 'w' => 0.115, 'h' => 0.155,
    //     'url' => 'https://…', 'title' => 'Where we got engaged']]
    'PHOTO_HOTSPOTS' => [],
    // On tablets and tall windows the invitation panel starts this far down
    // the photo (0.8 = 80%). Lower it if the panel covers faces.
    'PHOTO_PANEL_START' => 0.8,
    // Show the wedding date on the home page, where anyone can see it. Off by
    // default, so only guests who've entered their code see the date.
    'DATE_ON_HOME_PAGE' => false,
    // Languages (see private/i18n.php). SITE_LANGUAGE is your own: your content
    // is written in it, and anything not translated falls back to it.
    // LANGUAGES are the ones guests and admins can choose. For English only,
    // set LANGUAGES to ['en'] and the language menu disappears.
    'SITE_LANGUAGE' => 'en',
    'LANGUAGES' => ['en', 'es', 'de', 'fr', 'ar', 'zh-Hans', 'zh-Hant'],
    // Arabic numbers: 'western' (0-9, understood everywhere) or 'eastern' (٠-٩).
    'ARABIC_DIGITS' => 'western',
    // Footer line "Made with ♥ in …". Leave empty to hide it. The optional
    // URL turns the place name into a hidden link.
    // Lost the owner's passkey? Set this to true in config.php to sign in with
    // just ADMIN_PASSWORD, remove the old passkey and add a new one, then set
    // it back to false.
    'PASSKEY_RECOVERY' => false,
    'MADE_IN' => '',
    'MADE_IN_URL' => '',
];

foreach ($defaults as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
unset($defaults, $name, $value);
