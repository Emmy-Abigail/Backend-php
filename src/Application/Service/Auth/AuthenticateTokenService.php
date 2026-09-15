<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\TokenService;

final readonly class AuthenticateTokenService implements AuthenticateTokenUseCase
{
    public function __construct(
        private TokenService $tokenService,
        private UserRepository $userRepository,
    ) {
    }

    public function execute(string $token): AuthenticatedUser
    {
        $claims = $this->tokenService->verify($token);

        $user = $this->userRepository->findById($claims->userId);

        if ($user === null || !$user->active) {
            throw new UserNotAllowed();
        }

        return new AuthenticatedUser(
            $user->id,
            $user->names,
            $user->email,
            $user->role,
        );
    }
}
