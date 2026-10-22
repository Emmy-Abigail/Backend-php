<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Http;

interface HttpTransport
{
    /** @param array<string, mixed> $payload */
    public function post(string $url, array $payload, string $apiKey, float $timeout): HttpResponse;
}
