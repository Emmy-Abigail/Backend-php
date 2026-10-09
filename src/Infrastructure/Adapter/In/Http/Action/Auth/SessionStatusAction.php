<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SessionStatusAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // La validación la hace JwtAuthMiddleware: si se llega aquí, la sesión sigue vigente.
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(204);
    }
}
