<?php
// Router for PHP's built-in web server (php -S), used by the tests and CI.
// PHP's server ignores .htaccess, so this copies what it does on Apache:
// plain files get the basic security headers, and anything missing or
// blocked gets the site's own "Page not found" page.
//
//   php -S localhost:8097 -t public_html tests/router.php

$root = (string)realpath($_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath($root . $path);

$notFound = function () use ($root): bool {
    require $root . '/404.php';
    return true;
};

// Missing, outside the web root, hidden, or one of the names .htaccess blocks.
if ($file === false || strpos($file, $root) !== 0
    || preg_match('#/\.|(^|/)(private|sections|data)(/|$)|(^|/)(config|lib)\.php$|\.(sqlite|sqlite-journal|bak|swp|log)$|~$#i', $path)) {
    return $notFound();
}
if (is_dir($file)) {
    return is_file($file . '/index.php') ? false : $notFound();   // no directory listings
}
if (substr($file, -4) === '.php') {
    return false;   // PHP pages send their own headers
}

$types = ['css' => 'text/css', 'js' => 'application/javascript', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'txt' => 'text/plain'];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header_remove('X-Powered-By');   // Apache doesn't add it to plain files, so neither should we
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($file);
return true;
