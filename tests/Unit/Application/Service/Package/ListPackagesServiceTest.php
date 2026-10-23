<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Package;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\ListPackagesQuery;
use App\Application\Port\In\Package\ListPackagesResult;
use App\Application\Port\Out\Persistence\PackageQueryRepository;
use App\Application\Service\Package\ListPackagesService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListPackagesServiceTest extends TestCase
{
    public function test_busca_siempre_en_la_sede_del_operador(): void
    {
        $expected = new ListPackagesResult([], 0, 3, 10);
        $repository = $this->createMock(PackageQueryRepository::class);
        $repository->expects($this->once())->method('searchBySede')
            ->with(4, 'ana', 'EN_RUTA', '2026-10-01', '2026-10-02', 3, 10)
            ->willReturn($expected);

        $operator = new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', 'OPERADOR', false, 4);
        $result = (new ListPackagesService($repository))
            ->execute(new ListPackagesQuery($operator, 'ana', 'EN_RUTA', '2026-10-01', '2026-10-02', 3, 10));

        self::assertSame($expected, $result);
    }

    #[DataProvider('usuariosSinPermiso')]
    public function test_rechaza_usuarios_que_no_son_operadores_con_sede(string $role, ?int $idSede): void
    {
        $repository = $this->createMock(PackageQueryRepository::class);
        $repository->expects($this->never())->method('searchBySede');

        $user = new AuthenticatedUser(2, 'Usuario', 'usuario@example.invalid', $role, false, $idSede);

        $this->expectException(UserNotAllowed::class);
        (new ListPackagesService($repository))->execute(new ListPackagesQuery($user));
    }

    public static function usuariosSinPermiso(): array
    {
        return [
            'conductor' => ['CONDUCTOR', 1],
            'administrador' => ['ADMINISTRADOR', null],
            'operador sin sede' => ['OPERADOR', null],
        ];
    }
}
