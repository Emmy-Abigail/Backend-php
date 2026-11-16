<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

interface CaptchaVerifier
{
    /**
     * Devuelve true si el token del captcha es valido.
     *
     * @throws \App\Application\Exception\CaptchaUnavailable si el servicio de verificacion no responde.
     */
    public function verify(string $token): bool;
}
