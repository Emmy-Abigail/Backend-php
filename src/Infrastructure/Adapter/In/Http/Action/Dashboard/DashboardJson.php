<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Dashboard;

use Psr\Http\Message\ResponseInterface;

/** Respuestas JSON compartidas por las acciones del dashboard. */
final class DashboardJson
{
    /** @param array<string, mixed> $payload */
    public static function write(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * 422 con el detalle por campo. El mensaje general es el que muestra el cliente.
     *
     * @param array<string, string>|null $errors
     */
    public static function invalidParams(ResponseInterface $response, DashboardQueryParams $params, ?array $errors = null): ResponseInterface
    {
        return self::write($response, [
            'error' => 'parametros_invalidos',
            'message' => $params->hasDateErrors() ? 'El rango de fechas no es válido' : 'Parámetros del dashboard inválidos',
            'campos' => $errors ?? $params->errors,
        ], 422);
    }
}
