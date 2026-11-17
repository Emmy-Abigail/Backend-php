<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Pricing;

use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/public/quotes — cotización para el cliente, sin sesión.
 * No hay token, así que la sede de origen (donde dejará el paquete) llega en el cuerpo como id_sede.
 * Todo lo demás (validación, fórmula y respuesta) es lo mismo que en la cotización interna.
 */
final readonly class PublicQuoteAction
{
    private QuoteHttpHandler $handler;

    public function __construct(
        QuoteShipmentUseCase $quoteShipmentUseCase,
        private GetSedesUseCase $getSedesUseCase,
    ) {
        $this->handler = new QuoteHttpHandler($quoteShipmentUseCase);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        // Si el cuerpo no es un objeto JSON, el handler responde 400 cuerpo_invalido.
        if (!is_array($body)) {
            return $this->handler->handle(0, $body, $response);
        }

        $raw = $body['id_sede'] ?? null;

        if ($raw === null) {
            return $this->handler->json($response, ['message' => 'El campo id_sede es obligatorio', 'error' => 'campo_obligatorio'], 422);
        }

        $sedeId = $this->sedeId($raw);

        if ($sedeId === null) {
            return $this->handler->json($response, ['message' => 'El campo id_sede debe ser un número entero positivo', 'error' => 'campo_invalido'], 422);
        }

        if (!$this->isActiveSede($sedeId)) {
            return $this->handler->json($response, ['message' => 'La sede de origen no existe o no está activa', 'error' => 'referencia_invalida'], 422);
        }

        return $this->handler->handle($sedeId, $body, $response);
    }

    private function sedeId(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,9}$/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }

    private function isActiveSede(int $sedeId): bool
    {
        foreach ($this->getSedesUseCase->execute() as $sede) {
            if ($sede->id === $sedeId) {
                return $sede->activa;
            }
        }

        return false;
    }
}
