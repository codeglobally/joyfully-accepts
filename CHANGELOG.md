# Changelog

What changed in each version. The site uses [semantic versioning](https://semver.org): MAJOR when site owners must do something to upgrade, MINOR for anything new, PATCH for fixes and small tweaks. Every change bumps the version.

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
