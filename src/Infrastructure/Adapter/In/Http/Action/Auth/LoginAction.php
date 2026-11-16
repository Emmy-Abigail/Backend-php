<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\CaptchaUnavailable;
use App\Application\Exception\InvalidCaptcha;
use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class LoginAction
{
    private const MAX_CAPTCHA_TOKEN_LENGTH = 8192;

    public function __construct(private LoginUseCase $loginUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return LoginResponse::json($response, [
                'message' => 'El cuerpo debe ser un objeto JSON válido',
                'error' => 'cuerpo_invalido',
            ], 400);
        }

        $email = $body['correo'] ?? null;
        $password = $body['password'] ?? null;

        if (!is_string($email) || !is_string($password)) {
            return $this->invalidCredentialsResponse($response);
        }

        $email = strtolower(trim($email));

        if ($email === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->invalidCredentialsResponse($response);
        }

        if ($password === '' || strlen($password) > 72) {
            return $this->invalidCredentialsResponse($response);
        }

        // Opcional en el cuerpo; el servicio lo exige solo si RECAPTCHA_ENABLED=true.
        $captchaToken = $body['recaptcha_token'] ?? null;
        if (!is_string($captchaToken) || strlen($captchaToken) > self::MAX_CAPTCHA_TOKEN_LENGTH) {
            $captchaToken = null;
        }

        try {
            $result = $this->loginUseCase->execute(new LoginCommand($email, $password, $captchaToken));
        } catch (InvalidCaptcha) {
            return LoginResponse::json($response, [
                'message' => 'La verificación reCAPTCHA no es válida. Vuelve a marcar la casilla.',
                'error' => 'captcha_invalido',
            ], 422);
        } catch (CaptchaUnavailable) {
            return LoginResponse::json($response, [
                'message' => 'No se pudo verificar el reCAPTCHA. Intenta de nuevo en unos segundos.',
                'error' => 'captcha_no_disponible',
            ], 503);
        } catch (InvalidCredentials) {
            return $this->invalidCredentialsResponse($response);
        }

        return LoginResponse::json($response, LoginResponse::body($result));
    }

    private function invalidCredentialsResponse(ResponseInterface $response): ResponseInterface
    {
        return LoginResponse::json($response, [
            'message' => 'Credenciales incorrectas',
            'error' => 'credenciales_incorrectas',
        ], 401);
    }
}
