<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use App\Application\Port\In\Auth\AuthenticatedUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class MustChangePasswordMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var AuthenticatedUser|null $authUser */
        $authUser = $request->getAttribute('authUser');

        if ($authUser !== null && $authUser->mustChangePassword) {
            $path = $request->getUri()->getPath();
            $method = strtoupper($request->getMethod());

            $isMeEndpoint = ($method === 'GET' && (str_ends_with($path, '/auth/me') || $path === '/api/v1/auth/me'));
            $isChangePasswordEndpoint = ($method === 'PATCH' && (str_ends_with($path, '/auth/change-password') || $path === '/api/v1/auth/change-password'));

            if (!$isMeEndpoint && !$isChangePasswordEndpoint) {
                $response = new Response(403);
                $response->getBody()->write((string) json_encode([
                    'message' => 'Debes cambiar tu contraseña',
                    'error' => 'cambio_password_obligatorio',
                ], JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json');
            }
        }

        return $handler->handle($request);
    }
}