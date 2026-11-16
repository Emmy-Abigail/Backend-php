<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\GoogleAccountNotRegistered;
use App\Application\Port\In\Auth\GoogleLoginCommand;
use App\Application\Port\In\Auth\GoogleLoginUseCase;
use App\Application\Port\In\Auth\LoginResult;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\GoogleIdentityVerifier;
use App\Application\Port\Out\Security\TokenService;

/**
 * Inicio de sesion con Google. Solo entran usuarios que el administrador ya registro con ese correo;
 * no se crean cuentas nuevas. La respuesta es la misma que la del login con contrasena.
 */
final readonly class GoogleLoginService implements GoogleLoginUseCase
{
    private LoginResultFactory $resultFactory;

    public function __construct(
        private GoogleIdentityVerifier $identityVerifier,
        private UserRepository $userRepository,
        private TokenService $tokenService,
        ?CatalogRepository $catalogRepository = null,
    ) {
        $this->resultFactory = new LoginResultFactory($catalogRepository);
    }

    public function execute(GoogleLoginCommand $command): LoginResult
    {
        $email = $this->identityVerifier->verify($command->idToken);

        $user = $this->userRepository->findByEmail($email);
        if ($user === null || !$user->active) {
            throw new GoogleAccountNotRegistered();
        }

        return $this->resultFactory->build($user, $this->tokenService->issue($user));
    }
}
