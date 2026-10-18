#!/bin/bash
# Deploy the wedding site to cPanel hosting using a cPanel API token.
#
#   ./deploy.sh --setup   Save your cPanel username and API token (once)
#   ./deploy.sh --check   Test the token; changes nothing on the server
#   ./deploy.sh --ls DIR  List a folder in your home folder (read-only)
#   ./deploy.sh           Back up the live site, upload, extract, verify
#
# The live private/config.php and private/data/ (the guest database) are
# never uploaded, so your settings and guests are kept.
#
# The token is stored in ~/.config/wedding-deploy/token (readable only by
# you) and is passed to curl through a temporary file, never on the
# command line, so it doesn't show up in process listings or logs.

set -euo pipefail

CONF_DIR="${DEPLOY_CONF:-$HOME/.config/wedding-deploy}"
HOST="${DEPLOY_HOST:-$(cat "$CONF_DIR/host" 2>/dev/null || true)}"   # your site's domain, saved by --setup
ZIP_NAME="wedding-site-update.zip"
HERE="$(cd "$(dirname "$0")" && pwd)"

die() { echo "✗ $*" >&2; exit 1; }
step() { echo "→ $*"; }

# --- Setup -----------------------------------------------------------

if [[ "${1:-}" == "--setup" ]]; then
    mkdir -p "$CONF_DIR"
    chmod 700 "$CONF_DIR"
    read -r -p "Your site's domain (e.g. example.com): " host
    host="${host#https://}"; host="${host#http://}"; host="${host%%/*}"
    read -r -p "cPanel username (the account name at the top right of cPanel, not an email address): " user
    read -r -s -p "cPanel API token (input hidden): " token
    echo
    [[ -n "$host" && -n "$user" && -n "$token" ]] || die "All three are needed."
    [[ "$user" != *@* ]] || die "That looks like an email address. Use the cPanel account username instead."
    printf '%s' "$host" > "$CONF_DIR/host"
    printf '%s' "$user" > "$CONF_DIR/user"
    ( umask 077; printf '%s' "$token" > "$CONF_DIR/token" )
    chmod 600 "$CONF_DIR/token"
    echo "Saved. Run ./deploy.sh --check to test it."
    exit 0
fi

[[ -n "$HOST" && -s "$CONF_DIR/user" && -s "$CONF_DIR/token" ]] || die "Run ./deploy.sh --setup first."
API="${DEPLOY_API:-https://$HOST:2083}"         # overridable for testing
SITE="${DEPLOY_SITE:-https://$HOST}"
USER_NAME="$(cat "$CONF_DIR/user")"

# Authorization header in a private temp file, deleted on exit.
HEADER_FILE="$(mktemp)"
WORK="$(mktemp -d)"
trap 'rm -f "$HEADER_FILE"; rm -rf "$WORK"' EXIT
chmod 600 "$HEADER_FILE"
printf 'Authorization: cpanel %s:%s\n' "$USER_NAME" "$(cat "$CONF_DIR/token")" > "$HEADER_FILE"

# Calls cPanel. Prints the JSON response; fails on HTTP or API errors.
uapi() {   # uapi Module function [curl args...]
    local module="$1" func="$2"; shift 2
    curl -sS --fail-with-body -m 120 -H @"$HEADER_FILE" "$@" "$API/execute/$module/$func" > "$WORK/resp" \
        || die "cPanel $module/$func failed (HTTP error). Is the token valid? $(head -c 300 "$WORK/resp")"
    python3 - "$WORK/resp" "$module/$func" <<'PY' || return 1
import json, sys
try:
    r = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(f"✗ cPanel {sys.argv[2]}: unexpected response (token wrong or expired?)")
if r.get("status") != 1:
    sys.exit(f"✗ cPanel {sys.argv[2]}: {'; '.join(r.get('errors') or ['unknown error'])}")
PY
    cat "$WORK/resp"
}

# Calls cPanel's older API 2 (needed for mkdir, compress and extract).
api2() {   # api2 Module function [--data-urlencode name=value ...]
    local module="$1" func="$2"; shift 2
    curl -sS --fail-with-body -m 300 -H @"$HEADER_FILE" -G "$API/json-api/cpanel" \
        --data-urlencode "cpanel_jsonapi_user=$USER_NAME" \
        --data-urlencode "cpanel_jsonapi_apiversion=2" \
        --data-urlencode "cpanel_jsonapi_module=$module" \
        --data-urlencode "cpanel_jsonapi_func=$func" "$@" > "$WORK/resp" \
        || die "cPanel $module::$func failed (HTTP error). $(head -c 300 "$WORK/resp")"
    python3 - "$WORK/resp" "$module::$func" <<'PY' || return 1
import json, sys
try:
    r = json.load(open(sys.argv[1]))["cpanelresult"]
except Exception:
    sys.exit(f"✗ cPanel {sys.argv[2]}: unexpected response")
data = r.get("data") or [{}]
if r.get("error") or not r.get("event", {}).get("result") or not all(d.get("result", 1) for d in data):
    sys.exit(f"✗ cPanel {sys.argv[2]}: {r.get('error') or data}")
PY
}

fileop() {   # fileop op source dest [extra curl args]
    local op="$1" src="$2" dest="$3"; shift 3
    api2 Fileman fileop --data-urlencode "op=$op" \
        --data-urlencode "sourcefiles=$src" --data-urlencode "destfiles=$dest" "$@"
}

json_get() {   # reads JSON on stdin; fails quietly if it isn't there
    python3 -c "
import json, sys
try:
    print(json.load(sys.stdin)$1)
except Exception:
    sys.exit(1)"
}

# --- Check -----------------------------------------------------------

step "Connecting to cPanel at $HOST as $USER_NAME"
HOME_DIR="$(uapi Variables get_user_information | json_get '["data"]["home"]')"
[[ "$HOME_DIR" == /* ]] || die "Couldn't read your home folder from cPanel."
echo "  home folder: $HOME_DIR"

if [[ "${1:-}" == "--check" ]]; then
    echo "✓ Token works. Nothing was changed."
    exit 0
fi

# Read-only: list a folder on the server, e.g. ./deploy.sh --ls public_html
if [[ "${1:-}" == "--ls" ]]; then
    dir="$HOME_DIR/${2:-}"
    uapi Fileman list_files -G --data-urlencode "dir=$dir" --data-urlencode "show_hidden=1" \
        --data-urlencode "types=dir|file" > "$WORK/ls"
    python3 - "$WORK/ls" <<'PY'
import json, sys, time
items = json.load(open(sys.argv[1]))["data"] or []
for f in sorted(items, key=lambda f: (f["type"] != "dir", f["file"].lower())):
    when = time.strftime("%Y-%m-%d", time.localtime(int(f.get("mtime") or 0)))
    size = "" if f["type"] == "dir" else f"{int(f.get('size') or 0):>10,}"
    print(f"  {'DIR ' if f['type'] == 'dir' else '    '} {when}  {size:>10}  {f['file']}")
print(f"  ({len(items)} items)")
PY
    exit 0
fi

# --- Build -----------------------------------------------------------

VERSION="$(sed -n "s/^const SITE_VERSION = '\(.*\)';/\1/p" "$HERE/private/lib.php")"
[[ -n "$VERSION" ]] || die "Couldn't read SITE_VERSION from private/lib.php."
for f in "$HERE"/public_html/*.php "$HERE"/private/*.php; do
    if command -v php >/dev/null; then php -l "$f" >/dev/null || die "PHP syntax error in $f"; fi
done

step "Building $ZIP_NAME (version $VERSION)"
( cd "$HERE" && zip -qr -X "$WORK/$ZIP_NAME" public_html private \
    -x "*.DS_Store" "private/data/*" "private/config.php" )
unzip -l "$WORK/$ZIP_NAME" | grep -qE "private/config\.php|\.sqlite" && die "Zip unexpectedly contains config or database."
cp "$WORK/$ZIP_NAME" "$HERE/$ZIP_NAME"

LIVE_VERSION="$(curl -sS -m 20 "$SITE/" | sed -n 's/.*&middot; v\([0-9][0-9.]*\).*/\1/p' | head -1)"
echo "  live site is on v${LIVE_VERSION:-unknown}"
if [[ "$LIVE_VERSION" == "$VERSION" ]]; then
    echo "  ! The live site already shows v$VERSION, so the final check can't prove the"
    echo "    new files arrived. Bump SITE_VERSION in private/lib.php for each release."
fi

# --- Back up the live site (code, config and guest database) ---------

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$HOME_DIR/wedding-backups/backup-$STAMP.zip"
step "Backing up the live site to ~/wedding-backups/backup-$STAMP.zip"
# Creating the folder fails harmlessly if it already exists.
api2 Fileman mkdir --data-urlencode "path=$HOME_DIR" --data-urlencode "name=wedding-backups" >/dev/null 2>&1 || true
fileop compress "$HOME_DIR/public_html,$HOME_DIR/private" "$BACKUP" --data-urlencode "metadata=zip"

# --- Upload, extract, tidy up ----------------------------------------

step "Uploading"
uapi Fileman upload_files -F "dir=$HOME_DIR" -F "overwrite=1" -F "file-1=@$WORK/$ZIP_NAME" > "$WORK/up"
[[ "$(json_get '["data"]["succeeded"]' < "$WORK/up")" == "1" ]] || die "Upload failed: $(cat "$WORK/up")"

step "Extracting into $HOME_DIR"
fileop extract "$HOME_DIR/$ZIP_NAME" "$HOME_DIR"

step "Removing the zip from the server"
fileop unlink "$HOME_DIR/$ZIP_NAME" "" >/dev/null || echo "  (couldn't delete it; remove $ZIP_NAME in File Manager)"

# --- Verify ----------------------------------------------------------

step "Checking $SITE shows v$VERSION"
for attempt in 1 2 3 4 5; do
    page="$(curl -sS -m 20 "$SITE/" || true)"
    # The version sits in the footer, as "v1.2.3</bdi>" (or "v1.2.3<" before 2.6).
    if grep -qE ">v${VERSION//./\\.}<" <<<"$page"; then
        echo "✓ Deployed version $VERSION. Backup: ~/wedding-backups/backup-$STAMP.zip"
        exit 0
    fi
    sleep 3
done
die "The site isn't showing v$VERSION yet. Check $SITE, and restore from the backup if something is wrong."
