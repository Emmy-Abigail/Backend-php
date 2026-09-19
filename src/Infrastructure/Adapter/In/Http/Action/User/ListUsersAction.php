<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Port\In\User\ListUsersResult;
use App\Application\Port\In\User\ListUsersUseCase;
use App\Application\Port\In\User\UserListItem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListUsersAction
{
    public function __construct(private ListUsersUseCase $listUsersUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->listUsersUseCase->execute();

        return $this->json($response, $result);
    }

    private function json(ResponseInterface $response, ListUsersResult $result): ResponseInterface
    {
        $response->getBody()->write(json_encode([
            'data' => array_map(
                static fn (UserListItem $user): array => [
                    'id' => $user->id,
                    'nombres' => $user->names,
                    'correo' => $user->email,
                    'telefono' => $user->phone,
                    'rol' => $user->role,
                    'activo' => $user->active,
                ],
                $result->users,
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
