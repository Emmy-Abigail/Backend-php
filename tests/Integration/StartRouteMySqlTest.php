<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteDatabaseFixture;

require_once __DIR__ . '/../Support/RouteDatabaseFixture.php';

/** Concurrencia real: solo se ejecuta con una BD MySQL vacía y explícitamente reservada. */
final class StartRouteMySqlTest extends TestCase
{
    private ?Connection $db = null;
    private array $options = [];
    private bool $ownsTables = false;

    protected function setUp(): void
    {
        if (getenv('START_ROUTE_TEST_DATABASE') !== 'riva_start_route_test') {
            self::markTestSkipped('Configurar START_ROUTE_TEST_DATABASE=riva_start_route_test y una BD MySQL vacia para probar concurrencia');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('La prueba concurrente requiere proc_open');
        }
        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $this->options = [
            'driver' => 'mysql', 'database' => 'riva_start_route_test',
            'host' => getenv('START_ROUTE_TEST_HOST') ?: $_ENV['DB_HOST'],
            'port' => (int) (getenv('START_ROUTE_TEST_PORT') ?: $_ENV['DB_PORT']),
            'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ];
        $capsule = new Capsule();
        $capsule->addConnection($this->options);
        $this->db = $capsule->getConnection();
        self::assertSame([], $this->db->select('SHOW TABLES'), 'La base de prueba debe estar vacia; no se modificaron sus tablas');
        $this->ownsTables = true;
        RouteDatabaseFixture::create($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->ownsTables) {
            if ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
            // Únicamente tablas creadas por esta prueba dentro de la BD comprobada en setUp.
            foreach (['historial_estados', 'paquetes', 'estados_paquete', 'lotes', 'usuarios'] as $table) {
                $this->db->getSchemaBuilder()->dropIfExists($table);
            }
            $this->db->disconnect();
        }
        parent::tearDown();
    }

    public static function simultaneousStarts(): array
    {
        return ['dos lotes del mismo conductor' => [211], 'doble clic en el mismo lote' => [210]];
    }

    #[DataProvider('simultaneousStarts')]
    public function test_solo_un_inicio_concurrente_se_confirma(int $secondBatch): void
    {
        $workers = [];
        try {
            $this->db->beginTransaction();
            $this->db->table('usuarios')->where('id', 31)->lockForUpdate()->first();
            foreach ([210, $secondBatch] as $batchId) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/start_route_worker.php', (string) $batchId],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 2),
                    array_merge(getenv(), ['START_ROUTE_WORKER_CONFIG' => json_encode($this->options, JSON_THROW_ON_ERROR)]),
                    ['bypass_shell' => true],
                );
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                self::assertSame("READY\n", fgets($pipes[1]), 'El worker no pudo conectar con MySQL');
            }
            // Ambos workers deben esperar al mutex del conductor sin hacer cambios.
            usleep(200000);
            self::assertSame(0, $this->db->table('lotes')->where('estado', 'EN_RUTA')->count());
            $this->db->commit();

            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $body = json_decode(trim(stream_get_contents($pipes[1])), true, 32, JSON_THROW_ON_ERROR);
                $statuses[] = $body['status'];
            }
            sort($statuses);
            self::assertSame([200, 409], $statuses);
            self::assertSame(1, $this->db->table('lotes')->where('id_conductor', 31)->where('estado', 'EN_RUTA')->count());
            $started = $this->db->table('lotes')->where('estado', 'EN_RUTA')->value('id');
            $count = $this->db->table('paquetes')->where('id_lote', $started)->count();
            self::assertSame($count, $this->db->table('historial_estados')->count());
            self::assertSame($count, $this->db->table('paquetes')->where('estado_actual_id', 3)->count());
            self::assertSame(0, $this->db->table('lotes')->where('id', 212)->whereNotNull('started_at')->count());
        } finally {
            if ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
    }
}
