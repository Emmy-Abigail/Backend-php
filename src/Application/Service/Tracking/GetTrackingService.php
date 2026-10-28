<?php

declare(strict_types=1);

namespace App\Application\Service\Tracking;

use App\Application\Exception\PackageNotFound;
use App\Application\Port\In\Tracking\GetTrackingResult;
use App\Application\Port\In\Tracking\GetTrackingUseCase;
use App\Application\Port\Out\Persistence\PackageQueryRepository;

final readonly class GetTrackingService implements GetTrackingUseCase
{
    public function __construct(private PackageQueryRepository $repository)
    {
    }

    public function execute(string $trackingId): GetTrackingResult
    {
        $tracking = $this->repository->findPublicTracking($trackingId);
        if ($tracking === null) {
            throw new PackageNotFound('No encontramos un envio con ese codigo');
        }

        return new GetTrackingResult($tracking);
    }
}
