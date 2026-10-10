<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\TokenService;

final readonly class AuthenticateTokenService implements AuthenticateTokenUseCase
{
    public function __construct(
        private TokenService $tokenService,
        private UserRepository $userRepository,
        private ?CatalogRepository $catalogRepository = null,
    ) {
    }

    public function execute(string $token): AuthenticatedUser
    {
        $claims = $this->tokenService->verify($token);

        $user = $this->userRepository->findById($claims->userId);

        if ($user === null || !$user->active) {
            throw new UserNotAllowed();
        }

        // Revocación: Si la contraseña fue cambiada DESPUÉS de que se emitió este token, invalidarlo
        if ($user->passwordChangedAt !== null && $claims->issuedAt !== null) {
            if ($claims->issuedAt < $user->passwordChangedAt->getTimestamp()) {
                throw new InvalidToken();
            }
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

        return new AuthenticatedUser(
            $user->id,
            $user->names,
            $user->email,
            $user->role,
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