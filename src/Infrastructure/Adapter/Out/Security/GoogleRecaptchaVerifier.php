<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Security;

use App\Application\Exception\CaptchaUnavailable;
use App\Application\Port\Out\Security\CaptchaVerifier;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use JsonException;
use Throwable;

/** Valida el token de reCAPTCHA v2 con el endpoint siteverify de Google. */
final readonly class GoogleRecaptchaVerifier implements CaptchaVerifier
{
    public const SITEVERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private HttpTransport $transport,
        private string $secretKey,
        private float $timeout = 5.0,
        private string $url = self::SITEVERIFY_URL,
        private ?string $expectedHostname = null,
    ) {
    }

    public function verify(string $token): bool
    {
        try {
            $response = $this->transport->postForm(
                $this->url,
                ['secret' => $this->secretKey, 'response' => $token],
                $this->timeout,
            );
        } catch (Throwable) {
            // No se propaga el detalle: la peticion incluye la clave secreta.
            throw new CaptchaUnavailable('No se pudo verificar el captcha');
        }

        if ($response->status !== 200) {
            throw new CaptchaUnavailable('No se pudo verificar el captcha');
        }

        try {
            $data = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CaptchaUnavailable('No se pudo verificar el captcha');
        }

        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            return false;
        }

        if ($this->expectedHostname === null) {
            return true;
        }

        // Un token resuelto en otro dominio no sirve aunque Google lo haya dado por bueno.
        $hostname = $data['hostname'] ?? null;

        return is_string($hostname) && strcasecmp($hostname, $this->expectedHostname) === 0;
    }
}
