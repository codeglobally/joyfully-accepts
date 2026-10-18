# Joyfully Accepts

A small, self-hosted wedding website: guests enter an invitation code to RSVP, and the couple runs everything from an admin area. Plain PHP and SQLite, deployed to shared hosting. See README.md for features, CONTRIBUTING.md for the project's aims, and SECURITY.md for the security model.

## Commands

```bash
php tests/run.php                                                    # unit tests
php -S localhost:8080 -t public_html tests/router.php                # run locally (needs private/config.php)

# end-to-end: a throwaway copy with test settings, then the full suite against it
WEDDING_CONFIG=$PWD/tests/config.php WEDDING_TEST_DB=/tmp/e2e.sqlite php -S localhost:8097 -t public_html tests/router.php &
WEDDING_CONFIG=$PWD/tests/config.php WEDDING_TEST_DB=/tmp/e2e.sqlite php tests/run.php --http=http://localhost:8097
```

`tests/router.php` makes PHP's built-in server behave like `public_html/.htaccess` (404 page, headers on plain files). Always use it. Locally, emails are written to `private/data/mail.log` instead of being sent.

## How it fits together

- `public_html/` is the web root: one PHP file per page, plus `assets/`. Each page requires `private/lib.php`.
- `private/` is never served. `lib.php` loads settings in this order: `config.php` (or the file in `WEDDING_CONFIG`), then `site.php` (look and feel, only when `WEDDING_CONFIG` isn't set), then `defaults.php`, which fills in anything left out. Feature code lives in `content.php`, `mailer.php`, `notify.php`, `users.php`, `passkeys.php`, `insights.php`, `calendar.php` and `photo.php`. `passkeys.php` is security-critical: any change needs tests that forged, replayed and wrong-site sign-ins still fail (the pretend device in `tests/run.php` makes them).
- `theme.css.php` turns the photo and settings into CSS variables. `style.css` holds everything else.
- Tests are one plain script, `tests/run.php`, with `check()` and `section()` helpers and an end-to-end block run with `--http=`. Its settings are in `tests/config.php`.
- CI (`.github/workflows/`) runs the tests on PHP 7.4, 8.1, 8.3 and 8.4, plus Psalm taint analysis (`psalm.xml`) and an OWASP ZAP scan (`.zap/rules.tsv`).

## Rules for changes

- **No dependencies or build step.** No Composer, npm, frameworks or CDNs. The code must still run on PHP 7.4, so no `match`, enums, readonly properties or named arguments.
- **Security:** escape all output with `h()`. Read request data with `input()`. Use prepared statements. Every POST form gets `csrf_field()` and its handler calls `check_csrf()`.
- **Content Security Policy is `default-src 'self'`.** No inline `<script>`, `style=""` or event handlers. Put behaviour in `assets/*.js` and styles in `style.css`. The one exception is HTML emails (`mailer.php`, `notify.php`, `users.php`), which need inline styles; their admin previews send their own stricter policy for that.
- **Accessibility:** WCAG 2.1 AA. Label every control, make everything work by keyboard, and respect `prefers-reduced-motion`.
- **Wording** is plain, warm British English for non-technical guests. Comments explain *why*, in the same plain style.
- **Every word guests or admins see** comes from the phrase files: `private/lang/xx.php` (guests and their emails) and `private/lang/admin/xx.php` (admin). A new phrase goes in all eight languages in the same change (the tests fail otherwise), keeping `{placeholders}` and plural forms; use `t()`/`th()`, `t_html()` for phrases with HTML in, and `js_phrases()` for scripts. Layout uses CSS logical properties (start/end) so Arabic mirrors.
- **Assets:** link CSS and JS with `asset_url()`, so they're cached long-term and refreshed when changed.
- **Every change gets a test** in `tests/run.php`. Match the surrounding code rather than adding new layers.
- **Bump the version with every change**, using semantic versioning (MAJOR.MINOR.PATCH): MAJOR when site owners must do something to upgrade (a renamed setting, a migration that isn't automatic, dropping a PHP version), MINOR for anything new (a feature, setting or page), PATCH for fixes, wording and small tweaks. Change `SITE_VERSION` in `private/lib.php` and the README badge together, and add a line to `CHANGELOG.md`. A test checks they match.

## Privacy: never leak a real wedding

- Never commit, print or quote `private/config.php`, `private/site.php`, `private/data/`, `public_html/assets/photos/` or `*.zip`. They hold real names, dates, venues, guests and passwords, and are gitignored for that reason. If you need to check something about real settings, test it in a script that prints only yes or no, never the value.
- Screenshots and examples use made-up details only. The example couple is "Alex & Sam", Saturday 9 September 2028, The Old Barn, Exampleton. Example emails are `@example.com`.
- Check images for EXIF or GPS data before committing them.

## Deploying and publishing

`./deploy.sh` uploads the site to cPanel hosting. Its settings live in `~/.config/wedding-deploy/`, not in the repo. Never deploy, push, open pull requests or change GitHub settings unless the user asks for that specific action.
