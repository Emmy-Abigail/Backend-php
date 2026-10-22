<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Assignment;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Application\Port\Out\Assignment\BatchAssignmentGateway;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use Throwable;

final readonly class HttpBatchAssignmentGateway implements BatchAssignmentGateway
{
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

        try {
            $response = $this->transport->post(
                rtrim($this->baseUrl, '/') . '/api/v1/batch-assignments/evaluate',
                $snapshot->toArray(),
                $this->apiKey,
                $this->timeout,
            );
            if ($response->status !== 200) {
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
}
