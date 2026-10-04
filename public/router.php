<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$publicDirectory = realpath(__DIR__);
$requestedFile = is_string($path) ? realpath(__DIR__.$path) : false;

if (
    is_string($path)
    && $path !== '/index.php'
    && $path !== '/router.php'
    && $requestedFile !== false
    && $publicDirectory !== false
    && str_starts_with($requestedFile, $publicDirectory.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    return false;
}

require __DIR__.'/index.php';
