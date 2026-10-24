<?php

declare(strict_types=1);

namespace App\Application\Service\Delivery;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Application\Port\In\Delivery\FinalStatusResult;
use App\Application\Port\In\Delivery\FinalStatusUseCase;
use App\Application\Port\Out\Delivery\EvidenceStorage;
use App\Application\Port\Out\Delivery\FinalStatusRepository;
use Throwable;

final readonly class FinalStatusService implements FinalStatusUseCase
{
    public function __construct(private FinalStatusRepository $repository, private EvidenceStorage $storage)
    {
    }

    public function execute(FinalStatusCommand $command): FinalStatusResult
    {
        if ($command->driver->role !== 'CONDUCTOR' || $command->driver->mustChangePassword) {
            throw new UserNotAllowed('Solo un conductor habilitado puede finalizar entregas');
        }
        $this->repository->assertCanFinalize($command);
        $key = $command->photo === null ? null : $this->storage->store($command->photo);
        try {
            return $this->repository->finalize($command, $key);
        } catch (Throwable $exception) {
            if ($key !== null) {
                try {
                    // Si el servidor perdio la respuesta del COMMIT, conservar la foto si
                    // el historial se confirmo. Si no hay conexion, conservar por prudencia.
                    if (!$this->repository->isEvidenceReferenced($key)) {
                        $this->storage->delete($key);
                    }
                } catch (Throwable) {
                    error_log('No se pudo comprobar o limpiar una evidencia de entrega pendiente');
                }
            }
            throw $exception;
        }
    }
}
