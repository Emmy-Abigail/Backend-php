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

            $retry = $this->retryInfo($query->packageId);
            // Si ya no se puede reasignar (ya tiene su reintento o llegó al máximo), no se ofrece corregir la dirección.
            $canStillRetry = $retry['reintentos_restantes'] === null || $retry['reintentos_restantes'] > 0;

            return new GetPackageResult($detail->data + [
                'historial' => $detail->history,
                'requiere_corregir_direccion' => $canStillRetry && AddressCorrectionPolicy::requiresForRetry(
                    $detail->data['estado']['codigo'] ?? null,
                    $last['estado_nuevo'] ?? null,
                    $last['motivo_fallo']['codigo'] ?? null,
                ),
            ] + $retry);
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
     * Así el front deshabilita "Reasignar" sin esperar el 409. Además indica si ya fue reasignado y con qué código.
     *
     * @return array{reintentos_restantes: ?int, ya_reasignado: bool, reintento_tracking_id: ?string}
     */
    private function retryInfo(int $packageId): array
    {
        $source = $this->retrySources?->findRetrySource($packageId);
        if ($source === null) {
            return ['reintentos_restantes' => null, 'ya_reasignado' => false, 'reintento_tracking_id' => null];
        }

        return [
            'reintentos_restantes' => RetryPolicy::remaining($source->lastStatus, $source->failureReason, $source->previousRetries, $source->alreadyRetried),
            // Distingue «este paquete ya tiene su reintento» de «el envío llegó al máximo»: ambos dan 0 reintentos restantes.
            'ya_reasignado' => $source->alreadyRetried,
            'reintento_tracking_id' => $source->retryTracking,
        ];
    }
}
