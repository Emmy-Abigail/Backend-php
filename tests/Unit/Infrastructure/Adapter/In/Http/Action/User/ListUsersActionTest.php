<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Port\In\User\ListUsersResult;
use App\Application\Port\In\User\ListUsersUseCase;
use App\Application\Port\In\User\UserListItem;
use App\Infrastructure\Adapter\In\Http\Action\User\ListUsersAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ListUsersActionTest extends TestCase
{
    #[Test]
    public function devuelve_los_campos_publicos_del_personal_registrado(): void
    {
        $useCase = $this->createStub(ListUsersUseCase::class);
        $useCase->method('execute')->willReturn(new ListUsersResult([
            new UserListItem(2, 'Conductor', 'conductor@email.com', '999888777', 'Conductor', true),
        ]));

        $response = (new ListUsersAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'data' => [[
                'id' => 2,
                'nombres' => 'Conductor',
                'correo' => 'conductor@email.com',
                'telefono' => '999888777',
                'rol' => 'Conductor',
                'activo' => true,
            ]],
        ], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function devuelve_data_vacia_cuando_no_hay_usuarios(): void
    {
        $useCase = $this->createStub(ListUsersUseCase::class);
        $useCase->method('execute')->willReturn(new ListUsersResult([]));

        $response = (new ListUsersAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(['data' => []], json_decode((string) $response->getBody(), true));
    }
}
