<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$envFile = dirname(__DIR__).'/.env';
if (is_file($envFile) || is_file($envFile.'.dist')) {
    (new Dotenv())->bootEnv($envFile);
}
