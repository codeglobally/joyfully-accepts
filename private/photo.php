<?php
// The home-page photo: where it is, its shape, and the colours along its
// edges (used for the soft colour wash that continues past the photo).

// Folder that the site is served from (public_html).
function public_dir(): string
{
    $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    return $root !== '' && is_dir($root) ? $root : dirname(APP_DIR) . '/public_html';
}

function photo_file(string $url): string
{
    return public_dir() . '/' . ltrim($url, '/');
}

function has_photo(): bool
{
    return PHOTO !== '' && is_readable(photo_file(PHOTO));
}

// A file's address with its last-changed time added. Browsers can then keep
// it for a long time and still fetch the new one as soon as it changes.
function asset_url(string $url): string
{
    $file = photo_file($url);
    return $url . (is_file($file) ? '?v=' . filemtime($file) : '');
}

// A WebP copy of the photo (same name, ending .webp), if you've added one.
// It's usually about half the size, so pages load faster.
function photo_webp(): string
{
    $webp = (string)preg_replace('/\.(jpe?g|png)$/i', '.webp', PHOTO);
    return $webp !== PHOTO && is_readable(photo_file($webp)) ? $webp : '';
}

function email_photo_url(): string
{
    return PHOTO_EMAIL !== '' ? PHOTO_EMAIL : PHOTO;
}

// Width / height of the photo (e.g. 1.264 for 1187 x 939).
function photo_ratio(): float
{
    static $ratio = null;
    if ($ratio === null) {
        $size = has_photo() ? @getimagesize(photo_file(PHOTO)) : false;
        $ratio = $size && $size[1] ? $size[0] / $size[1] : 1.5;
    }
    return $ratio;
}

// Average colours along the photo's right edge (top to bottom) and bottom
// edge (left to right). Worked out once with the GD image library and
// remembered until the photo changes. Without GD, neutral tones are used.
function photo_edge_colours(): array
{
    $neutral = ['right' => ['#d9d4cc', '#cfc8bd', '#c5bdb0'], 'bottom' => ['#cfc8bd', '#c5bdb0', '#bdb4a6']];
    if (!has_photo() || !function_exists('imagecreatefromstring')) {
        return $neutral;
    }
    $file = photo_file(PHOTO);
    $key = 'photo_colours_' . md5(PHOTO . filemtime($file));
    $q = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $q->execute([$key]);
    if ($cached = $q->fetchColumn()) {
        return json_decode($cached, true);
    }
    $im = @imagecreatefromstring((string)file_get_contents($file));
    if (!$im) {
        return $neutral;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $avg = function ($x0, $y0, $x1, $y1) use ($im) {
        $r = $g = $b = $n = 0;
        $step = max(1, (int)(($x1 - $x0) * ($y1 - $y0) / 4000));
        for ($x = $x0; $x < $x1; $x += $step) {
            for ($y = $y0; $y < $y1; $y += $step) {
                $c = imagecolorat($im, $x, $y);
                $r += ($c >> 16) & 255;
                $g += ($c >> 8) & 255;
                $b += $c & 255;
                $n++;
            }
        }
        return $n ? sprintf('#%02x%02x%02x', $r / $n, $g / $n, $b / $n) : '#cccccc';
    };
    $bands = [[0, .12], [.12, .25], [.25, .45], [.45, .65], [.65, .82], [.82, 1]];
    $colours = ['right' => [], 'bottom' => []];
    foreach ($bands as [$a, $z]) {
        $colours['right'][] = $avg((int)($w * .93), (int)($h * $a), $w, max((int)($h * $z), (int)($h * $a) + 1));
    }
    // The wash below the photo uses its left and right sides (usually
    // scenery) rather than the middle or bottom edge, which are often people.
    $left = $avg(0, (int)($h * .45), (int)($w * .07), (int)($h * .8));
    $right = $avg((int)($w * .93), (int)($h * .45), $w, (int)($h * .8));
    $mix = sprintf('#%06x', (int)array_sum(array_map(
        fn($shift) => ((((hexdec(substr($left, 1)) >> $shift) & 255) + ((hexdec(substr($right, 1)) >> $shift) & 255)) >> 1) << $shift,
        [16, 8, 0])));
    $colours['bottom'] = [$left, $mix, $right];
    if (PHP_VERSION_ID < 80000) {
        imagedestroy($im);   // frees memory on PHP 7; automatic (and deprecated) since PHP 8
    }
    db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([$key, json_encode($colours)]);
    return $colours;
}
