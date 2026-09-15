<?php

declare(strict_types=1);

use App\Application\Service\Auth\LoginService;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlUserRepository;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$loginAction = new LoginAction(
    new LoginService(new MySqlUserRepository()),
);

$registerAuthRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/AuthRoutes.php';
$registerAuthRoutes($app, $loginAction);

$routeFiles = [
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/UserRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/PackageRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/BatchRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/DriverRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/DeliveryRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/TrackingRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/DashboardRoutes.php',
    __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/ZoneRoutes.php',
];

foreach ($routeFiles as $routeFile) {
    $registerRoutes = require $routeFile;
    $registerRoutes($app);
}

$app->run();
