#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$projectDirectory = dirname(__DIR__);
require $projectDirectory.'/vendor/autoload.php';

(new Dotenv())->bootEnv($projectDirectory.'/.env');

$binary = $projectDirectory.'/var/mercure/mercure';
$configuration = $projectDirectory.'/mercure/Caddyfile';
if (!is_executable($binary) || !is_file($configuration)) {
    fwrite(STDERR, "Mercure is not installed. Run bin/install-mercure.sh first.\n");
    exit(1);
}

$secret = $_SERVER['MERCURE_JWT_SECRET'] ?? $_ENV['MERCURE_JWT_SECRET'] ?? '';
$issuer = $_SERVER['MERCURE_ISSUER'] ?? $_ENV['MERCURE_ISSUER'] ?? '';
if ('' === $secret || '' === $issuer) {
    fwrite(STDERR, "MERCURE_JWT_SECRET and MERCURE_ISSUER must be configured.\n");
    exit(1);
}

putenv('MERCURE_PUBLISHER_JWT_KEY='.$secret);
putenv('MERCURE_SUBSCRIBER_JWT_KEY='.$secret);
putenv('MERCURE_TRUSTED_ISSUERS='.$issuer);

$process = proc_open(
    [$binary, 'run', '--config', $configuration],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $projectDirectory,
);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start the Mercure Hub process.\n");
    exit(1);
}

exit(proc_close($process));
