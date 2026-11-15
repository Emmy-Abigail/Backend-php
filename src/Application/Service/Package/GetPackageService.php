<?php

declare(strict_types=1);

namespace App\Application\Service\Package;

use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Package\GetPackageQuery;
use App\Application\Port\In\Package\GetPackageResult;
use App\Application\Port\In\Package\GetPackageUseCase;
use App\Application\Port\Out\Persistence\PackageQueryRepository;
use App\Application\Port\Out\Persistence\PackageRetrySourceRepository;
use App\Domain\Package\AddressCorrectionPolicy;
use App\Domain\Package\RetryPolicy;

final readonly class GetPackageService implements GetPackageUseCase
{
    /** Estados del lote en los que el conductor trabaja con sus paquetes. */
    private const DRIVER_BATCH_STATUSES = ['ASIGNADO', 'EN_RUTA'];

    public function __construct(
        private PackageQueryRepository $repository,
        private ?PackageRetrySourceRepository $retrySources = null,
    ) {
    }

    public function execute(GetPackageQuery $query): GetPackageResult
    {
        $viewer = $query->viewer;
        $isOperator = $viewer->role === 'OPERADOR' && $viewer->idSede !== null;
        // El administrador supervisa todas las sedes (por ejemplo, desde los fallidos del dashboard).
        // Solo consulta: la reasignación sigue siendo del operador de la sede.
        $isAdmin = $viewer->role === 'ADMINISTRADOR';
        if (!$isOperator && !$isAdmin && $viewer->role !== 'CONDUCTOR') {
            throw new UserNotAllowed('Solo un operador con sede, un administrador o un conductor puede consultar paquetes');
        }

        $detail = $this->repository->findDetail($query->packageId);
        if ($detail === null) {
            throw new PackageNotFound('El paquete no existe');
        }

        if ($isOperator || $isAdmin) {
            if ($isOperator && $detail->sedeId !== $viewer->idSede) {
                throw new PackageAccessDenied('No puede ver paquetes de otra sede');
            }

            $last = $detail->history === [] ? [] : $detail->history[array_key_last($detail->history)];

            return new GetPackageResult($detail->data + [
                'historial' => $detail->history,
                'requiere_corregir_direccion' => AddressCorrectionPolicy::requiresForRetry(
                    $detail->data['estado']['codigo'] ?? null,
                    $last['estado_nuevo'] ?? null,
                    $last['motivo_fallo']['codigo'] ?? null,
                ),
                'reintentos_restantes' => $this->remainingRetries($query->packageId),
            ]);
        }

        if ($detail->driverId !== $viewer->id || !in_array($detail->batchStatus, self::DRIVER_BATCH_STATUSES, true)) {
            throw new PackageAccessDenied('No puede ver paquetes que no estan en un lote asignado a usted');
        }

        // El conductor necesita dirección y celular para entregar, pero no los datos del remitente ni el costo.
        $data = $detail->data;
        unset($data['remitente'], $data['costo_estimado']);

        return new GetPackageResult($data + ['historial' => $detail->history, 'requiere_corregir_direccion' => false]);
    }

    /**
     * DEMO 4: MAX_RETRIES - reintentos previos del envío; 0 si fue RECHAZADO o si el paquete ya tiene su reintento.
     * Así el front deshabilita "Reasignar" sin esperar el 409.
     */
    private function remainingRetries(int $packageId): ?int
    {
        $source = $this->retrySources?->findRetrySource($packageId);
        if ($source === null) {
            return null;
        }

        return RetryPolicy::remaining($source->lastStatus, $source->failureReason, $source->previousRetries, $source->alreadyRetried);
    }
}
