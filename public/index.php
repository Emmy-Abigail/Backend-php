<?php

declare(strict_types=1);

use App\Application\Service\Auth\AuthenticateTokenService;
use App\Application\Service\Auth\ChangePasswordService;
use App\Application\Service\Auth\ConfirmPasswordResetService;
use App\Application\Service\Auth\LoginService;
use App\Application\Service\Auth\RequestPasswordResetService;
use App\Application\Service\Catalog\GetFailureReasonsService;
use App\Application\Service\Catalog\GetGeographyService;
use App\Application\Service\Catalog\GetSedesService;
use App\Application\Service\Catalog\GetVehicleTypesService;
use App\Application\Service\User\ChangeUserStatusService;
use App\Application\Service\User\CreateUserService;
use App\Application\Service\User\ListUsersService;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use App\Infrastructure\Adapter\In\Http\Action\User\ChangeUserStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use App\Infrastructure\Adapter\In\Http\Action\User\ListUsersAction;
use App\Infrastructure\Adapter\In\Http\Middleware\CorsMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Adapter\Out\Notification\SmtpUserCredentialsMailer;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlCatalogRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPasswordResetTokenRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlUserRepository;
use App\Infrastructure\Adapter\Out\Security\JwtTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/bootstrap.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();

// Endpoint de verificacion de salud (Health Check)
$app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $response->getBody()->write((string) json_encode(['status' => 'ok']));
    return $response->withHeader('Content-Type', 'application/json');
});

$userRepository = new MySqlUserRepository();
$passwordResetTokenRepository = new MySqlPasswordResetTokenRepository();
$catalogRepository = new MySqlCatalogRepository();
$tokenService = new JwtTokenService(
    $_ENV['JWT_SECRET'],
    $_ENV['JWT_ISSUER'],
    (int) $_ENV['JWT_TTL_SECONDS'],
);

$loginAction = new LoginAction(
    new LoginService($userRepository, $tokenService, $catalogRepository),
);

$authenticateTokenService = new AuthenticateTokenService($tokenService, $userRepository, $catalogRepository);
$jwtAuthMiddleware = new JwtAuthMiddleware($authenticateTokenService);
$adminRoleMiddleware = new RoleMiddleware('ADMINISTRADOR', 'Admin');
$mustChangePasswordMiddleware = new MustChangePasswordMiddleware();
$meAction = new MeAction();
$changePasswordAction = new ChangePasswordAction(
    new ChangePasswordService($userRepository, $tokenService),
);

$mailer = new SmtpUserCredentialsMailer(
    $_ENV['MAIL_HOST'],
    (int) $_ENV['MAIL_PORT'],
    $_ENV['MAIL_USERNAME'],
    $_ENV['MAIL_PASSWORD'],
    $_ENV['MAIL_FROM_ADDRESS'],
    $_ENV['MAIL_FROM_NAME'],
    $_ENV['PASSWORD_RESET_URL'] ?? null,
);
$requestPasswordResetAction = new RequestPasswordResetAction(
    new RequestPasswordResetService($userRepository, $passwordResetTokenRepository, $mailer),
);
$confirmPasswordResetAction = new ConfirmPasswordResetAction(
    new ConfirmPasswordResetService($userRepository, $passwordResetTokenRepository),
);

$createUserAction = new CreateUserAction(
    new CreateUserService(
        $userRepository,
        $catalogRepository,
        $mailer,
    ),
);
$listUsersAction = new ListUsersAction(
    new ListUsersService($userRepository),
);
$changeUserStatusAction = new ChangeUserStatusAction(
    new ChangeUserStatusService($userRepository),
);

$registerAuthRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/AuthRoutes.php';
$registerAuthRoutes(
    $app,
    $loginAction,
    $meAction,
    $changePasswordAction,
    $requestPasswordResetAction,
    $confirmPasswordResetAction,
    $jwtAuthMiddleware,
);

$registerUserRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/UserRoutes.php';
$registerUserRoutes($app, $createUserAction, $listUsersAction, $changeUserStatusAction, $jwtAuthMiddleware, $adminRoleMiddleware, $mustChangePasswordMiddleware);

// Catalogos
$getGeographyAction = new GetGeographyAction(new GetGeographyService($catalogRepository));
$getSedesAction = new GetSedesAction(new GetSedesService($catalogRepository));
$getVehicleTypesAction = new GetVehicleTypesAction(new GetVehicleTypesService($catalogRepository));
$getFailureReasonsAction = new GetFailureReasonsAction(new GetFailureReasonsService($catalogRepository));

$registerCatalogRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/CatalogRoutes.php';
$registerCatalogRoutes(
    $app,
    $getGeographyAction,
    $getSedesAction,
    $getVehicleTypesAction,
    $getFailureReasonsAction
);

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

// Manejador global de errores (JSON renderer para 404, 405 y 500)
$isDevelopment = ($_ENV['APP_ENV'] ?? 'production') === 'development';
$errorMiddleware = $app->addErrorMiddleware($isDevelopment, true, true);
$customErrorHandler = function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app, $isDevelopment): ResponseInterface {
    $response = $app->getResponseFactory()->createResponse();

    $statusCode = 500;
    $errorCode = 'error_interno';
    $message = 'Error interno del servidor';

    if ($exception instanceof HttpNotFoundException) {
        $statusCode = 404;
        $errorCode = 'no_encontrado';
        $message = 'Ruta no encontrada';
    } elseif ($exception instanceof HttpMethodNotAllowedException) {
        $statusCode = 405;
        $errorCode = 'metodo_no_permitido';
        $message = 'MÃ©todo no permitido';
    } elseif ($displayErrorDetails) {
        $message = $exception->getMessage();
    }

    $payload = [
        'message' => $message,
        'error' => $errorCode,
    ];

    $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response
        ->withStatus($statusCode)
        ->withHeader('Content-Type', 'application/json');
};
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// CORS como el middleware mas externo
$app->add(new CorsMiddleware($_ENV['FRONTEND_URL'] ?? 'http://localhost:4200'));

$app->run();