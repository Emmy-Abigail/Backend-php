<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginResult;
use App\Application\Port\In\Auth\LoginUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\TokenService;

final readonly class LoginService implements LoginUseCase
{
    public function __construct(
        private UserRepository $userRepository,
        private TokenService $tokenService,
        private ?CatalogRepository $catalogRepository = null,
    ) {
    }

    public function execute(LoginCommand $command): LoginResult
    {
        $user = $this->userRepository->findByEmail($command->email);

        if ($user === null || !$user->active || !$user->passwordMatches($command->password)) {
            throw new InvalidCredentials();
        }

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

        $issuedToken = $this->tokenService->issue($user);

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
