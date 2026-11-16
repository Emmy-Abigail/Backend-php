<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\InvalidCaptcha;
use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginResult;
use App\Application\Port\In\Auth\LoginUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\CaptchaVerifier;
use App\Application\Port\Out\Security\TokenService;

final readonly class LoginService implements LoginUseCase
{
    private LoginResultFactory $resultFactory;

    /** Sin $captchaVerifier (RECAPTCHA_ENABLED=false) el login no exige captcha. */
    public function __construct(
        private UserRepository $userRepository,
        private TokenService $tokenService,
        ?CatalogRepository $catalogRepository = null,
        private ?CaptchaVerifier $captchaVerifier = null,
    ) {
        $this->resultFactory = new LoginResultFactory($catalogRepository);
    }

    public function execute(LoginCommand $command): LoginResult
    {
        // El captcha se valida antes que la contrasena: un bot no llega a probar claves.
        if ($this->captchaVerifier !== null) {
            $captchaToken = $command->captchaToken ?? '';
            if ($captchaToken === '' || !$this->captchaVerifier->verify($captchaToken)) {
                throw new InvalidCaptcha();
            }
        }

        $user = $this->userRepository->findByEmail($command->email);

        if ($user === null || !$user->active || !$user->passwordMatches($command->password)) {
            throw new InvalidCredentials();
        }

        return $this->resultFactory->build($user, $this->tokenService->issue($user));
    }
}
