<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use App\Application\Port\In\Auth\AuthenticatedUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class RoleMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedRoles;

    public function __construct(string ...$allowedRoles)
    {
        $this->allowedRoles = array_values($allowedRoles);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authUser = $request->getAttribute('authUser');

        if (!$authUser instanceof AuthenticatedUser) {
            return $this->json(['message' => 'Token no proporcionado'], 401);
        }

        if (!in_array($authUser->role, $this->allowedRoles, true)) {
            return $this->json(['message' => 'No tiene permisos para esta operación'], 403);
        }

        return $handler->handle($request);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
