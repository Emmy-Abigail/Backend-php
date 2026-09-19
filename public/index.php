<?php

declare(strict_types=1);

use App\Application\Service\Auth\AuthenticateTokenService;
use App\Application\Service\Auth\LoginService;
use App\Application\Service\User\CreateUserService;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlUserRepository;
use App\Infrastructure\Adapter\Out\Security\JwtTokenService;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/bootstrap.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$userRepository = new MySqlUserRepository();
$tokenService = new JwtTokenService(
    $_ENV['JWT_SECRET'],
    $_ENV['JWT_ISSUER'],
    (int) $_ENV['JWT_TTL_SECONDS'],
);

$loginAction = new LoginAction(
    new LoginService($userRepository, $tokenService),
);

$authenticateTokenService = new AuthenticateTokenService($tokenService, $userRepository);
$jwtAuthMiddleware = new JwtAuthMiddleware($authenticateTokenService);
$meAction = new MeAction();

$createUserAction = new CreateUserAction(
    new CreateUserService($userRepository),
);

$registerAuthRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/AuthRoutes.php';
$registerAuthRoutes($app, $loginAction, $meAction, $jwtAuthMiddleware);

$registerUserRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/UserRoutes.php';
$registerUserRoutes($app, $createUserAction);

$routeFiles = [
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