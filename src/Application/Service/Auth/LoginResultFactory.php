<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Port\In\Auth\LoginResult;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Security\IssuedToken;
use App\Domain\User\User;

/** Arma el resultado de inicio de sesion; lo comparten el login con contrasena y el login con Google. */
final readonly class LoginResultFactory
{
    public function __construct(private ?CatalogRepository $catalogRepository = null)
    {
    }

    public function build(User $user, IssuedToken $issuedToken): LoginResult
    {
        $sedeNombre = null;
        if ($user->role === 'OPERADOR' && $user->idSede !== null && $this->catalogRepository !== null) {
            foreach ($this->catalogRepository->getSedes() as $sede) {
                if ($sede->id === $user->idSede) {
                    $sedeNombre = $sede->nombre;
                    break;
                }
            }
        }

        $tipoVehiculoNombre = null;
        $pesoMaxTotalKg = null;
        $ladoMaxCm = null;
        if ($user->role === 'CONDUCTOR' && $user->idTipoVehiculo !== null && $this->catalogRepository !== null) {
            foreach ($this->catalogRepository->getVehicleTypes() as $tipoVehiculo) {
                if ($tipoVehiculo->id === $user->idTipoVehiculo) {
                    $tipoVehiculoNombre = $tipoVehiculo->nombre;
                    $pesoMaxTotalKg = $tipoVehiculo->pesoMaxTotalKg;
                    $ladoMaxCm = $tipoVehiculo->ladoMaxCm;
                    break;
                }
            }
        }

        return new LoginResult(
            $user->id,
            $user->names,
            $user->email,
            $user->role,
            $issuedToken->token,
            'Bearer',
            $issuedToken->expiresAt,
            $user->mustChangePassword,
            $user->idSede,
            $sedeNombre,
            $user->idTipoVehiculo,
            $tipoVehiculoNombre,
            $pesoMaxTotalKg,
            $ladoMaxCm,
            $user->placa,
        );
    }
}
