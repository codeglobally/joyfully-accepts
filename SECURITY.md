# Security

## Reporting a vulnerability

Please **don't open a public issue** for security problems. Instead, use
GitHub's private reporting: go to the **Security** tab of this repository and
choose **Report a vulnerability**. Include the steps to reproduce it and the
version (shown in the site footer).

You should hear back within a week. Once a fix is ready it's released, and
you'll be credited in the release notes unless you'd rather not be. Only the
latest release gets security fixes.

The site is small and self-hosted, so the most useful reports are ones that let
someone outside a wedding's guest list see or change guest data, or let a
guest reach the admin area.

## How it's checked

| Check | What it covers | When |
|---|---|---|
| [Psalm](https://psalm.dev/docs/security_analysis/) taint analysis | Follows data from requests, cookies and the database to HTML, SQL, headers, files and shell commands. Fails on any path without escaping or validation (XSS, SQL injection, header injection and so on). | Every push ([security.yml](.github/workflows/security.yml)) |
| [OWASP ZAP](https://www.zaproxy.org/docs/docker/baseline-scan/) baseline scan | Crawls a running copy and checks every response: security headers, cookie flags, CSRF tokens on forms, error and version leaks. Rules marked FAIL in [.zap/rules.tsv](.zap/rules.tsv) fail the build. | Every push, and weekly |
| Tests ([tests/run.php](tests/run.php)) | Escaping of formatted text, `javascript:` links, CSV formula injection, email header injection, input type confusion, login and setup-link handling, plus end-to-end checks of headers, cookies, CSRF and sign-in. | Every push, PHP 7.4 to 8.4 |
| [Mozilla Observatory](https://developer.mozilla.org/en-US/observatory) | HTTP security headers on the live site | By hand after header changes. The reference install scores **A+** |
| [SSL Labs](https://www.ssllabs.com/ssltest/) | TLS setup of the live site | By hand after hosting changes. The reference install scores **A+** (TLS 1.2 and 1.3 only, HSTS, forward secrecy) |

The live grades depend on your hosting, so test your own site. The site
supplies the headers, but TLS is up to your host.

## OWASP Top 10 (2021)

| Risk | How the site handles it |
|---|---|
| **A01 Broken access control** | Every admin page and action checks the sign-in on the server. A guest's session only holds their own invitation's ID, so no URL or form field can reach another guest's data. Only the owner can manage other admins. All forms that change anything are POST with a CSRF token. The language switcher only sends people back to a page on this site, so it can't be used as an open redirect, and only accepts the languages on offer. |
| **A02 Cryptographic failures** | HTTPS is forced (`.htaccess`) with HSTS. Admin passwords are bcrypt-hashed. Codes, CSRF tokens and setup links come from the system's secure random source, and setup links are stored only as SHA-256 hashes. |
| **A03 Injection** | All SQL uses prepared statements. All output is HTML-escaped, and the formatting syntax escapes first and only then adds its few tags, allowing `http`, `https` and `mailto` links only. Email addresses are checked so they can't add headers. CSV exports neutralise spreadsheet formulas. Translated phrases are escaped like any other text; the few that carry HTML (a link or a bold word) have it added by the site after escaping. Psalm's taint analysis confirms there are no unescaped paths. |
| **A04 Insecure design** | Guessing codes is rate-limited: 10 wrong tries per 15 minutes per address, with IPv6 counted per /64, and admin sign-ins share the same limit. Setup and reset links work once and expire in 7 days. Unsubscribe links open a confirmation page, so email scanners that "click" links can't unsubscribe anyone. |
| **A05 Security misconfiguration** | Strict CSP (`default-src 'self'`, no inline script), `frame-ancestors 'none'`, `nosniff`, `no-referrer`, Permissions-Policy, COOP and CORP. Errors are logged, never shown, and `X-Powered-By` is removed. Directory listings are off. Private files sit outside the web root, and `.htaccess` blocks them if they can't. |
| **A06 Vulnerable components** | There are no dependencies: no Composer packages, no JavaScript libraries, no CDNs. The only thing to keep updated is PHP itself. The site runs on 7.4+, but use a version that still gets security fixes (8.2 or later). |
| **A07 Authentication failures** | A new session ID and form token at every sign-in. Cookies are HttpOnly, SameSite=Lax, and Secure over HTTPS. Admin sessions end after 2 hours idle or 12 hours in all, and straight away when that person is removed or their password changes: resetting it, choosing a new one, or a new `ADMIN_PASSWORD` in config.php. A session with no signed-in person is treated as nobody, never as the owner. A timing-safe check stops attackers learning which usernames exist. Passwords must be at least 12 characters.  Optional passkeys (checked with no libraries: challenge, origin, site, Face ID/fingerprint/PIN, signature and sign-in counter) can't be phished, and once added are required. |
| **A08 Integrity failures** | No third-party scripts or styles, and CSP blocks any that might be injected. Nothing from users is ever unserialised or run as code. Deploys go over HTTPS with an expiring cPanel API token. |
| **A09 Logging and monitoring** | Failed codes and sign-ins are recorded (for rate limiting), and PHP errors go to the server's error log. There's **no audit trail** of admin actions. See Known limits. |
| **A10 Server-side request forgery** | The server never fetches a URL a user supplied. It only reads its own files (the photo) and the CSV an admin uploads. |

## OWASP ASVS 4.0 level 1

The checks that apply to a site like this:

| Area | Status |
|---|---|
| V2 Authentication | Met: minimum 12 characters, no composition rules, rate limiting, hashed passwords, single-use expiring reset links. Optional passkeys (WebAuthn, with user verification required), which once added are required on their own or as a second step after the password. **Not done:** checking passwords against breach lists (2.1.7), and the owner password is stored in plain text in config (see Known limits).  A password reset by emailed link still requires the person's passkey, and changing an admin's email address (used for resets) needs their current password. |
| V3 Session management | Met: new ID and CSRF token at sign-in, ended at sign-out, cookie flags, idle (2 hours) and absolute (12 hours) limits for admins, and sessions ended when a password changes. |
| V4 Access control | Met: checked on the server for every request, least privilege for extra admins, CSRF protection. |
| V5 Validation, sanitising and encoding | Met: inputs read as plain strings, length and type checks, escaping by context (HTML, attributes, CSS, CSV, email, iCalendar), parameterised SQL. |
| V7 Errors and logging | Partly met: no errors shown to visitors, nothing sensitive logged. No admin audit log. |
| V8 Data protection | Met: guest pages aren't cached (`no-store`), are marked `noindex`, and send no referrer. No third parties receive any data. |
| V9 Communications | Met: HTTPS only, with HSTS. TLS quality depends on your host (the reference install scores A+). |
| V12 Files | Met: the only upload is the guest-list CSV in the admin area. It needs an admin sign-in and a CSRF token, is limited to 1 MB and 2,000 rows, is read in memory, and is never saved or served. Each address in it is checked like a typed one. |
| V13 API | Not applicable: no public API. |
| V14 Configuration | Met: no dependencies to patch, security headers, private files kept out of the web root. |

## Known limits

These are deliberate trade-offs for a small wedding site, listed so you can
decide whether they suit you.

- **A guest's link is their key.** Anyone with it can view and change that
  invitation's RSVP. That's the price of one-click links. Don't put anything
  truly private on guest pages.
- **The owner password is in plain text** in `private/config.php` (compared in
  constant time). Keep that file private, and use a password you don't use
  anywhere else. Extra admins set their own bcrypt-hashed passwords.
- **No audit log** of admin changes. Two-step sign-in is available through passkeys, but only for admins who add one.
- **Passkey attestation isn't checked**, as on most sites: any device that keeps the key safe and asks for Face ID, a fingerprint or a PIN is accepted. `PASSKEY_RECOVERY` in config.php lets the owner sign in with just the password, so only turn it on while replacing a lost passkey.
- **Signing out is a plain link**, so another site could sign you out. That's
  harmless, but it isn't CSRF-protected.
- **Rate limits are per address.** Someone with many addresses could guess
  faster. Even so, 850 billion possible codes make guessing a real one unlikely.
