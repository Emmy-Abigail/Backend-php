<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Assignment;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Application\Port\Out\Assignment\BatchAssignmentGateway;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use Throwable;

final readonly class HttpBatchAssignmentGateway implements BatchAssignmentGateway
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private HttpTransport $transport,
        private string $baseUrl,
        private string $apiKey,
        private float $timeout = 3.0,
    ) {
    }

    public function evaluate(AssignmentSnapshot $snapshot): BatchAssignment
    {
        $parts = parse_url($this->baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || trim($this->apiKey) === '' || strpbrk($this->apiKey, "\r\n") !== false
            || !is_finite($this->timeout) || $this->timeout <= 0 || $this->timeout > 30) {
            throw new AssignmentUnavailable('Servicio de asignacion no disponible');
        }

        $response = $this->send($snapshot);

        try {
            if ($response === null || $response->status !== 200) {
                // Un 401 de Python indica un error de configuración entre servicios, no en la sesión del operador.
                throw new AssignmentUnavailable('Servicio de asignacion no disponible');
            }
            $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 3
                || !is_string($data['accion'] ?? null) || !is_int($data['id_tipo_vehiculo'] ?? null)
                || !array_key_exists('id_lote', $data)
                || ($data['id_lote'] !== null && !is_int($data['id_lote']))) {
                throw new AssignmentUnavailable('Respuesta de asignacion invalida');
            }
            $assignment = new BatchAssignment($data['accion'], $data['id_tipo_vehiculo'], $data['id_lote']);
            $snapshot->assertValid($assignment);

            return $assignment;
        } catch (Throwable) {
            // Nunca enviar a Angular los cuerpos originales del servicio externo, claves, URL ni detalles de excepciones.
            throw new AssignmentUnavailable('Servicio de asignacion no disponible');
        }
    }

    /**
     * Red, tiempo agotado o 5xx (por ejemplo, Python despertando en Render): se reintenta una vez.
     * Un 401 o un 422 no se reintentan: reintentar no los arregla.
     */
    private function send(AssignmentSnapshot $snapshot): ?HttpResponse
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/batch-assignments/evaluate';
        $response = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->transport->post($url, $snapshot->toArray(), $this->apiKey, $this->timeout);
            } catch (Throwable) {
                $response = null;
                continue;
            }
            if ($response->status < 500) {
                return $response;
            }
        }

        return $response;
    }
}
