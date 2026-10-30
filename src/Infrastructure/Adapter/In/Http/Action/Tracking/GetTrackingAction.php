<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Tracking;

use App\Application\Exception\PackageNotFound;
use App\Application\Port\In\Tracking\GetTrackingUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetTrackingAction
{
    /** Mismo alfabeto que RandomTrackingGenerator: sin 0, 1, I ni O. */
    private const TRACKING_PATTERN = '/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{10}$/D';

    public function __construct(private GetTrackingUseCase $useCase)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trackingId = strtoupper(trim($args['trackingId'] ?? ''));
        if (preg_match(self::TRACKING_PATTERN, $trackingId) !== 1) {
            return $this->json($response, ['error' => 'codigo_invalido', 'message' => 'El codigo de seguimiento debe tener 10 caracteres'], 422);
        }

        try {
            $result = $this->useCase->execute($trackingId);
        } catch (PackageNotFound $exception) {
            return $this->json($response, ['error' => 'envio_no_encontrado', 'message' => $exception->getMessage()], 404);
        }

        return $this->json($response, $result->toArray(), 200);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
