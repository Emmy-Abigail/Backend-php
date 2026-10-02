<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Domain\Catalog\Department;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GetGeographyAction
{
    public function __construct(
        private GetGeographyUseCase $getGeographyUseCase,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $departments = $this->getGeographyUseCase->execute();

        $payload = [
            'data' => array_map(static fn (Department $d): array => $d->toArray(), $departments),
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}