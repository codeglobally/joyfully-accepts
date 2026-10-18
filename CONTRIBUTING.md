# Contributing to Joyfully Accepts

Thank you for helping. Whether it's a bug report, an idea, a suggested fix or a note about how your wedding went, it all makes the site better for the next couple.

## Ways to help

- **Tell us how it went.** [Share your feedback](https://github.com/codeglobally/joyfully-accepts/issues/new?template=feedback.yml). Hearing how the site was really used is the most useful thing of all.
- **Report a bug** with the [bug form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=bug_report.yml).
- **Report an accessibility barrier** with the [accessibility form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=accessibility.yml). These are given priority.
- **Improve a translation** with the [translation form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=translation.yml), or **ask for a new language** with the [language form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=new_language.yml). See [Translations](#translations).
- **Suggest an idea** with the [idea form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=feature_request.yml).
- **Suggest a fix.** Pull requests are limited to collaborators (see below), but fixes are very welcome in an issue.
- **Make it your own.** Fork it for your own wedding. That's what the MIT licence is for.

**Security problems** go through [private reporting](https://github.com/codeglobally/joyfully-accepts/security/advisories/new), never a public issue. See [SECURITY.md](SECURITY.md).

Whatever you post, leave out guests' names, email addresses, invitation codes and passwords.

## What the project is aiming for

These are what keep it easy to install and look after, so changes are judged against them:

- **Small and plain.** PHP and SQLite, with no frameworks, no Composer or npm dependencies, and no build step. Someone should be able to upload it to shared hosting and have it work.
- **PHP 7.4 and up.** Tests run on 7.4, 8.1, 8.3 and 8.4.
- **Private by default.** No third-party scripts, fonts, analytics or trackers. Guest data stays in the couple's own database.
- **Accessible.** Everything a guest or couple uses works by keyboard and meets WCAG 2.1 AA.
- **Secure.** Output is escaped, SQL uses prepared statements, every form has a CSRF token, and nothing breaks the Content Security Policy (so no inline scripts or styles).
- **Friendly words.** Text on the site is plain and warm, written for guests who aren't technical: British English first, then the same tone in every other language.

A big new feature may not fit even if it's a good idea, so for anything large, please open an idea issue and we can talk it through.

## Translations

The site comes in English, Español, Deutsch, Français, العربية, 简体中文, 繁體中文, Te Reo Māori and Cymraeg. Everything other than English began as a draft, so corrections from people who speak these languages are the most valuable help of all.

- **What guests see** (pages and emails) is in `private/lang/xx.php`; **the admin area** is in `private/lang/admin/xx.php`. English (`en.php`) is the source for both.
- Keep every key, and every `{placeholder}` exactly as it is: the site fills in `{name}`, `{count}` and the rest. Where a phrase shows `{name}`, `{link}` and `{code}` to the couple, those stay in English too.
- Phrases with a `{count}` have plural forms: `one` and `other` in English, Spanish, German, French and Māori (French counts 0 as one); all six of `zero`, `one`, `two`, `few`, `many` and `other` in Arabic and Welsh; just `other` in Chinese.
- The tone: Spanish uses *tú*, German *Sie*, French *vous*, Arabic the polite plural (أنتم), Māori *koutou* and Welsh *chi*, which work for one guest or several (the Māori admin area uses *koe*). Greetings in emails don't assume anyone's gender.
- The tests check that every language has every phrase, with the same placeholders and plural forms, so `php tests/run.php` tells you if something's missing.

**Adding a language** means a new pair of files, adding it to `LANGUAGE_NAMES` in `private/i18n.php` (and `RTL_LANGUAGES` if it's written right to left), its plural rules in `plural_form()` (in PHP; the admin's scripts use the browser's own), and its words for "and" and "family" in `private/lib.php` so guest names split properly. Please start with a [language request](https://github.com/codeglobally/joyfully-accepts/issues/new?template=new_language.yml) so we can plan it together.

## Running your own copy

You need PHP 7.4 or later with the `pdo_sqlite` extension (`gd` is optional, for the photo colours).

```bash
cp private/config.example.php private/config.php
php -S localhost:8080 -t public_html tests/router.php
```

Open http://localhost:8080, and sign in to http://localhost:8080/admin.php as `admin` with the password from `private/config.php`. Emails aren't sent locally. They're written to `private/data/mail.log`.

`tests/router.php` makes PHP's built-in server behave like the `.htaccess` rules on a real host (the 404 page and the headers on plain files).

## Running the tests

```bash
php tests/run.php
```

For the end-to-end checks as well, start a test copy and point the tests at it:

```bash
WEDDING_CONFIG=$PWD/tests/config.php WEDDING_TEST_DB=/tmp/e2e.sqlite php -S localhost:8097 -t public_html tests/router.php &
WEDDING_CONFIG=$PWD/tests/config.php WEDDING_TEST_DB=/tmp/e2e.sqlite php tests/run.php --http=http://localhost:8097
```

GitHub Actions runs these on every push, along with a Psalm taint analysis and an OWASP ZAP scan (see [SECURITY.md](SECURITY.md)). Forks get the same checks once Actions is switched on in the fork's Actions tab. If you change something, add a test for it.

## Versions

The site uses [semantic versioning](https://semver.org): **MAJOR.MINOR.PATCH**.

- **MAJOR** when site owners have to do something to upgrade, such as renaming a setting in `config.php`.
- **MINOR** for anything new: a feature, a setting or a page.
- **PATCH** for fixes, wording and small tweaks.

Every change bumps one of them, in `SITE_VERSION` in `private/lib.php` and the README badge, with a line in [CHANGELOG.md](CHANGELOG.md).

## About pull requests

Pull requests are open to collaborators only, which keeps the project small and easy to look after. If you've fixed something or have an improvement:

1. Open a [bug report](https://github.com/codeglobally/joyfully-accepts/issues/new?template=bug_report.yml) or an [idea](https://github.com/codeglobally/joyfully-accepts/issues/new?template=feature_request.yml) describing it.
2. Include the change itself: a code snippet in the issue, or a link to the commit in your fork.
3. If it fits the aims above, it'll be brought in, with credit to you.

By sharing code in an issue, you agree that it can be used under the project's [MIT licence](LICENSE).

## Be kind

Everyone taking part is expected to follow the [code of conduct](CODE_OF_CONDUCT.md).
