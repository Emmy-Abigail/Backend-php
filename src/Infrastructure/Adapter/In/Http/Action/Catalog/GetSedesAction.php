<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Domain\Catalog\Sede;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GetSedesAction
{
    public function __construct(
        private GetSedesUseCase $getSedesUseCase,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sedes = $this->getSedesUseCase->execute();

        $payload = [
            'data' => array_map(static fn (Sede $s): array => $s->toArray(), $sedes),
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}