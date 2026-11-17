<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Domain\Catalog\Sede;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/v1/public/sedes — las sedes activas donde un cliente puede dejar su paquete (sin sesión). */
final class GetPublicSedesAction
{
    public function __construct(private GetSedesUseCase $getSedesUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $active = array_values(array_filter(
            $this->getSedesUseCase->execute(),
            static fn (Sede $sede): bool => $sede->activa,
        ));

        $payload = ['data' => array_map(static fn (Sede $sede): array => $sede->toArray(), $active)];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
