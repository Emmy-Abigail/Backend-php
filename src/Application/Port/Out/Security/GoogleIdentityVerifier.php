<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

interface GoogleIdentityVerifier
{
    /**
     * Valida el ID token de Google y devuelve el correo verificado, en minusculas.
     *
     * @throws \App\Application\Exception\InvalidGoogleToken si el token no es valido para esta aplicacion.
     * @throws \App\Application\Exception\GoogleIdentityUnavailable si no se pueden obtener las claves de Google.
     */
    public function verify(string $idToken): string;
}
