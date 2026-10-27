<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Route;

use App\Application\Exception\RouteUnavailable;
use App\Application\Port\Out\Route\RouteOptimizerGateway;
use App\Application\Port\Out\Route\RouteStop;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use Throwable;

final readonly class HttpRouteOptimizerGateway implements RouteOptimizerGateway
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private HttpTransport $transport,
        private string $baseUrl,
        private string $apiKey,
        private float $timeout = 3.0,
    ) {
    }

    public function order(int $sedeId, array $stops): array
    {
        if (!$this->isConfigured()) {
            throw new RouteUnavailable('Servicio de rutas no disponible');
        }

        $payload = [
            'id_sede' => $sedeId,
            'paradas' => array_map(
                static fn (RouteStop $stop): array => ['id_paquete' => $stop->packageId, 'ubigeo_destino' => $stop->ubigeo]
                    // Solo se envían cuando existen, para no cambiar el JSON de los paquetes sin mapa.
                    + ($stop->lat === null || $stop->lng === null ? [] : ['lat' => $stop->lat, 'lng' => $stop->lng]),
                $stops,
            ),
        ];
        $url = rtrim($this->baseUrl, '/') . '/api/v1/optimize-route';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->transport->post($url, $payload, $this->apiKey, $this->timeout);
            } catch (Throwable) {
                // Red o tiempo de espera agotado: se reintenta una vez.
                continue;
            }

            if ($response->status >= 500) {
                continue;
            }
            if ($response->status !== 200) {
                // Un 401 o un 422 indican un problema de configuración o de datos, no una caída:
                // reintentar no lo arregla.
                break;
            }

            return $this->parse($response->body, $stops);
        }

        throw new RouteUnavailable('Servicio de rutas no disponible');
    }

    /**
     * @param list<RouteStop> $stops
     * @return list<int>
     */
    private function parse(string $body, array $stops): array
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RouteUnavailable('Respuesta de ruta invalida');
        }

        $order = is_array($data) ? ($data['orden'] ?? null) : null;
        if (!is_array($order) || !array_is_list($order)) {
            throw new RouteUnavailable('Respuesta de ruta invalida');
        }
        foreach ($order as $packageId) {
            if (!is_int($packageId)) {
                throw new RouteUnavailable('Respuesta de ruta invalida');
            }
        }

        // La respuesta debe ser una permutación exacta de los paquetes enviados.
        $expected = array_map(static fn (RouteStop $stop): int => $stop->packageId, $stops);
        $received = $order;
        sort($expected);
        sort($received);
        if ($received !== $expected) {
            throw new RouteUnavailable('Respuesta de ruta invalida');
        }

        return $order;
    }

    private function isConfigured(): bool
    {
        $parts = parse_url($this->baseUrl);

        return $parts !== false
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && trim($this->apiKey) !== ''
            && strpbrk($this->apiKey, "\r\n") === false
            && is_finite($this->timeout)
            && $this->timeout > 0
            && $this->timeout <= 30;
    }
}
