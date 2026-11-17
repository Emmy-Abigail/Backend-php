<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Pricing;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/v1/quotes — cotización interna: la sede de origen es la del operador (JWT). */
final readonly class QuoteAction
{
    private QuoteHttpHandler $handler;

    public function __construct(QuoteShipmentUseCase $quoteShipmentUseCase)
    {
        $this->handler = new QuoteHttpHandler($quoteShipmentUseCase);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authUser = $request->getAttribute('authUser');

        if (!$authUser instanceof AuthenticatedUser || $authUser->idSede === null) {
            return $this->handler->json($response, ['message' => 'El usuario no tiene una sede asignada', 'error' => 'sede_requerida'], 403);
        }

        return $this->handler->handle($authUser->idSede, $request->getParsedBody(), $response);
    }
}
