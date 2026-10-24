<?php

declare(strict_types=1);

namespace Tests\Integration;

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DeliveryDatabaseFixture;

require_once __DIR__ . '/../Support/DeliveryDatabaseFixture.php';

/** Usa exclusivamente una BD vacia reservada: nunca las tablas del entorno normal. */
final class FinalStatusMySqlTest extends TestCase
{
    private ?Connection $db = null;
    private array $options = [];
    private bool $ownsTables = false;

    protected function setUp(): void
    {
        if (getenv('FINAL_STATUS_TEST_DATABASE') !== 'riva_final_status_test') {
            self::markTestSkipped('Requiere FINAL_STATUS_TEST_DATABASE=riva_final_status_test y una BD MySQL vacia dedicada');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Requiere proc_open');
        }
        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $this->options = [
            'driver' => 'mysql', 'database' => 'riva_final_status_test',
            'host' => getenv('FINAL_STATUS_TEST_HOST') ?: $_ENV['DB_HOST'],
            'port' => (int) (getenv('FINAL_STATUS_TEST_PORT') ?: $_ENV['DB_PORT']),
            'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ];
        $capsule = new Capsule();
        $capsule->addConnection($this->options);
        $this->db = $capsule->getConnection();
        self::assertSame([], $this->db->select('SHOW TABLES'), 'La base de prueba debe estar vacia; no se modificaron sus tablas');
        $this->ownsTables = true;
        DeliveryDatabaseFixture::create($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->ownsTables) {
            if ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }
            foreach (['historial_estados', 'motivos_fallo', 'paquetes', 'estados_paquete', 'lotes', 'usuarios'] as $table) {
                $this->db->getSchemaBuilder()->dropIfExists($table);
            }
            $this->db->disconnect();
        }
        parent::tearDown();
    }

    public static function concurrentFinalizations(): array
    {
        return ['entrega y fallo sobre el mismo paquete' => [1], 'ultimas dos paradas del lote' => [2]];
    }

    #[DataProvider('concurrentFinalizations')]
    public function test_serializa_la_finalizacion_y_el_cierre_automatico(int $secondPackage): void
    {
        $workers = [];
        try {
            $this->db->beginTransaction();
            $this->db->table('lotes')->where('id', 210)->lockForUpdate()->first();
            foreach ([[1, 'ENTREGADO'], [$secondPackage, 'FALLIDO']] as [$packageId, $state]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/final_status_worker.php', (string) $packageId, $state],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2),
                    array_merge(getenv(), ['FINAL_STATUS_WORKER_CONFIG' => json_encode($this->options, JSON_THROW_ON_ERROR)]), ['bypass_shell' => true]);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                self::assertSame("READY\n", fgets($pipes[1]), 'El worker no pudo conectar o preparar la peticion');
            }
            usleep(200000);
            self::assertSame(0, $this->db->table('historial_estados')->count());
            $this->db->commit();
            $responses = [];
            foreach ($workers as [$process, $pipes]) {
                $responses[] = json_decode(trim(stream_get_contents($pipes[1])), true, 32, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($responses, 'status');
            sort($statuses);
            self::assertSame($secondPackage === 1 ? [200, 409] : [200, 200], $statuses);
            self::assertSame($secondPackage === 1 ? 1 : 2, $this->db->table('historial_estados')->count());
            $batch = $this->db->table('lotes')->where('id', 210)->first();
            self::assertSame($secondPackage === 1 ? 'EN_RUTA' : 'CERRADO', $batch->estado);
            self::assertSame($secondPackage === 1, $batch->closed_at === null);
            if ($secondPackage === 2) {
                self::assertSame(1, count(array_filter($responses, static fn (array $response): bool => $response['closed'])));
                self::assertSame([4, 5], $this->db->table('paquetes')->where('id_lote', 210)->orderBy('id')->pluck('estado_actual_id')->all());
            }
            self::assertSame([2, 2], $this->db->table('paquetes')->whereIn('id', [3, 4])->orderBy('id')->pluck('estado_actual_id')->all());
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
