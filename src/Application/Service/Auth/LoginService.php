<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginResult;
use App\Application\Port\In\Auth\LoginUseCase;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\TokenService;

final readonly class LoginService implements LoginUseCase
{
    public function __construct(
        private UserRepository $userRepository,
        private TokenService $tokenService,
    ) {
    }

    public function execute(LoginCommand $command): LoginResult
    {
        $user = $this->userRepository->findByEmail($command->email);

        if ($user === null || !$user->active || !$user->passwordMatches($command->password)) {
            throw new InvalidCredentials();
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
        );
    }
}
