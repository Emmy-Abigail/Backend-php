<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Batch;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\StartRouteCommand;
use App\Application\Port\In\Batch\StartRouteResult;
use App\Application\Port\Out\Batch\BatchRouteRepository;
use App\Application\Service\Batch\StartRouteService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StartRouteServiceTest extends TestCase
{
    public function test_inicia_con_el_conductor_autenticado_y_formatea_la_hora_de_lima(): void
    {
        $command = new StartRouteCommand(new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', 'CONDUCTOR'), 210);
        $result = new StartRouteResult(210, 'L-ABC123', new DateTimeImmutable('2026-10-24T15:30:00+00:00'), 2);
        $repository = $this->createMock(BatchRouteRepository::class);
        $repository->expects($this->once())->method('start')->with($command)->willReturn($result);

        self::assertSame([
            'id' => 210, 'codigo' => 'L-ABC123', 'estado' => 'EN_RUTA',
            'started_at' => '2026-10-24T10:30:00-05:00', 'cantidad_paquetes' => 2,
        ], (new StartRouteService($repository))->execute($command)->toArray());
    }

    public static function forbiddenUsers(): array
    {
        return [['OPERADOR', false], ['ADMINISTRADOR', false], ['CONDUCTOR', true]];
    }

    #[DataProvider('forbiddenUsers')]
    public function test_rechaza_roles_y_password_pendiente_sin_acceder_a_la_base(string $role, bool $mustChange): void
    {
        $repository = $this->createMock(BatchRouteRepository::class);
        $repository->expects($this->never())->method('start');
        $this->expectException(UserNotAllowed::class);
        (new StartRouteService($repository))->execute(new StartRouteCommand(
            new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', $role, $mustChange), 210,
        ));
    }
}
