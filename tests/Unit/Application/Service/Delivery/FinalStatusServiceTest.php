<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Delivery;

use App\Application\Exception\FinalStatusConflict;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Delivery\EvidencePhoto;
use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Application\Port\Out\Delivery\EvidenceStorage;
use App\Application\Port\Out\Delivery\FinalStatusRepository;
use App\Application\Service\Delivery\FinalStatusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DeliveryPhotoFixture;

require_once __DIR__ . '/../../../../Support/DeliveryPhotoFixture.php';

final class FinalStatusServiceTest extends TestCase
{
    public static function cleanupCases(): array
    {
        return [['rolledBack'], ['committed'], ['unknown'], ['deleteFailed']];
    }

    #[DataProvider('cleanupCases')]
    public function test_compensa_solo_una_evidencia_no_referenciada_sin_ocultar_el_error_original(string $outcome): void
    {
        $key = str_repeat('a', 64) . '.png';
        $repository = $this->createMock(FinalStatusRepository::class);
        $repository->expects($this->once())->method('assertCanFinalize');
        $original = new FinalStatusConflict('original');
        $repository->expects($this->once())->method('finalize')->with($this->anything(), $key)->willThrowException($original);
        $check = $repository->expects($this->once())->method('isEvidenceReferenced')->with($key);
        if ($outcome === 'unknown') {
            $check->willThrowException(new \RuntimeException('Connection lost'));
        } else {
            $check->willReturn($outcome === 'committed');
        }
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->once())->method('store')->willReturn($key);
        $deletion = $storage->expects(in_array($outcome, ['rolledBack', 'deleteFailed'], true) ? $this->once() : $this->never())->method('delete')->with($key);
        if ($outcome === 'deleteFailed') {
            $deletion->willThrowException(new \RuntimeException('Cannot delete'));
        }
        $command = new FinalStatusCommand(new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', 'CONDUCTOR'), 1, 'ENTREGADO', photo: new EvidencePhoto(DeliveryPhotoFixture::bytes()));
        try {
            (new FinalStatusService($repository, $storage))->execute($command);
            self::fail('No se propago el error original');
        } catch (FinalStatusConflict $exception) {
            self::assertSame($original, $exception);
        }
    }

    public function test_no_crea_archivos_si_la_propiedad_o_estado_cambiaron_antes_del_guardado(): void
    {
        $repository = $this->createMock(FinalStatusRepository::class);
        $repository->expects($this->once())->method('assertCanFinalize')->willThrowException(new FinalStatusConflict());
        $repository->expects($this->never())->method('finalize');
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->never())->method('store');
        $this->expectException(FinalStatusConflict::class);
        (new FinalStatusService($repository, $storage))->execute(new FinalStatusCommand(new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', 'CONDUCTOR'), 1, 'ENTREGADO', photo: new EvidencePhoto(DeliveryPhotoFixture::bytes())));
    }
}
