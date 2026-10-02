<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Exception\UserNotFound;
use App\Application\Port\In\User\ChangeUserStatusCommand;
use App\Application\Port\In\User\ChangeUserStatusUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ChangeUserStatusAction
{
    public function __construct(private ChangeUserStatusUseCase $changeUserStatusUseCase)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = (int) ($args['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($response, ['message' => 'Id de usuario invÃ¡lido'], 422);
        }

        $body = $request->getParsedBody();

        if (!is_array($body) || !array_key_exists('activo', $body)) {
            return $this->json($response, ['message' => 'El campo activo es obligatorio'], 422);
        }

        if (!is_bool($body['activo'])) {
            return $this->json($response, ['message' => 'El campo activo debe ser booleano'], 422);
        }

        try {
            $this->changeUserStatusUseCase->execute(
                new ChangeUserStatusCommand($userId, $body['activo']),
            );
        } catch (UserNotFound) {
            return $this->json($response, ['message' => 'Usuario no encontrado'], 404);
        }

        return $this->json($response, ['id' => $userId, 'activo' => $body['activo']]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}