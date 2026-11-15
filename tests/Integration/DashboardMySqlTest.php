<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Port\Out\Dashboard\DashboardScope;
use App\Application\Port\Out\Dashboard\RegistrationGroup;
use App\Application\Port\Out\Dashboard\TerminalEvent;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlDashboardReadRepository;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Consultas del dashboard contra MySQL real (esquema de las migraciones, ONLY_FULL_GROUP_BY, tipos reales).
 * Solo se ejecuta si DASHBOARD_TEST_DATABASE apunta a una BD ya migrada. Todo ocurre dentro de una
 * transacción que siempre se revierte: no deja datos. Usa marzo de 2030 para no mezclarse con otros datos.
 *
 * Ejemplo con Docker: docker compose exec -e DASHBOARD_TEST_DATABASE=riva api php vendor/bin/phpunit tests/Integration/DashboardMySqlTest.php
 */
final class DashboardMySqlTest extends TestCase
{
    private const MARCH_2030 = ['2030-03-01', '2030-03-31'];
    private const OPERATOR = 9100001;
    private const DRIVER = 9100002;

    private ?Connection $db = null;
    private MySqlDashboardReadRepository $repository;

    protected function setUp(): void
    {
        $database = getenv('DASHBOARD_TEST_DATABASE');
        if ($database === false || $database === '') {
            self::markTestSkipped('Configurar DASHBOARD_TEST_DATABASE con una BD MySQL migrada para probar las consultas del dashboard');
        }
        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql', 'database' => $database,
            'host' => getenv('DASHBOARD_TEST_HOST') ?: ($_ENV['DB_HOST'] ?? getenv('DB_HOST')),
            'port' => (int) (getenv('DASHBOARD_TEST_PORT') ?: ($_ENV['DB_PORT'] ?? getenv('DB_PORT'))),
            'username' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME'),
            'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ]);
        $this->db = $capsule->getConnection();
        self::assertTrue(
            $this->db->getSchemaBuilder()->hasTable('phinxlog') && $this->db->table('estados_paquete')->where('codigo', 'FALLIDO')->exists(),
            'La BD debe tener las migraciones aplicadas',
        );

        $this->db->beginTransaction();
        $this->insertFixture();
        $this->repository = new MySqlDashboardReadRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            // Nunca se confirma nada: la transacción abierta en setUp se revierte siempre.
            while ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
            $this->db->disconnect();
        }
        parent::tearDown();
    }

    private function scope(): DashboardScope
    {
        return new DashboardScope(self::MARCH_2030[0], self::MARCH_2030[1], 1);
    }

    public function test_agrupa_los_registrados_con_only_full_group_by(): void
    {
        $groups = array_map(
            static fn (RegistrationGroup $g): array => [$g->operatorId, $g->status, $g->failureReason, $g->count],
            $this->repository->registrationGroups($this->scope()),
        );
        sort($groups);

        self::assertSame([
            [self::OPERATOR, 'ENTREGADO', null, 2],
            [self::OPERATOR, 'FALLIDO', 'DIRECCION_ERRONEA', 1],
        ], $groups);
    }

    public function test_lee_entregas_fallos_conductor_vehiculo_y_reintento(): void
    {
        $events = $this->repository->terminalEvents($this->scope());

        self::assertSame([9100101, 9100102, 9100103], array_map(static fn (TerminalEvent $e): int => $e->packageId, $events));
        self::assertSame(self::DRIVER, $events[0]->driverId);
        self::assertSame('MOTORIZADO', $events[0]->batchVehicleType);
        self::assertSame(12 * 3600, $events[0]->occurredAt->getTimestamp() - $events[0]->registeredAt->getTimestamp());
        self::assertSame('DIRECCION_ERRONEA', $events[1]->failureReason);
        self::assertTrue($events[1]->retried);
        self::assertSame('ENTREGADO', $events[1]->retryStatus);
    }

    public function test_lee_despachos_operadores_y_fallidos(): void
    {
        self::assertCount(3, $this->repository->dispatchedPackages($this->scope()));
        self::assertContains(self::OPERATOR, array_map(static fn ($o): int => $o->id, $this->repository->operators(1)));
        self::assertSame(1, $this->repository->countFailedDeliveries($this->scope()));
        self::assertSame('ITG9100102', $this->repository->failedDeliveries($this->scope(), 1, 8)[0]->trackingId);
    }

    private function insertFixture(): void
    {
        $motorizado = (int) $this->db->table('tipos_vehiculo')->where('codigo', 'MOTORIZADO')->value('id');
        $wrongAddress = (int) $this->db->table('motivos_fallo')->where('codigo', 'DIRECCION_ERRONEA')->value('id');
        $states = array_map('intval', $this->db->table('estados_paquete')->pluck('id', 'codigo')->all());
        $sede = $this->db->table('sedes')->where('id', 1)->first(['id', 'id_zona']);
        $ubigeo = (string) $this->db->table('distritos')->where('id_zona', $sede->id_zona)->value('ubigeo');
        $tariff = (int) $this->db->table('tarifas')->max('id');
        $hash = password_hash('integracion', PASSWORD_DEFAULT);

        $this->db->table('usuarios')->insert([
            ['id' => self::OPERATOR, 'nombres' => 'Operador Integración', 'dni' => '91000001', 'correo' => 'op.integracion@riva.test', 'rol' => 'OPERADOR', 'id_sede' => 1, 'id_tipo_vehiculo' => null, 'placa' => null, 'password_hash' => $hash, 'debe_cambiar_password' => 0],
            ['id' => self::DRIVER, 'nombres' => 'Conductor Integración', 'dni' => '91000002', 'correo' => 'cond.integracion@riva.test', 'rol' => 'CONDUCTOR', 'id_sede' => null, 'id_tipo_vehiculo' => $motorizado, 'placa' => 'ITG001', 'password_hash' => $hash, 'debe_cambiar_password' => 0],
        ]);
        $this->db->table('clientes')->insert(['id' => 9100001, 'tipo_documento' => 'DNI', 'numero_documento' => '91000009', 'nombres_razon_social' => 'Cliente Integración', 'celular' => '999111222']);

        foreach ([[9100001, '2030-03-10 18:00:00'], [9100002, '2030-03-12 17:00:00']] as [$batch, $dispatchedAt]) {
            $this->db->table('lotes')->insert([
                'id' => $batch, 'codigo' => 'L-ITG' . $batch, 'id_sede' => 1, 'id_zona_destino' => $sede->id_zona,
                'id_tipo_vehiculo' => $motorizado, 'id_conductor' => self::DRIVER, 'creado_por' => self::OPERATOR,
                'estado' => 'CERRADO', 'created_at' => '2030-03-10 15:00:00', 'version' => 1,
                'despachado_por' => self::OPERATOR, 'despachado_at' => $dispatchedAt,
            ]);
        }

        // Fechas UTC; Lima = UTC-5. 9100103 es el reintento de 9100102 con la dirección corregida.
        foreach ([
            [9100101, null, $states['ENTREGADO'], 9100001, '2030-03-10 15:00:00'],
            [9100102, null, $states['FALLIDO'], 9100001, '2030-03-10 16:00:00'],
            [9100103, 9100102, $states['ENTREGADO'], 9100002, '2030-03-12 15:00:00'],
        ] as [$id, $origin, $state, $batch, $registeredAt]) {
            $this->db->table('paquetes')->insert([
                'id' => $id, 'tracking_id' => 'ITG' . $id, 'id_paquete_origen' => $origin, 'id_sede' => 1, 'id_remitente' => 9100001,
                'destinatario_nombres' => 'Destinatario ' . $id, 'destinatario_dni' => '81000000', 'destinatario_celular' => '988777666',
                'ubigeo_destino' => $ubigeo, 'tipo_via' => 'Av.', 'nombre_via' => 'Integración', 'numero' => '100',
                'peso_kg' => 2, 'largo_cm' => 30, 'ancho_cm' => 20, 'alto_cm' => 15, 'costo_estimado' => 10, 'id_tarifa' => $tariff,
                'estado_actual_id' => $state, 'id_lote' => $batch, 'orden_parada' => 1, 'fecha_ingreso' => $registeredAt, 'creado_por' => self::OPERATOR,
            ]);
        }

        $this->db->table('historial_estados')->insert([
            ['id_paquete' => 9100101, 'estado_anterior_id' => $states['EN_RUTA'], 'estado_nuevo_id' => $states['ENTREGADO'], 'id_usuario_ejecutor' => self::DRIVER, 'id_motivo_fallo' => null, 'created_at' => '2030-03-11 03:00:00'],
            ['id_paquete' => 9100102, 'estado_anterior_id' => $states['EN_RUTA'], 'estado_nuevo_id' => $states['FALLIDO'], 'id_usuario_ejecutor' => self::DRIVER, 'id_motivo_fallo' => $wrongAddress, 'created_at' => '2030-03-11 04:00:00'],
            ['id_paquete' => 9100103, 'estado_anterior_id' => $states['EN_RUTA'], 'estado_nuevo_id' => $states['ENTREGADO'], 'id_usuario_ejecutor' => self::DRIVER, 'id_motivo_fallo' => null, 'created_at' => '2030-03-13 15:00:00'],
        ]);
    }
}
