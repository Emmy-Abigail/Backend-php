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

final readonly class GetPackageService implements GetPackageUseCase
{
    /** Estados del lote en los que el conductor trabaja con sus paquetes. */
    private const DRIVER_BATCH_STATUSES = ['ASIGNADO', 'EN_RUTA'];

    public function __construct(private PackageQueryRepository $repository)
    {
    }

    public function execute(GetPackageQuery $query): GetPackageResult
    {
        $viewer = $query->viewer;
        $isOperator = $viewer->role === 'OPERADOR' && $viewer->idSede !== null;
        if (!$isOperator && $viewer->role !== 'CONDUCTOR') {
            throw new UserNotAllowed('Solo un operador con sede o un conductor puede consultar paquetes');
        }

        $detail = $this->repository->findDetail($query->packageId);
        if ($detail === null) {
            throw new PackageNotFound('El paquete no existe');
        }

        if ($isOperator) {
            if ($detail->sedeId !== $viewer->idSede) {
                throw new PackageAccessDenied('No puede ver paquetes de otra sede');
            }

            return new GetPackageResult($detail->data + ['historial' => $detail->history]);
        }

        if ($detail->driverId !== $viewer->id || !in_array($detail->batchStatus, self::DRIVER_BATCH_STATUSES, true)) {
            throw new PackageAccessDenied('No puede ver paquetes que no estan en un lote asignado a usted');
        }

        // El conductor necesita dirección y celular para entregar, pero no los datos del remitente ni el costo.
        $data = $detail->data;
        unset($data['remitente'], $data['costo_estimado']);

        return new GetPackageResult($data + ['historial' => $detail->history]);
    }
}
