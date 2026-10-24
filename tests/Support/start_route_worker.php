<?php

declare(strict_types=1);

use App\Application\Exception\RouteStartConflict;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\StartRouteCommand;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlBatchRouteRepository;
use Illuminate\Database\Capsule\Manager as Capsule;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    // Credenciales heredadas por entorno, nunca por argumentos ni por stdout.
    $options = json_decode(getenv('START_ROUTE_WORKER_CONFIG') ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (($options['database'] ?? null) !== 'riva_start_route_test') {
        throw new RuntimeException('Invalid worker database');
    }
    $capsule = new Capsule();
    $capsule->addConnection($options);
    $db = $capsule->getConnection();
    $db->getPdo();
    $db->statement('SET SESSION innodb_lock_wait_timeout = 3');
    echo "READY\n";
    flush();
    $result = (new MySqlBatchRouteRepository($db))->start(new StartRouteCommand(
        new AuthenticatedUser(31, 'Test driver', 'driver@example.invalid', 'CONDUCTOR'), (int) $argv[1],
    ));
    echo json_encode(['status' => 200, 'id' => $result->batchId]) . "\n";
} catch (RouteStartConflict $exception) {
    echo json_encode(['status' => 409, 'error' => $exception->error]) . "\n";
} catch (Throwable $exception) {
    // No imprimir mensajes PDO que puedan contener datos de conexión.
    echo json_encode(['status' => 500, 'type' => $exception::class]) . "\n";
    exit(1);
}
