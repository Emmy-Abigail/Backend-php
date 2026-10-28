<?php

declare(strict_types=1);

use App\Application\Service\Auth\AuthenticateTokenService;
use App\Application\Service\Auth\ChangePasswordService;
use App\Application\Service\Auth\ConfirmPasswordResetService;
use App\Application\Service\Auth\LoginService;
use App\Application\Service\Auth\RequestPasswordResetService;
use App\Application\Service\Batch\DispatchBatchService;
use App\Application\Service\Batch\GetBatchService;
use App\Application\Service\Batch\ListBatchesService;
use App\Application\Service\Batch\StartRouteService;
use App\Application\Service\Delivery\FinalStatusService;
use App\Application\Service\Delivery\GetEvidenceService;
use App\Application\Service\Catalog\GetFailureReasonsService;
use App\Application\Service\Catalog\GetGeographyService;
use App\Application\Service\Catalog\GetSedesService;
use App\Application\Service\Catalog\GetVehicleTypesService;
use App\Application\Service\Pricing\QuoteShipmentService;
use App\Application\Service\Package\GetPackageService;
use App\Application\Service\Package\ListPackagesService;
use App\Application\Service\Package\RegisterPackageService;
use App\Application\Service\Tracking\GetTrackingService;
use App\Application\Service\User\ChangeUserStatusService;
use App\Application\Service\User\CreateUserService;
use App\Application\Service\User\ListUsersService;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\SessionStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\DispatchBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\GetBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\ListBatchesAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\StartRouteAction;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\FinalStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\GetEvidenceAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetCatalogsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\QuoteAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\GetPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\ListPackagesAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\RegisterPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Tracking\GetTrackingAction;
use App\Infrastructure\Adapter\In\Http\Action\User\ChangeUserStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use App\Infrastructure\Adapter\In\Http\Action\User\ListUsersAction;
use App\Infrastructure\Adapter\In\Http\Middleware\CorsMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Adapter\Out\Notification\SmtpUserCredentialsMailer;
use App\Infrastructure\Adapter\Out\Assignment\HttpBatchAssignmentGateway;
use App\Infrastructure\Adapter\Out\Http\StreamHttpTransport;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageQueryRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlBatchDispatchRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlBatchReadRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlBatchRouteRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlFinalStatusRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageEvidenceRepository;
use App\Infrastructure\Adapter\Out\Storage\LocalEvidenceStorage;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageRegistrationRepository;
use App\Infrastructure\Adapter\Out\Pricing\LocalPackagePricing;
use App\Infrastructure\Adapter\Out\Route\HttpRouteOptimizerGateway;
use App\Infrastructure\Adapter\Out\Security\FileRateLimiter;
use App\Infrastructure\Adapter\Out\Security\RandomTrackingGenerator;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlCatalogRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPasswordResetTokenRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlTariffRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlUserRepository;
use App\Infrastructure\Adapter\Out\Security\JwtTokenService;
use Illuminate\Database\Capsule\Manager as Capsule;
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
$sessionStatusAction = new SessionStatusAction();
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
    $sessionStatusAction,
    $changePasswordAction,
    $requestPasswordResetAction,
    $confirmPasswordResetAction,
    $jwtAuthMiddleware,
);

$registerUserRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/UserRoutes.php';
$registerUserRoutes($app, $createUserAction, $listUsersAction, $changeUserStatusAction, $jwtAuthMiddleware, $adminRoleMiddleware, $mustChangePasswordMiddleware);

// Catalogos
$getGeographyService = new GetGeographyService($catalogRepository);
$getVehicleTypesService = new GetVehicleTypesService($catalogRepository);
$getFailureReasonsService = new GetFailureReasonsService($catalogRepository);
$getGeographyAction = new GetGeographyAction($getGeographyService);
$getSedesAction = new GetSedesAction(new GetSedesService($catalogRepository));
$getVehicleTypesAction = new GetVehicleTypesAction($getVehicleTypesService);
$getFailureReasonsAction = new GetFailureReasonsAction($getFailureReasonsService);
$getCatalogsAction = new GetCatalogsAction($getGeographyService, $getVehicleTypesService, $getFailureReasonsService);

$registerCatalogRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/CatalogRoutes.php';
$registerCatalogRoutes(
    $app,
    $getGeographyAction,
    $getSedesAction,
    $getVehicleTypesAction,
    $getFailureReasonsAction,
    $getCatalogsAction,
    $jwtAuthMiddleware,
);

// Cotizacion (solo Operador)
$tariffRepository = new MySqlTariffRepository();
$quoteAction = new QuoteAction(
    new QuoteShipmentService($catalogRepository, $tariffRepository),
);
$operatorRoleMiddleware = new RoleMiddleware('OPERADOR');

$registerQuoteRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/QuoteRoutes.php';
$registerQuoteRoutes($app, $quoteAction, $jwtAuthMiddleware, $operatorRoleMiddleware, $mustChangePasswordMiddleware);

// El registro calcula el precio en PHP antes de solicitar la asignación a Python.
$registerPackageAction = new RegisterPackageAction(new RegisterPackageService(
    new MySqlPackageRegistrationRepository(Capsule::connection()),
    new LocalPackagePricing($tariffRepository),
    new HttpBatchAssignmentGateway(
        new StreamHttpTransport(),
        $_ENV['ASSIGNMENT_SERVICE_URL'] ?? '',
        $_ENV['ASSIGNMENT_API_KEY'] ?? '',
        (float) ($_ENV['ASSIGNMENT_TIMEOUT_SECONDS'] ?? 3),
    ),
    new RandomTrackingGenerator(),
));
$packageQueryRepository = new MySqlPackageQueryRepository(Capsule::connection());
$listPackagesAction = new ListPackagesAction(new ListPackagesService($packageQueryRepository));
$getPackageAction = new GetPackageAction(new GetPackageService($packageQueryRepository));
$registerPackageRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/PackageRoutes.php';
$registerPackageRoutes(
    $app,
    $registerPackageAction,
    $listPackagesAction,
    $getPackageAction,
    $jwtAuthMiddleware,
    new RoleMiddleware('OPERADOR'),
    $mustChangePasswordMiddleware,
    new RoleMiddleware('OPERADOR', 'CONDUCTOR'),
);

// Lotes: consulta (Operador y Conductor) y despacho (Operador). El orden de las paradas lo calcula Python.
// La ruta usa el mismo servicio que la asignación, salvo que se definan las variables ROUTE_*.
$batchReadRepository = new MySqlBatchReadRepository(Capsule::connection());
$listBatchesAction = new ListBatchesAction(new ListBatchesService($batchReadRepository));
$getBatchAction = new GetBatchAction(new GetBatchService($batchReadRepository));
$dispatchBatchAction = new DispatchBatchAction(new DispatchBatchService(
    new MySqlBatchDispatchRepository(Capsule::connection()),
    new HttpRouteOptimizerGateway(
        new StreamHttpTransport(),
        $_ENV['ROUTE_SERVICE_URL'] ?? $_ENV['ASSIGNMENT_SERVICE_URL'] ?? '',
        $_ENV['ROUTE_API_KEY'] ?? $_ENV['ASSIGNMENT_API_KEY'] ?? '',
        (float) ($_ENV['ROUTE_TIMEOUT_SECONDS'] ?? $_ENV['ASSIGNMENT_TIMEOUT_SECONDS'] ?? 3),
    ),
));
$registerBatchRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/BatchRoutes.php';
$registerBatchRoutes(
    $app,
    $listBatchesAction,
    $getBatchAction,
    $dispatchBatchAction,
    $jwtAuthMiddleware,
    new RoleMiddleware('OPERADOR', 'CONDUCTOR'),
    new RoleMiddleware('OPERADOR'),
    $mustChangePasswordMiddleware,
);

$registerDriverRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/DriverRoutes.php';
$registerDriverRoutes(
    $app,
    new StartRouteAction(new StartRouteService(new MySqlBatchRouteRepository(Capsule::connection()))),
    $jwtAuthMiddleware,
    new RoleMiddleware('CONDUCTOR'),
    $mustChangePasswordMiddleware,
);

$evidencePath = $_ENV['EVIDENCE_STORAGE_PATH'] ?? dirname(__DIR__) . '/var/evidence';
if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $evidencePath) !== 1) {
    $evidencePath = dirname(__DIR__) . '/' . $evidencePath;
}
$registerDeliveryRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/DeliveryRoutes.php';
$evidenceStorage = new LocalEvidenceStorage($evidencePath, __DIR__);
$registerDeliveryRoutes(
    $app,
    new FinalStatusAction(new FinalStatusService(
        new MySqlFinalStatusRepository(Capsule::connection()),
        $evidenceStorage,
    )),
    $jwtAuthMiddleware,
    new RoleMiddleware('CONDUCTOR'),
    $mustChangePasswordMiddleware,
    new GetEvidenceAction(new GetEvidenceService(
        new MySqlPackageEvidenceRepository(Capsule::connection()),
        $evidenceStorage,
    )),
    new RoleMiddleware('OPERADOR', 'ADMINISTRADOR'),
);

// Seguimiento publico: sin token y con limite de consultas por IP, contado en archivos (sin tabla).
$registerTrackingRoutes = require __DIR__ . '/../src/Infrastructure/Adapter/In/Http/Route/TrackingRoutes.php';
$registerTrackingRoutes(
    $app,
    new GetTrackingAction(new GetTrackingService($packageQueryRepository)),
    new RateLimitMiddleware(
        new FileRateLimiter(
            $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? sys_get_temp_dir() . '/riva-rate-limit',
            (int) ($_ENV['TRACKING_RATE_LIMIT_PER_MINUTE'] ?? 30),
        ),
        'tracking',
    ),
);

$routeFiles = [
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
