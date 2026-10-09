<?php

/**
 * Development/E2E front controller for `php -S`: serves the Flutter web build, forwards /api and /up to Laravel,
 * and falls back to index.html for client-side routes (refresh on /admin/people must work).
 * Production uses nginx (infra/nginx.conf), not this file.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$backend = realpath(__DIR__.'/../backend/public');
$web = realpath(__DIR__.'/../frontend/build/web');

if (preg_match('#^/(api|up)(/|$)#', $uri)) {
    chdir($backend);
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $backend.'/index.php';
    require $backend.'/index.php';

    return true;
}
$file = $web.$uri;
if ($uri !== '/' && is_file($file) && str_starts_with(realpath($file), $web)) {
    $types = ['js' => 'application/javascript', 'wasm' => 'application/wasm', 'json' => 'application/json', 'css' => 'text/css', 'wav' => 'audio/wav', 'png' => 'image/png', 'ico' => 'image/x-icon', 'otf' => 'font/otf', 'ttf' => 'font/ttf', 'html' => 'text/html'];
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    header('Content-Type: '.($types[$ext] ?? mime_content_type($file)));
    readfile($file);

    return true;
}
header('Content-Type: text/html; charset=utf-8');
readfile($web.'/index.html');

return true;
