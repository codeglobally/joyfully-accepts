<?php
// Per-site styles worked out from config: the photo, its shape, the colours
// along its edges (for the soft wash past the photo) and any hidden links
// placed over it. style.css uses these through CSS variables.
const NO_SESSION = true;
require is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/lib.php' : __DIR__ . '/private/lib.php';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$css = fn($s) => addcslashes((string)$s, "'\\\n\r");
$c = photo_edge_colours();
$r = $c['right'];
$b = $c['bottom'];
?>
:root {
  --photo-url: <?= has_photo() ? "url('" . $css(asset_url(PHOTO)) . "')" : 'none' ?>;
  --photo-ratio: <?= round(photo_ratio(), 4) ?>;
  --photo-panel-start: <?= max(0.3, min(1.0, (float)PHOTO_PANEL_START)) ?>;
  --wash-wide: linear-gradient(to bottom, <?= $r[0] ?>, <?= $r[1] ?> 18%, <?= $r[2] ?> 35%, <?= $r[3] ?> 55%, <?= $r[4] ?> 74%, <?= $r[5] ?> 92%);
  --wash-tall: linear-gradient(to right, <?= $b[0] ?>, <?= $b[1] ?> 50%, <?= $b[2] ?>);
}
<?php if (has_photo() && photo_webp() !== ''): ?>
@supports (background-image: image-set(url('x.webp') type('image/webp'))) {
  :root { --photo-url: image-set(url('<?= $css(asset_url(photo_webp())) ?>') type('image/webp'), url('<?= $css(asset_url(PHOTO)) ?>') type('image/jpeg')); }
}
<?php endif; ?>
<?php foreach (array_values((array)PHOTO_HOTSPOTS) as $i => $spot): ?>
.photo-hotspot-<?= $i ?> { --hx: <?= (float)($spot['x'] ?? 0) ?>; --hy: <?= (float)($spot['y'] ?? 0) ?>; --hw: <?= (float)($spot['w'] ?? 0.1) ?>; --hh: <?= (float)($spot['h'] ?? 0.1) ?>; }
<?php endforeach;
