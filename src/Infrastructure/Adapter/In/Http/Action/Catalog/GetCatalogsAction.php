<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetFailureReasonsUseCase;
use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Application\Port\In\Catalog\GetVehicleTypesUseCase;
use App\Domain\Catalog\Department;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\VehicleType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GetCatalogsAction
{
    public function __construct(
        private GetGeographyUseCase $getGeographyUseCase,
        private GetVehicleTypesUseCase $getVehicleTypesUseCase,
        private GetFailureReasonsUseCase $getFailureReasonsUseCase,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = [
            'data' => [
                'distritos' => $this->districts($this->getGeographyUseCase->execute()),
                'vehiculos' => array_map(
                    static fn (VehicleType $t): array => $t->toArray(),
                    $this->getVehicleTypesUseCase->execute(),
                ),
                'motivos_fallo' => array_map(
                    static fn (FailureReason $r): array => $r->toArray(),
                    $this->getFailureReasonsUseCase->execute(),
                ),
            ],
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Aplana departamento > provincia > distrito en una sola lista de distritos.
     *
     * @param Department[] $departments
     * @return array<int, array<string, mixed>>
     */
    private function districts(array $departments): array
    {
        $districts = [];

        foreach ($departments as $department) {
            foreach ($department->toArray()['provincias'] as $province) {
                foreach ($province['distritos'] as $district) {
                    $districts[] = $district;
                }
            }
        }

        return $districts;
    }
}
