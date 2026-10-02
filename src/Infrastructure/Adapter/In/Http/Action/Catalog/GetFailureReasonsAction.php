<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetFailureReasonsUseCase;
use App\Domain\Catalog\FailureReason;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GetFailureReasonsAction
{
    public function __construct(
        private GetFailureReasonsUseCase $getFailureReasonsUseCase,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $reasons = $this->getFailureReasonsUseCase->execute();

        $payload = [
            'data' => array_map(static fn (FailureReason $r): array => $r->toArray(), $reasons),
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}