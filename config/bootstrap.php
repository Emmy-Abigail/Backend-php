<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);

$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->load();
$dotenv->required([
    'APP_ENV',
    'DB_CONNECTION',
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_CHARSET',
    'DB_COLLATION',
    'JWT_SECRET',
    'JWT_ISSUER',
    'JWT_TTL_SECONDS',
    'MAIL_HOST',
    'MAIL_PORT',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_FROM_ADDRESS',
    'MAIL_FROM_NAME',
])->notEmpty();

/** @var callable(): Capsule $connectDatabase */
$connectDatabase = require __DIR__ . '/database.php';

return $connectDatabase();
