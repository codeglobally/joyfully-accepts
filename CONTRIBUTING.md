# Contributing to Joyfully Accepts

Thank you for helping. Whether it's a bug report, an idea, a fix or a note about how your wedding went, it all makes the site better for the next couple.

## Ways to help

- **Tell us how it went.** [Share your feedback](https://github.com/codeglobally/joyfully-accepts/issues/new?template=feedback.yml). Hearing how the site was really used is the most useful thing of all.
- **Report a bug** with the [bug form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=bug_report.yml).
- **Report an accessibility barrier** with the [accessibility form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=accessibility.yml). These are given priority.
- **Suggest an idea** with the [idea form](https://github.com/codeglobally/joyfully-accepts/issues/new?template=feature_request.yml).
- **Fix something** with a pull request (see below).

**Security problems** go through [private reporting](https://github.com/codeglobally/joyfully-accepts/security/advisories/new), never a public issue. See [SECURITY.md](SECURITY.md).

Whatever you post, leave out guests' names, email addresses, invitation codes and passwords.

## What the project is aiming for

These are what keep it easy to install and look after, so changes are judged against them:

- **Small and plain.** PHP and SQLite, with no frameworks, no Composer or npm dependencies, and no build step. Someone should be able to upload it to shared hosting and have it work.
- **PHP 7.4 and up.** Tests run on 7.4, 8.1, 8.3 and 8.4.
- **Private by default.** No third-party scripts, fonts, analytics or trackers. Guest data stays in the couple's own database.
- **Accessible.** Everything a guest or couple uses works by keyboard and meets WCAG 2.1 AA.
- **Secure.** Output is escaped, SQL uses prepared statements, every form has a CSRF token, and nothing breaks the Content Security Policy (so no inline scripts or styles).
- **Friendly words.** Text on the site is plain, warm British English, written for guests who aren't technical.

A big new feature may not fit even if it's a good idea, so for anything large, please open an idea issue first and we can talk it through.

## Running it on your computer

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

GitHub Actions runs these on every pull request, along with a Psalm taint analysis and an OWASP ZAP scan (see [SECURITY.md](SECURITY.md)). Please add a test for anything you fix or add.

## Making a pull request

1. Fork the repository and make a branch for your change.
2. Keep it focused: one fix or feature per pull request.
3. Match the code around it: same naming, same comment style, plain functions, no new layers.
4. Run the tests, and try anything guests see on a phone as well as a computer.
5. If you changed how something is set up or behaves, update the README.
6. Open the pull request and fill in the checklist.

By contributing, you agree that your work is shared under the project's [MIT licence](LICENSE).

## Be kind

Everyone taking part is expected to follow the [code of conduct](CODE_OF_CONDUCT.md).
