# Changelog

What changed in each version. The site uses [semantic versioning](https://semver.org): MAJOR when site owners must do something to upgrade, MINOR for anything new, PATCH for fixes and small tweaks. Every change bumps the version.

## 2.7.3

- Security hardening: a language code from a cookie, form or link is swapped for the site's own copy before it's used, the return path after choosing a language is checked in one place, and one more admin value is escaped. (Found by the Psalm taint analysis; none of it was exploitable, as codes were already checked against the list.)

## 2.7.2

- README: the fully localised guest and admin experience up front, with a table of the nine languages (and a flag each).

## 2.7.1

- `deploy.sh`: reads the live site's current version from the 2.6+ footer again (it said "unknown").

## 2.7.0

- **Choosing a language, however many there are:**
  - Admin: one compact menu replaces the row of language tabs on the Invitation email tab and in the section editor ("Editing: Español ✓ ▾"). It lists every language with whether it's translated yet, and there's a count and a *Next untranslated* link. On the Updates tab it switches between translations in place, one at a time.
  - Guests: the language menu is in the top corner of the code page and guest page as well as the footer (just the globe on phones). It opens as a small panel, with the visitor's own browser languages first and the current one ticked.
  - Guests: if a page is in a language the guest didn't choose (set in admin, or from another device), a small line at the top offers to switch to the one chosen in this browser, or the browser's own, written in that language. "No thanks" means it isn't offered again.

## 2.6.1

- `deploy.sh`: the check that the new version is live now finds it in the 2.6 footer.

## 2.6.0

- **Languages:** English, Español, Deutsch, Français, العربية (right-to-left), 简体中文, 繁體中文, Te Reo Māori and Cymraeg, for the guest pages, emails and the admin area. Everything other than English is a draft translation: corrections welcome, with new [translation](.github/ISSUE_TEMPLATE/translation.yml) and [new language](.github/ISSUE_TEMPLATE/new_language.yml) issue forms.
- **Guests** get their browser's language on their first visit, can change it from the footer, and keep it: it's saved on their invitation and used for later emails. Dates, plurals and right-to-left layout follow the language.
- **Draft content:** the sample sections and the *Add a section* templates come with a draft in every language, on existing sites too where you haven't reworded them.
- **Your wording in each language:** guest-page sections, the invitation email and tagline, and update emails (including templates) each have a version per language, falling back to your main one. Built-in text you haven't changed comes translated, on existing sites too.
- **Admin:** each admin chooses their language on Your account (their browser's until then), and gets emails such as feedback notices in it. The Guests tab shows each guest's language, which can be set when editing an invitation or with a *Language* column in a CSV upload.
- **Names** in other languages split into people properly ("y", "und", "et", "و", "和", "、"); Chinese names are left whole. CSV files saved by older Excel in Chinese encodings (GB18030, Big5) are read correctly.
- Fix: the Insights tab no longer fails on a brand-new site before any update has been sent.
- Fix: radio buttons keep their size next to long answers.
- New settings: `SITE_LANGUAGE`, `LANGUAGES` and `ARABIC_DIGITS` (see `config.example.php`). Nothing to change to upgrade: without them the site's language is English, with all nine offered to guests.

## 2.5.0

- People tab: a **Feedback emails** column showing who gets an email when a guest sends a message, with an on/off switch for the site owner.

## 2.4

- **Feedback emails:** each admin can add an email address (avatar menu → **Your account**) and choose to be emailed when a guest sends a message. Replies go straight to the guest.
- **Configurable tagline** above the couple's names on the code page and at the top of the invitation email ("You're invited" by default), set on the Invitation email tab.
- Changing another admin's email address needs their current password, since it's used for password resets.
- Fix: a password reset by emailed link now still asks for the person's passkey.

## 2.3

- More than one email address per invitation, with a copy of every email to each.
- Upload the guest list as a CSV.
- Tick several invitations to send or delete them together.
- Greet guests by full name or first names only, with surnames in bold in the guest list.
- Passkeys for admin sign-in, required once added.
- Account menu (avatar, top right).
- Collapsible **Add invitations** box.
- Guest page: RSVP stays open, with a gentle nudge, until a guest has a definite answer; bigger "Welcome".
- Security: admin sessions end when a password changes, have a 12-hour limit, and get a fresh form token at sign-in; safer bulk actions.

## 2.2

- Open-source release as Joyfully Accepts: settings in config, screenshots, tests, CI, security checks and documentation.
