<?php

// Router for PHP's built-in web server (local development only).
//
// Real files under public/ (built CSS/JS, images, fonts) are handed back to
// the built-in server so it serves them with the correct Content-Type.
// Everything else goes to Symfony's front controller.
//
// Passing public/index.php directly as the router instead sends every file
// through Symfony with a text/html Content-Type, and browsers then refuse
// to apply the stylesheets.
//
// Usage: php -d memory_limit=-1 -S 127.0.0.1:8000 -t public bin/dev-router.php

$publicDir = dirname(__DIR__).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file($publicDir.$path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicDir.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $publicDir.'/index.php';
