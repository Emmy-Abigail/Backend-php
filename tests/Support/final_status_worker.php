<?php

declare(strict_types=1);

use App\Application\Exception\FinalStatusConflict;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Delivery\EvidencePhoto;
use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlFinalStatusRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\Support\DeliveryPhotoFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/DeliveryPhotoFixture.php';

try {
    $options = json_decode(getenv('FINAL_STATUS_WORKER_CONFIG') ?: '', true, 32, JSON_THROW_ON_ERROR);
    if (($options['database'] ?? null) !== 'riva_final_status_test') {
        throw new RuntimeException('Invalid worker database');
    }
    $capsule = new Capsule();
    $capsule->addConnection($options);
    $db = $capsule->getConnection();
    $db->getPdo();
    $db->statement('SET SESSION innodb_lock_wait_timeout = 5');
    $state = $argv[2];
    $command = new FinalStatusCommand(new AuthenticatedUser(31, 'Test driver', 'driver@example.invalid', 'CONDUCTOR'), (int) $argv[1], $state,
        $state === 'FALLIDO' ? 1 : null, 'Test final status', $state === 'ENTREGADO' ? new EvidencePhoto(DeliveryPhotoFixture::bytes()) : null);
    echo "READY\n";
    flush();
    // Aqui se prueba la serializacion de BD; el almacenamiento se prueba en FinalStatusFlowTest.
    $result = (new MySqlFinalStatusRepository($db))->finalize($command, $state === 'ENTREGADO' ? bin2hex(random_bytes(32)) . '.png' : null);
    echo json_encode(['status' => 200, 'id' => $result->packageId, 'closed' => $result->batchClosed]) . "\n";
} catch (FinalStatusConflict) {
    echo json_encode(['status' => 409]) . "\n";
} catch (Throwable $exception) {
    echo json_encode(['status' => 500, 'type' => $exception::class]) . "\n";
    exit(1);
}
