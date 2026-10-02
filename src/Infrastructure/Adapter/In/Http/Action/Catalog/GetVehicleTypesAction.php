<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetVehicleTypesUseCase;
use App\Domain\Catalog\VehicleType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GetVehicleTypesAction
{
    public function __construct(
        private GetVehicleTypesUseCase $getVehicleTypesUseCase,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $types = $this->getVehicleTypesUseCase->execute();

        $payload = [
            'data' => array_map(static fn (VehicleType $t): array => $t->toArray(), $types),
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}