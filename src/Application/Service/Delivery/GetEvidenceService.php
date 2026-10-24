<?php

declare(strict_types=1);

namespace App\Application\Service\Delivery;

use App\Application\Exception\EvidenceNotFound;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Delivery\GetEvidenceQuery;
use App\Application\Port\In\Delivery\GetEvidenceUseCase;
use App\Application\Port\Out\Delivery\EvidenceStorage;
use App\Application\Port\Out\Delivery\PackageEvidenceRepository;
use App\Application\Port\Out\Delivery\StoredEvidence;

final readonly class GetEvidenceService implements GetEvidenceUseCase
{
    public function __construct(private PackageEvidenceRepository $repository, private EvidenceStorage $storage)
    {
    }

    public function execute(GetEvidenceQuery $query): StoredEvidence
    {
        $viewer = $query->viewer;
        if ($viewer->mustChangePassword || !in_array($viewer->role, ['OPERADOR', 'ADMINISTRADOR'], true)
            || ($viewer->role === 'OPERADOR' && $viewer->idSede === null)) {
            throw new UserNotAllowed('No tiene permisos para consultar evidencias');
        }
        $reference = $this->repository->find($query->packageId);
        if ($reference === null) {
            throw new PackageNotFound('El paquete no existe');
        }
        // Comprobar sede antes del estado o archivo: una sede ajena siempre es 403.
        if ($viewer->role === 'OPERADOR' && $reference->sedeId !== $viewer->idSede) {
            throw new PackageAccessDenied('No puede consultar evidencias de otra sede');
        }
        if ($reference->status !== 'ENTREGADO' || $reference->key === null || $reference->key === '') {
            throw new EvidenceNotFound();
        }
        return $this->storage->read($reference->key);
    }
}
