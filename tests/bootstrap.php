<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);

require $baseDir . '/vendor/autoload.php';

if (is_file($baseDir . '/.env')) {
    (new Symfony\Component\Dotenv\Dotenv())->load($baseDir . '/.env');
}
