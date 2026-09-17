<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\TokenExpired;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthenticateTokenUseCase $authenticateTokenUseCase)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '' || !preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            return $this->json(['message' => 'Token no proporcionado'], 401);
        }

        $token = trim($matches[1]);
        if ($token === '') {
            return $this->json(['message' => 'Token no proporcionado'], 401);
        }

        try {
            $authUser = $this->authenticateTokenUseCase->execute($token);
        } catch (TokenExpired) {
            return $this->json(['message' => 'Token expirado'], 401);
        } catch (InvalidToken) {
            return $this->json(['message' => 'Token inválido'], 401);
        } catch (UserNotAllowed) {
            return $this->json(['message' => 'Usuario inactivo o inexistente'], 401);
        }

        return $handler->handle($request->withAttribute('authUser', $authUser));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 401): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
