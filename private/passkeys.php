<?php
// Passkeys (WebAuthn) for admin sign-in, with no libraries.
//
// A passkey is a key pair kept by the person's device (phone, laptop, password
// manager or security key). The site stores only the public half. To sign in,
// the site sends a random challenge; the device asks for Face ID, a
// fingerprint or a PIN, then signs the challenge, and the site checks the
// signature. The browser binds every signature to this site's address, so a
// look-alike site can't collect one.
//
// Once someone has a passkey, it's required: they sign in with the passkey on
// its own, or with their password and then the passkey. If the owner loses
// theirs, PASSKEY_RECOVERY in config.php allows password-only sign-in again;
// other admins can be sent a reset link, which also clears their passkeys.
//
// Attestation (proof of which company made the device) isn't checked, as on
// most sites: what matters is that the same key signs in each time.

const PASSKEY_SECONDS = 300;   // how long a challenge can be used for
const PASSKEY_FLAG_PRESENT = 0x01;     // someone touched or looked at the device
const PASSKEY_FLAG_VERIFIED = 0x04;    // ...and unlocked it (Face ID, fingerprint, PIN)
const PASSKEY_FLAG_NEW_KEY = 0x40;     // the data includes a new public key

function passkeys_db(): PDO
{
    static $ready = false;
    $pdo = db();
    if (!$ready) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS passkeys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,             -- 0 = the owner, otherwise users.id
            credential_id TEXT NOT NULL UNIQUE,   -- base64url, from the device
            public_key TEXT NOT NULL,             -- PEM
            sign_count INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TEXT
        )');
        $ready = true;
    }
    return $pdo;
}

function user_passkeys(int $userId): array
{
    $q = passkeys_db()->prepare('SELECT * FROM passkeys WHERE user_id = ? ORDER BY created_at');
    $q->execute([$userId]);
    return $q->fetchAll();
}

function find_passkey(string $credentialId): ?array
{
    $q = passkeys_db()->prepare('SELECT * FROM passkeys WHERE credential_id = ?');
    $q->execute([$credentialId]);
    return $q->fetch() ?: null;
}

// True if this person must use a passkey to sign in.
function passkey_required(int $userId): bool
{
    return !($userId === 0 && PASSKEY_RECOVERY) && user_passkeys($userId);
}

function b64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function b64url_decode(string $text): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]*$/', $text)) {
        return null;
    }
    $bytes = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $bytes === false ? null : $bytes;
}

// --- Where passkeys work ----------------------------------------------
// The site's own address (from SITE_URL, over HTTPS), or localhost while
// developing. Returns [relying party id, origin] or null.
function passkey_origin(): ?array
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostname = (string)preg_replace('/:\d+$/', '', $host);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $local = $hostname === 'localhost' || substr($hostname, -10) === '.localhost';
    $site = $https && $hostname === strtolower((string)parse_url(SITE_URL, PHP_URL_HOST));
    if ($hostname === '' || !($site || $local)) {
        return null;
    }
    return [$hostname, ($https ? 'https' : 'http') . '://' . $host];
}

// --- Challenges -------------------------------------------------------
// One at a time per session, used once, and only for what it was made for.

function new_passkey_challenge(string $purpose, int $userId = -1): string
{
    $challenge = b64url_encode(random_bytes(32));
    $_SESSION['passkey'] = ['challenge' => $challenge, 'purpose' => $purpose, 'user' => $userId, 'until' => time() + PASSKEY_SECONDS];
    return $challenge;
}

function take_passkey_challenge(string $purpose): ?array
{
    $c = $_SESSION['passkey'] ?? null;
    unset($_SESSION['passkey']);
    return is_array($c) && ($c['purpose'] ?? '') === $purpose && ($c['until'] ?? 0) >= time() ? $c : null;
}

// A stable, meaningless id for each person, so their passkey doesn't carry
// their username or database id.
function passkey_user_handle(int $userId): string
{
    $secret = setting('passkey_secret');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        save_setting('passkey_secret', $secret);
    }
    return substr(hash_hmac('sha256', 'passkey-user|' . $userId, $secret, true), 0, 16);
}

// Options for navigator.credentials.create() (adding a passkey).
function passkey_create_options(array $origin, array $me, string $username): array
{
    return [
        'challenge' => new_passkey_challenge('register', (int)$me['id']),
        'rp' => ['id' => $origin[0], 'name' => COUPLE_NAMES . ' wedding admin'],
        'user' => ['id' => b64url_encode(passkey_user_handle((int)$me['id'])), 'name' => $username, 'displayName' => $me['name']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],   // ES256, RS256
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'attestation' => 'none',
        'timeout' => PASSKEY_SECONDS * 1000,
        'excludeCredentials' => array_map(fn($p) => ['type' => 'public-key', 'id' => $p['credential_id']], user_passkeys((int)$me['id'])),
    ];
}

// Options for navigator.credentials.get(): any of this site's passkeys
// ($userId -1), or one of a particular person's.
function passkey_get_options(array $origin, string $purpose, int $userId = -1): array
{
    return [
        'challenge' => new_passkey_challenge($purpose, $userId),
        'rpId' => $origin[0],
        'userVerification' => 'required',
        'timeout' => PASSKEY_SECONDS * 1000,
        'allowCredentials' => $userId < 0 ? [] : array_map(fn($p) => ['type' => 'public-key', 'id' => $p['credential_id']], user_passkeys($userId)),
    ];
}

// --- CBOR, the binary format passkeys use -----------------------------
// Only what WebAuthn needs: numbers, byte and text strings, arrays, maps,
// true/false/null. Throws RuntimeException on anything else or bad data.

function cbor_read(string $data, int &$pos, int $depth = 0)
{
    if ($depth > 8 || $pos >= strlen($data)) {
        throw new RuntimeException('Bad CBOR');
    }
    $byte = ord($data[$pos++]);
    $major = $byte >> 5;
    $info = $byte & 31;
    if ($major === 7) {
        $simple = [20 => false, 21 => true, 22 => null];
        if (!array_key_exists($info, $simple)) {
            throw new RuntimeException('Unsupported CBOR value');
        }
        return $simple[$info];
    }
    $n = cbor_length($data, $pos, $info);
    switch ($major) {
        case 0:
            return $n;
        case 1:
            return -1 - $n;
        case 2:   // bytes
        case 3:   // text
            if ($n > strlen($data) - $pos) {
                throw new RuntimeException('Bad CBOR');
            }
            $value = substr($data, $pos, $n);
            $pos += $n;
            return $value;
        case 4:
            $list = [];
            for ($i = 0; $i < $n; $i++) {
                $list[] = cbor_read($data, $pos, $depth + 1);
            }
            return $list;
        case 5:
            $map = [];
            for ($i = 0; $i < $n; $i++) {
                $key = cbor_read($data, $pos, $depth + 1);
                if (!is_int($key) && !is_string($key)) {
                    throw new RuntimeException('Bad CBOR');
                }
                $map[$key] = cbor_read($data, $pos, $depth + 1);
            }
            return $map;
    }
    throw new RuntimeException('Unsupported CBOR value');
}

function cbor_length(string $data, int &$pos, int $info): int
{
    if ($info < 24) {
        return $info;
    }
    $size = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info] ?? 0;
    if ($size === 0 || $size > strlen($data) - $pos) {
        throw new RuntimeException('Bad CBOR');
    }
    $n = 0;
    for ($i = 0; $i < $size; $i++) {
        $n = ($n << 8) | ord($data[$pos + $i]);
    }
    $pos += $size;
    if ($n < 0 || $n > 0x7FFFFFFF) {
        throw new RuntimeException('CBOR value too large');
    }
    return $n;
}

// --- Keys --------------------------------------------------------------

// A DER element: tag, length, content.
function der(string $tag, string $content): string
{
    $len = strlen($content);
    $bytes = ltrim(pack('N', $len), "\0");
    return $tag . ($len < 128 ? chr($len) : chr(0x80 | strlen($bytes)) . $bytes) . $content;
}

// Turns a passkey's public key (COSE) into PEM for openssl, or null if it
// isn't a kind we accept: P-256 elliptic curve (ES256) or RSA of 2048 bits
// or more (RS256).
function cose_to_pem(array $key): ?string
{
    $kty = $key[1] ?? null;
    $alg = $key[3] ?? null;
    if ($kty === 2 && $alg === -7 && ($key[-1] ?? null) === 1) {
        $x = $key[-2] ?? null;
        $y = $key[-3] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        // SubjectPublicKeyInfo for id-ecPublicKey on prime256v1, uncompressed point.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    } elseif ($kty === 3 && $alg === -257) {
        $n = $key[-1] ?? null;
        $e = $key[-2] ?? null;
        if (!is_string($n) || !is_string($e) || strlen(ltrim($n, "\0")) < 256 || $e === '' || strlen($e) > 8) {
            return null;
        }
        $int = fn(string $b) => der("\x02", (ord(ltrim($b, "\0")[0] ?? "\0") > 127 ? "\0" : '') . ltrim($b, "\0"));
        $der = der("\x30", der("\x30", hex2bin('06092a864886f70d0101010500')) . der("\x03", "\0" . der("\x30", $int($n) . $int($e))));
    } else {
        return null;
    }
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem) ? $pem : null;
}

// --- Checking what the device sent ------------------------------------

// The device's summary: [rp id hash, flags, signature counter, credential id, public key].
function parse_authenticator_data(string $data): ?array
{
    if (strlen($data) < 37) {
        return null;
    }
    $out = ['rp_hash' => substr($data, 0, 32), 'flags' => ord($data[32]), 'count' => unpack('N', substr($data, 33, 4))[1],
        'credential_id' => null, 'public_key' => null];
    if ($out['flags'] & PASSKEY_FLAG_NEW_KEY) {
        if (strlen($data) < 55) {
            return null;
        }
        $idLength = unpack('n', substr($data, 53, 2))[1];
        if ($idLength < 1 || $idLength > 1023 || strlen($data) < 55 + $idLength + 1) {
            return null;
        }
        $out['credential_id'] = substr($data, 55, $idLength);
        $pos = 55 + $idLength;
        try {
            $key = cbor_read($data, $pos);
        } catch (RuntimeException $e) {
            return null;
        }
        if (!is_array($key)) {
            return null;
        }
        $out['public_key'] = $key;
    }
    return $out;
}

// The browser's own record of the request: it fills in the real origin and
// the challenge, so these can't be faked by a look-alike site.
function client_data_ok(string $json, string $type, string $challenge, string $origin): bool
{
    $c = json_decode($json, true);
    return is_array($c) && ($c['type'] ?? null) === $type
        && is_string($c['challenge'] ?? null) && hash_equals($challenge, $c['challenge'])
        && ($c['origin'] ?? null) === $origin && empty($c['crossOrigin']);
}

// Checks a new passkey from navigator.credentials.create(). $expect holds
// 'challenge', 'origin' and 'rp_id'. Returns ['credential_id' (base64url),
// 'public_key' (PEM), 'count'] or a message saying what was wrong.
function verify_passkey_registration(array $expect, string $clientDataJson, string $attestationObject)
{
    if (!client_data_ok($clientDataJson, 'webauthn.create', $expect['challenge'], $expect['origin'])) {
        return 'That passkey was made for a different request or site.';
    }
    try {
        $pos = 0;
        $attestation = cbor_read($attestationObject, $pos);
    } catch (RuntimeException $e) {
        return 'The passkey data couldn\'t be read.';
    }
    $auth = is_array($attestation) && is_string($attestation['authData'] ?? null) ? parse_authenticator_data($attestation['authData']) : null;
    if (!$auth || $auth['credential_id'] === null) {
        return 'The passkey data couldn\'t be read.';
    }
    if (!hash_equals(hash('sha256', $expect['rp_id'], true), $auth['rp_hash'])) {
        return 'That passkey was made for a different site.';
    }
    if (($auth['flags'] & (PASSKEY_FLAG_PRESENT | PASSKEY_FLAG_VERIFIED)) !== (PASSKEY_FLAG_PRESENT | PASSKEY_FLAG_VERIFIED)) {
        return 'The device didn\'t confirm it was you (Face ID, fingerprint or PIN).';
    }
    $pem = cose_to_pem($auth['public_key']);
    if ($pem === null) {
        return 'That kind of passkey isn\'t supported. Try your phone, computer or password manager instead.';
    }
    return ['credential_id' => b64url_encode($auth['credential_id']), 'public_key' => $pem, 'count' => $auth['count']];
}

// Checks a sign-in from navigator.credentials.get() against the stored
// passkey. Returns the new signature count, or a message saying what was wrong.
function verify_passkey_assertion(array $expect, array $stored, string $clientDataJson, string $authenticatorData, string $signature)
{
    if (!client_data_ok($clientDataJson, 'webauthn.get', $expect['challenge'], $expect['origin'])) {
        return 'That sign-in was for a different request or site.';
    }
    $auth = parse_authenticator_data($authenticatorData);
    if (!$auth || !hash_equals(hash('sha256', $expect['rp_id'], true), $auth['rp_hash'])) {
        return 'That sign-in was for a different site.';
    }
    if (($auth['flags'] & (PASSKEY_FLAG_PRESENT | PASSKEY_FLAG_VERIFIED)) !== (PASSKEY_FLAG_PRESENT | PASSKEY_FLAG_VERIFIED)) {
        return 'The device didn\'t confirm it was you (Face ID, fingerprint or PIN).';
    }
    $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
    if (openssl_verify($signed, $signature, (string)$stored['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        return 'The passkey\'s signature didn\'t match.';
    }
    // Devices that count sign-ins must count up; going backwards suggests a copied key.
    if (($auth['count'] !== 0 || (int)$stored['sign_count'] !== 0) && $auth['count'] <= (int)$stored['sign_count']) {
        return 'That passkey looks like it may have been copied, so it wasn\'t accepted.';
    }
    return $auth['count'];
}
