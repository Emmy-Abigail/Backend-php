<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Http;

interface HttpTransport
{
    /** @param array<string, mixed> $payload */
    public function post(string $url, array $payload, string $apiKey, float $timeout): HttpResponse;

    /** GET sin credenciales, para servicios públicos (geocodificación). */
    public function get(string $url, float $timeout): HttpResponse;
}
