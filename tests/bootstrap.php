<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Prefer the bdi-managed chromedriver in ./drivers over any stale system one (Panther E2E).
putenv('PATH='.dirname(__DIR__).'/drivers'.\PATH_SEPARATOR.getenv('PATH'));

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
