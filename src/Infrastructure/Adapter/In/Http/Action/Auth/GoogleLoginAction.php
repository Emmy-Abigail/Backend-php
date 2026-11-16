<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\GoogleAccountNotRegistered;
use App\Application\Exception\GoogleIdentityUnavailable;
use App\Application\Exception\InvalidGoogleToken;
use App\Application\Port\In\Auth\GoogleLoginCommand;
use App\Application\Port\In\Auth\GoogleLoginUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/v1/auth/google: recibe el id_token que Google entrego al front. */
final readonly class GoogleLoginAction
{
    private const MAX_ID_TOKEN_LENGTH = 8192;

    public function __construct(private GoogleLoginUseCase $googleLoginUseCase)
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

        $idToken = $body['id_token'] ?? null;
        if (!is_string($idToken) || trim($idToken) === '' || strlen($idToken) > self::MAX_ID_TOKEN_LENGTH) {
            return $this->invalidTokenResponse($response);
        }

        try {
            $result = $this->googleLoginUseCase->execute(new GoogleLoginCommand(trim($idToken)));
        } catch (InvalidGoogleToken) {
            return $this->invalidTokenResponse($response);
        } catch (GoogleAccountNotRegistered) {
            return LoginResponse::json($response, [
                'message' => 'Esta cuenta de Google no está registrada en RIVA. Pide al administrador que te registre con este correo.',
                'error' => 'cuenta_no_registrada',
            ], 401);
        } catch (GoogleIdentityUnavailable) {
            return LoginResponse::json($response, [
                'message' => 'No se pudo verificar la cuenta de Google. Intenta de nuevo en unos segundos.',
                'error' => 'google_no_disponible',
            ], 503);
        }

        return LoginResponse::json($response, LoginResponse::body($result));
    }

    private function invalidTokenResponse(ResponseInterface $response): ResponseInterface
    {
        return LoginResponse::json($response, [
            'message' => 'El inicio de sesión con Google no es válido o expiró. Intenta de nuevo.',
            'error' => 'google_token_invalido',
        ], 401);
    }
}
