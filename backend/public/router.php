<?php

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path !== '/' && is_file(__DIR__.$path)) {
        return false;
    }
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
require dirname(__DIR__).'/vendor/autoload_runtime.php';
