#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';

$projectDir = dirname(__DIR__);
if (is_file($projectDir.'/.env') || is_file($projectDir.'/.env.dist')) {
    (new Dotenv())->bootEnv($projectDir.'/.env');
}

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

foreach ([
    'APP_SECRET' => 'change_me_not_for_production',
    'DATABASE_URL' => 'postgresql://app:historic@127.0.0.1:5432/app?serverVersion=16&charset=utf8',
    'AUTH_DEV_MODE' => '1',
    'DEV_USER_NAME' => 'Demo Mitglied',
    'DEV_USER_GROUPS' => 'admin,Mitglieder',
    'AUTH0_DOMAIN' => '',
    'AUTH0_AUDIENCE' => '',
    'AUTH0_GROUPS_CLAIM' => 'https://historic-winterthur.ch/groups',
    'CORS_ALLOW_ORIGIN' => '^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$',
    'DEFAULT_URI' => 'http://localhost',
] as $name => $value) {
    if (!isset($_ENV[$name]) && !isset($_SERVER[$name])) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}

$output = new ConsoleOutput();
$run = static function (array $input) use ($output): int {
    $application = new Application(new Kernel('test', true));
    $application->setAutoExit(false);

    return $application->run(new ArrayInput($input), $output);
};

$exit = $run([
    'command' => 'doctrine:database:create',
    '--if-not-exists' => true,
    '--no-interaction' => true,
]);
if ($exit !== 0) {
    exit($exit);
}

$kernel = new Kernel('test', true);
$kernel->boot();
$doctrine = $kernel->getContainer()->get('doctrine');
if (!$doctrine instanceof ManagerRegistry) {
    fwrite(STDERR, "Doctrine is not available.\n");
    exit(1);
}
$database = (string) $doctrine->getConnection()->getDatabase();
$kernel->shutdown();

if ($database === 'app' || !str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to migrate database \"{$database}\". Tests must use a name ending in _test.\n");
    exit(1);
}

fwrite(STDOUT, "Preparing test database {$database}.\n");

exit($run([
    'command' => 'doctrine:migrations:migrate',
    '--no-interaction' => true,
]));
