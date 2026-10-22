<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Http;

use RuntimeException;

/** Usa el soporte HTTP de flujos de PHP; no requiere extensiones ni dependencias adicionales. */
final readonly class StreamHttpTransport implements HttpTransport
{
    public function post(string $url, array $payload, string $apiKey, float $timeout): HttpResponse
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-API-Key: {$apiKey}\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ]]);

        set_error_handler(static function (): never {
            // No incluir direcciones URL, cabeceras ni credenciales en las excepciones.
            throw new RuntimeException('No se pudo contactar al servicio de asignacion');
        });
        try {
            $body = file_get_contents($url, false, $context, 0, 65537);
        } finally {
            restore_error_handler();
        }

        if ($body === false || strlen($body) > 65536
            || !preg_match('/^HTTP\/\S+\s+([0-9]{3})\b/', $http_response_header[0] ?? '', $status)) {
            throw new RuntimeException('Respuesta HTTP de asignacion invalida');
        }

        return new HttpResponse((int) $status[1], $body);
    }
}
