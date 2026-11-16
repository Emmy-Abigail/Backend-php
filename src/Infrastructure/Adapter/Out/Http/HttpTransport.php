<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Http;

interface HttpTransport
{
    /** @param array<string, mixed> $payload */
    public function post(string $url, array $payload, string $apiKey, float $timeout): HttpResponse;

    /** GET sin credenciales, para servicios públicos (geocodificación). */
    public function get(string $url, float $timeout): HttpResponse;

    /**
     * POST como formulario (application/x-www-form-urlencoded), sin API key; lo exige reCAPTCHA.
     *
     * @param array<string, string> $fields
     */
    public function postForm(string $url, array $fields, float $timeout): HttpResponse;
}
