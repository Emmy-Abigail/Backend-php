<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Geocoding;

use Psr\Http\Message\ResponseInterface;

final class GeocodingJson
{
    /** @param array<string, mixed> $payload */
    public static function write(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public static function unavailable(ResponseInterface $response): ResponseInterface
    {
        return self::write($response, 503, [
            'error' => 'direcciones_no_disponible',
            'message' => 'El buscador de direcciones no responde; complete los campos a mano',
        ]);
    }
}
