<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
// En la nube no hay archivo .env: las variables vienen del entorno del servicio.
$dotenv->safeLoad();
$dotenv->required([
    'APP_ENV',
    'DB_CONNECTION',
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_CHARSET',
    'DB_COLLATION',
])->notEmpty();

$database = [
    'adapter' => $_ENV['DB_CONNECTION'],
    'host' => $_ENV['DB_HOST'],
    'name' => $_ENV['DB_DATABASE'],
    'user' => $_ENV['DB_USERNAME'],
    'pass' => $_ENV['DB_PASSWORD'],
    'port' => (int) $_ENV['DB_PORT'],
    'charset' => $_ENV['DB_CHARSET'],
    'collation' => $_ENV['DB_COLLATION'],
];

// TiDB Cloud y otras bases gestionadas exigen TLS; en local DB_SSL_CA no se define.
if (($_ENV['DB_SSL_CA'] ?? '') !== '') {
    $database['mysql_attr_ssl_ca'] = $_ENV['DB_SSL_CA'];
}

return [
    'paths' => [
        'migrations' => 'database/migrations',
        'seeds' => 'database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $_ENV['APP_ENV'],
        'development' => $database,
        'production' => $database,
    ],
    'version_order' => 'creation',
];
