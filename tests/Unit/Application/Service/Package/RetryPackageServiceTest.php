<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Package;

use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageAlreadyRetried;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\PackageNotRetryable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Application\Port\In\Package\RetryPackageCommand;
use App\Application\Port\Out\Persistence\PackageRetrySource;
use App\Application\Port\Out\Persistence\PackageRetrySourceRepository;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Application\Service\Package\RetryPackageService;
use App\Domain\Package\InvalidPackageData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPackageServiceTest extends TestCase
{
    private static function input(): array
    {
        return [
            'remitente' => ['tipo_documento' => 'DNI', 'numero_documento' => '45678912', 'nombres' => 'Remitente', 'celular' => '987654321'],
            'destinatario' => ['nombres' => 'Maria Quispe', 'dni' => '41234567', 'celular' => '912345678'],
            'direccion' => ['ubigeo_distrito' => '150104', 'tipo_via' => 'Av.', 'nombre_via' => 'Grau', 'numero' => '123', 'manzana' => null, 'lote' => null, 'referencia' => 'Frente al parque'],
            'peso_kg' => '5.00', 'largo_cm' => '40.0', 'ancho_cm' => '30.0', 'alto_cm' => '20.0',
        ];
    }

    private function source(string $status = 'FALLIDO', int $sedeId = 1, bool $retried = false, int $previousRetries = 0): PackageRetrySource
    {
        return new PackageRetrySource($sedeId, $status, $retried, $previousRetries, self::input());
    }

    private function operator(?int $sedeId = 1, string $role = 'OPERADOR', bool $mustChange = false): AuthenticatedUser
    {
        return new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', $role, $mustChange, $sedeId);
    }

    private function newPackage(): RegisterPackageResult
    {
        return new RegisterPackageResult(20, 'NEWCODE234', '2026-10-28 15:00:00', new PackageQuote(1, '12.50'), ['id' => 3]);
    }

    private function service(?PackageRetrySource $source, ?RegisterPackageUseCase $register = null): RetryPackageService
    {
        $sources = $this->createStub(PackageRetrySourceRepository::class);
        $sources->method('findRetrySource')->willReturn($source);

        return new RetryPackageService($sources, $register ?? $this->createStub(RegisterPackageUseCase::class));
    }

    public function test_registra_un_paquete_nuevo_con_los_datos_del_fallido_y_su_origen(): void
    {
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RegisterPackageCommand $c): bool => $c->originPackageId === 7
                && $c->operator->id === 2
                && $c->details->recipient === ['nombres' => 'Maria Quispe', 'dni' => '41234567', 'celular' => '912345678', 'correo' => null]
                && $c->details->address['nombre_via'] === 'Grau'
                && $c->details->sender['numero_documento'] === '45678912'
                && $c->details->weightUnits === 500))
            ->willReturn($this->newPackage());

        $result = $this->service($this->source(), $register)->execute(new RetryPackageCommand($this->operator(), 7));

        self::assertSame('NEWCODE234', $result->tracking);
    }

    public function test_aplica_la_direccion_y_el_celular_corregidos_y_copia_lo_demas(): void
    {
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RegisterPackageCommand $c): bool => $c->details->address['nombre_via'] === 'Larco'
                && $c->details->address['numero'] === '456'
                && $c->details->address['ubigeo_distrito'] === '150104'
                && $c->details->address['referencia'] === 'Frente al parque'
                && $c->details->recipient['celular'] === '998877665'
                && $c->details->recipient['nombres'] === 'Maria Quispe'))
            ->willReturn($this->newPackage());

        $this->service($this->source(), $register)->execute(new RetryPackageCommand($this->operator(), 7, [
            'direccion' => ['nombre_via' => 'Larco', 'numero' => '456'],
            'destinatario' => ['celular' => '+51 998 877 665'],
        ]));
    }

    public function test_una_correccion_invalida_se_rechaza_con_los_campos(): void
    {
        try {
            $this->service($this->source())->execute(new RetryPackageCommand($this->operator(), 7, ['direccion' => ['ubigeo_distrito' => 'abc']]));
            self::fail('Debio rechazar el ubigeo');
        } catch (InvalidPackageData $exception) {
            self::assertArrayHasKey('direccion.ubigeo_distrito', $exception->errors);
        }
    }

    public function test_paquete_inexistente(): void
    {
        $this->expectException(PackageNotFound::class);
        $this->service(null)->execute(new RetryPackageCommand($this->operator(), 99));
    }

    public function test_paquete_de_otra_sede(): void
    {
        $this->expectException(PackageAccessDenied::class);
        $this->service($this->source(sedeId: 3))->execute(new RetryPackageCommand($this->operator(1), 7));
    }

    #[DataProvider('estadosNoFallidos')]
    public function test_solo_se_reintenta_un_paquete_fallido(string $status): void
    {
        $this->expectException(PackageNotRetryable::class);
        $this->service($this->source($status))->execute(new RetryPackageCommand($this->operator(), 7));
    }

    public static function estadosNoFallidos(): array
    {
        return [['EN_ALMACEN'], ['ASIGNADO'], ['EN_RUTA'], ['ENTREGADO']];
    }

    public function test_un_reintento_que_tambien_fallo_se_puede_volver_a_reintentar(): void
    {
        // El paquete 6 es el reintento del 2 y también falló: su reintento apunta al 6, no al 2.
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RegisterPackageCommand $c): bool => $c->originPackageId === 6))
            ->willReturn($this->newPackage());

        $this->service($this->source(), $register)->execute(new RetryPackageCommand($this->operator(), 6));
    }

    public function test_permite_el_tercer_reintento_de_un_envio(): void
    {
        // El paquete fallido ya es el segundo reintento: el que se crea ahora es el tercero y último.
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')->willReturn($this->newPackage());

        $this->service($this->source(previousRetries: 2), $register)->execute(new RetryPackageCommand($this->operator(), 9));
    }

    public function test_no_permite_un_cuarto_reintento(): void
    {
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->never())->method('execute');

        $this->expectException(PackageNotRetryable::class);
        $this->expectExceptionMessage('maximo de 3 reintentos');
        $this->service($this->source(previousRetries: 3), $register)->execute(new RetryPackageCommand($this->operator(), 11));
    }

    public function test_un_paquete_con_reintento_no_crea_otro(): void
    {
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->never())->method('execute');

        $this->expectException(PackageAlreadyRetried::class);
        $this->service($this->source(retried: true), $register)->execute(new RetryPackageCommand($this->operator(), 7));
    }

    #[DataProvider('usuariosSinPermiso')]
    public function test_solo_un_operador_habilitado_con_sede(AuthenticatedUser $user): void
    {
        $sources = $this->createMock(PackageRetrySourceRepository::class);
        $sources->expects($this->never())->method('findRetrySource');

        $this->expectException(UserNotAllowed::class);
        (new RetryPackageService($sources, $this->createStub(RegisterPackageUseCase::class)))->execute(new RetryPackageCommand($user, 7));
    }

    public static function usuariosSinPermiso(): array
    {
        return [
            'conductor' => [new AuthenticatedUser(3, 'Juan', 'juan@example.invalid', 'CONDUCTOR')],
            'operador sin sede' => [new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', 'OPERADOR', false, null)],
            'operador con cambio de clave pendiente' => [new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', 'OPERADOR', true, 1)],
        ];
    }
}
