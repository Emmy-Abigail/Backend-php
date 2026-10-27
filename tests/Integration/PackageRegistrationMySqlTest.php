<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Exception\PackageRegistrationConflict;
use App\Application\Exception\PricingUnavailable;
use App\Application\Exception\RegistrationSnapshotChanged;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Application\Port\Out\Assignment\BatchAssignmentGateway;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Application\Service\Package\RegisterPackageService;
use App\Domain\Package\PackageDetails;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageRegistrationRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlTariffRepository;
use App\Infrastructure\Adapter\Out\Pricing\LocalPackagePricing;
use App\Infrastructure\Adapter\Out\Security\RandomTrackingGenerator;
use App\Infrastructure\Adapter\Out\Security\JwtTokenService;
use App\Domain\User\User;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedPackagePricing;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../Support/PackageFixture.php';
require_once __DIR__ . '/../Support/FixedPackagePricing.php';

/** Ejecución opcional: usar únicamente la base de datos temporal indicada abajo. */
final class PackageRegistrationMySqlTest extends TestCase
{
    private static Capsule $capsule;
    private static Connection $db;

    public static function setUpBeforeClass(): void
    {
        $testDatabase = getenv('PACKAGE_TEST_DATABASE');
        if ($testDatabase !== 'riva_package_registration_test') {
            self::markTestSkipped('Set PACKAGE_TEST_DATABASE=riva_package_registration_test for isolated MySQL checks');
        }
        Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        $source = $_ENV['DB_DATABASE'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $source) || $source === $testDatabase) {
            throw new \RuntimeException('Invalid source database for isolated tests');
        }
        self::$capsule = new Capsule();
        $options = [
            'driver' => 'mysql', 'host' => $_ENV['DB_HOST'], 'port' => (int) $_ENV['DB_PORT'],
            'database' => $testDatabase, 'username' => $_ENV['DB_USERNAME'], 'password' => $_ENV['DB_PASSWORD'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'options' => [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        ];
        self::$capsule->addConnection($options, 'registration-test');
        self::$capsule->addConnection($options, 'registration-test-contender');
        self::$capsule->getDatabaseManager()->setDefaultConnection('registration-test');
        self::$capsule->setAsGlobal();
        self::$db = self::$capsule->getConnection('registration-test');

        $catalogs = ['zonas', 'departamentos', 'provincias', 'distritos', 'sedes', 'tipos_vehiculo', 'estados_paquete', 'motivos_fallo'];
        foreach (array_merge($catalogs, ['usuarios', 'tarifas', 'clientes', 'lotes', 'paquetes', 'historial_estados']) as $table) {
            if (self::$db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $row = self::$db->selectOne("SHOW CREATE TABLE `{$source}`.`{$table}`");
            self::$db->statement(array_values((array) $row)[1]);
            if (in_array($table, $catalogs, true)) {
                self::$db->statement("INSERT INTO `{$table}` SELECT * FROM `{$source}`.`{$table}`");
            }
        }
        self::$db->table('usuarios')->insertOrIgnore([
            ['id' => 1, 'nombres' => 'Test admin', 'dni' => '00000001', 'correo' => 'admin@example.invalid', 'rol' => 'ADMINISTRADOR', 'id_sede' => null, 'id_tipo_vehiculo' => null, 'placa' => null, 'password_hash' => 'test-only'],
            ['id' => 2, 'nombres' => 'Test operator', 'dni' => '00000002', 'correo' => 'operator@example.invalid', 'rol' => 'OPERADOR', 'id_sede' => 1, 'id_tipo_vehiculo' => null, 'placa' => null, 'password_hash' => 'test-only'],
            ['id' => 3, 'nombres' => 'Test driver', 'dni' => '00000003', 'correo' => 'driver@example.invalid', 'rol' => 'CONDUCTOR', 'id_sede' => null, 'id_tipo_vehiculo' => 1, 'placa' => 'TST123', 'password_hash' => 'test-only'],
        ]);
        self::$db->table('tarifas')->insertOrIgnore(['id' => 1, 'tarifa_base_kg' => '0.05', 'vigente_desde' => '2020-01-01 00:00:00', 'creado_por' => null]);
    }

    protected function setUp(): void
    {
        // Esta conexión solo puede apuntar a la base de datos temporal comprobada explícitamente.
        foreach (['historial_estados', 'paquetes', 'lotes', 'clientes'] as $table) {
            self::$db->table($table)->delete();
        }
    }

    private function gateway(string $action, ?int $batchId = null): BatchAssignmentGateway
    {
        return new class($action, $batchId) implements BatchAssignmentGateway {
            public function __construct(private string $action, private ?int $batchId) {}
            public function evaluate(AssignmentSnapshot $snapshot): BatchAssignment
            {
                return new BatchAssignment($this->action, 1, $this->batchId);
            }
        };
    }

    private function register(string $action = 'CREAR_LOTE', ?int $batchId = null, ?RegisterPackageCommand $command = null): RegisterPackageResult
    {
        $tariffId = (int) self::$db->table('tarifas')->orderBy('id')->value('id');
        $service = new RegisterPackageService(new MySqlPackageRegistrationRepository(self::$db), new FixedPackagePricing($tariffId), $this->gateway($action, $batchId), new RandomTrackingGenerator());

        return $service->execute($command ?? PackageFixture::command());
    }

    public function test_unavailable_pricing_leaves_all_operational_tables_untouched(): void
    {
        $gateway = $this->createMock(BatchAssignmentGateway::class);
        $gateway->expects($this->never())->method('evaluate');
        $service = new RegisterPackageService(new MySqlPackageRegistrationRepository(self::$db), new LocalPackagePricing($this->createStub(TariffRepository::class)), $gateway, new RandomTrackingGenerator());
        try {
            $service->execute(PackageFixture::command());
            self::fail('Production registration must not succeed without pricing');
        } catch (PricingUnavailable) {
            foreach (['clientes', 'lotes', 'paquetes', 'historial_estados'] as $table) {
                self::assertSame(0, self::$db->table($table)->count());
            }
        }
    }

    public function test_creates_preparing_batch_package_and_first_history_atomically(): void
    {
        $result = $this->register();
        $package = self::$db->table('paquetes')->where('id', $result->id)->first();
        $batch = self::$db->table('lotes')->where('id', $result->batch['id'])->first();
        self::assertSame('EN_PREPARACION', $batch->estado);
        self::assertNull($batch->id_conductor);
        self::assertSame(1, (int) $batch->id_tipo_vehiculo);
        self::assertSame(0, (int) $batch->version);
        self::assertSame(1, (int) $package->id_sede);
        self::assertSame(2, (int) $package->creado_por);
        self::assertNull($package->orden_parada);
        self::assertSame('15.50', $package->costo_estimado);
        self::assertSame(1, self::$db->table('historial_estados')->where('id_paquete', $result->id)->count());
        self::assertSame($result->tracking, $package->tracking_id);
        self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{10}$/D', $result->tracking);
    }

    public function test_reuses_batch_and_sender_without_overwriting_previous_price(): void
    {
        $first = $this->register();
        $second = $this->register('USAR_LOTE', $first->batch['id']);
        self::assertSame($first->batch['id'], $second->batch['id']);
        self::assertSame(1, self::$db->table('clientes')->count());
        self::assertSame(1, self::$db->table('lotes')->count());
        self::assertSame(2, self::$db->table('historial_estados')->count());
        self::assertNotSame($first->tracking, $second->tracking);
        self::assertSame('15.50', self::$db->table('paquetes')->where('id', $first->id)->value('costo_estimado'));
    }

    public function test_saves_emails_and_map_point_and_keeps_the_sender_email_when_omitted(): void
    {
        $body = PackageFixture::body();
        $body['remitente']['correo'] = 'Maria@Example.com';
        $body['destinatario']['correo'] = 'rosa@example.com';
        $body['direccion']['lat'] = -12.121234;
        $body['direccion']['lng'] = -77.030987;
        $operator = PackageFixture::command()->operator;
        $first = $this->register(command: new RegisterPackageCommand($operator, PackageDetails::fromArray($body)));

        $package = self::$db->table('paquetes')->where('id', $first->id)->first();
        self::assertSame('rosa@example.com', $package->destinatario_correo);
        self::assertSame('-12.121234', $package->destino_lat);
        self::assertSame('-77.030987', $package->destino_lng);
        self::assertSame('maria@example.com', self::$db->table('clientes')->value('correo'));

        // Una segunda visita sin correo ni mapa no borra el correo del cliente.
        $second = $this->register('USAR_LOTE', $first->batch['id']);
        self::assertSame('maria@example.com', self::$db->table('clientes')->value('correo'));
        $package = self::$db->table('paquetes')->where('id', $second->id)->first();
        self::assertNull($package->destinatario_correo);
        self::assertNull($package->destino_lat);
        self::assertNull($package->destino_lng);
    }

    public function test_opens_a_new_batch_when_the_vehicle_weight_would_be_exceeded(): void
    {
        $first = $this->register();
        for ($index = 0; $index < 5; $index++) {
            $this->register('USAR_LOTE', $first->batch['id']);
        }
        $next = $this->register();
        self::assertNotSame($first->batch['id'], $next->batch['id']);
        self::assertSame(6, self::$db->table('paquetes')->where('id_lote', $first->batch['id'])->count());
        self::assertSame('18.00', self::$db->table('paquetes')->where('id_lote', $first->batch['id'])->sum('peso_kg'));
    }

    public function test_opens_a_new_batch_after_twenty_light_packages(): void
    {
        $body = PackageFixture::body();
        $body['peso_kg'] = '0.10';
        $command = new RegisterPackageCommand(PackageFixture::command()->operator, PackageDetails::fromArray($body));
        $first = $this->register(command: $command);
        for ($index = 0; $index < 19; $index++) {
            $this->register('USAR_LOTE', $first->batch['id'], $command);
        }
        $next = $this->register(command: $command);
        self::assertNotSame($first->batch['id'], $next->batch['id']);
        self::assertSame(20, self::$db->table('paquetes')->where('id_lote', $first->batch['id'])->count());
    }

    public function test_stale_create_recommendation_cannot_create_a_duplicate_batch(): void
    {
        $repository = new MySqlPackageRegistrationRepository(self::$db);
        $command = PackageFixture::command();
        $snapshot = $repository->snapshot($repository->contextFor(1, '150122'), $command->details);
        $this->register(); // Otro registro se confirma antes de guardar la primera recomendación.
        try {
            $repository->register($command, $snapshot, new BatchAssignment('CREAR_LOTE', 1, null), new PackageQuote((int) self::$db->table('tarifas')->value('id'), '15.50'), 'AB23456789');
            self::fail('Stale recommendation was accepted');
        } catch (RegistrationSnapshotChanged) {
            self::assertSame(1, self::$db->table('lotes')->count());
            self::assertSame(1, self::$db->table('paquetes')->count());
        }
    }

    public function test_dispatch_between_evaluation_and_commit_invalidates_the_snapshot(): void
    {
        $first = $this->register();
        $repository = new MySqlPackageRegistrationRepository(self::$db);
        $command = PackageFixture::command();
        $snapshot = $repository->snapshot($repository->contextFor(1, '150122'), $command->details);
        self::$db->table('lotes')->where('id', $first->batch['id'])->update(['estado' => 'ASIGNADO', 'id_conductor' => 3, 'version' => 1]);
        try {
            $repository->register($command, $snapshot, new BatchAssignment('USAR_LOTE', 1, $first->batch['id']), new PackageQuote((int) self::$db->table('tarifas')->value('id'), '15.50'), 'AB23456789');
            self::fail('Dispatched batch was reused');
        } catch (RegistrationSnapshotChanged) {
            self::assertSame(1, self::$db->table('paquetes')->count());
        }
    }

    public function test_group_lock_protects_the_absence_of_any_existing_batch(): void
    {
        $lockName = 'riva:register:' . sha1(self::$db->getDatabaseName() . ':1:3');
        self::assertSame(1, (int) self::$db->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->acquired);
        $contender = self::$capsule->getConnection('registration-test-contender');
        $repository = new MySqlPackageRegistrationRepository($contender, 0);
        $command = PackageFixture::command();
        $snapshot = $repository->snapshot($repository->contextFor(1, '150122'), $command->details);
        try {
            $repository->register($command, $snapshot, new BatchAssignment('CREAR_LOTE', 1, null), new PackageQuote((int) self::$db->table('tarifas')->value('id'), '15.50'), 'AB23456789');
            self::fail('Concurrent registration bypassed the group lock');
        } catch (PackageRegistrationConflict) {
            self::assertSame(0, self::$db->table('lotes')->count());
        } finally {
            self::$db->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    public function test_history_failure_rolls_back_sender_batch_and_package(): void
    {
        self::$db->statement('ALTER TABLE historial_estados ADD CONSTRAINT ck_test_reject_history CHECK (id_usuario_ejecutor < 0)');
        try {
            $this->register();
            self::fail('History failure should abort registration');
        } catch (QueryException) {
            foreach (['clientes', 'lotes', 'paquetes', 'historial_estados'] as $table) {
                self::assertSame(0, self::$db->table($table)->count());
            }
        } finally {
            self::$db->statement('ALTER TABLE historial_estados DROP CHECK ck_test_reject_history');
        }
    }

    public function test_tracking_collision_rolls_back_and_releases_the_group_lock(): void
    {
        $first = $this->register();
        $body = PackageFixture::body();
        $body['remitente']['numero_documento'] = '87654321';
        $command = new RegisterPackageCommand(PackageFixture::command()->operator, PackageDetails::fromArray($body));
        $repository = new MySqlPackageRegistrationRepository(self::$db);
        $snapshot = $repository->snapshot($repository->contextFor(1, '150122'), $command->details);
        try {
            $repository->register($command, $snapshot, new BatchAssignment('USAR_LOTE', 1, $first->batch['id']), new PackageQuote((int) self::$db->table('tarifas')->value('id'), '15.50'), $first->tracking);
            self::fail('Duplicate tracking was accepted');
        } catch (RegistrationSnapshotChanged) {
            foreach (['clientes', 'lotes', 'paquetes', 'historial_estados'] as $table) {
                self::assertSame(1, self::$db->table($table)->count());
            }
        }
        $second = $this->register('USAR_LOTE', $first->batch['id'], $command);
        self::assertNotSame($first->tracking, $second->tracking);
        self::assertSame(2, self::$db->table('clientes')->count());
    }

    public function test_real_http_wiring_returns_503_and_persists_nothing_without_pricing(): void
    {
        self::$db->table('tarifas')->update(['vigente_desde' => '2099-01-01 00:00:00']);
        try {
            $this->withHttpServer(function (string $address, string $jwt): void {
                [$status, $body] = $this->post($address, $jwt, '/api/v1/packages', PackageFixture::body());
                self::assertStringContainsString('503', $status);
                self::assertSame('cotizacion_no_disponible', $body['error']);
                foreach (['clientes', 'lotes', 'paquetes', 'historial_estados'] as $table) {
                    self::assertSame(0, self::$db->table($table)->count());
                }
            });
        } finally {
            self::$db->table('tarifas')->update(['vigente_desde' => '2020-01-01 00:00:00']);
        }
    }

    public function test_current_tariff_excludes_future_rates(): void
    {
        $futureId = self::$db->table('tarifas')->insertGetId([
            'tarifa_base_kg' => '1.00', 'vigente_desde' => '2099-01-01 00:00:00', 'creado_por' => null,
        ]);
        try {
            self::assertSame(1, (new MySqlTariffRepository())->findCurrent()->id);
        } finally {
            self::$db->table('tarifas')->where('id', $futureId)->delete();
        }
    }

    public function test_python_failure_returns_503_without_partial_registration(): void
    {
        $this->withHttpServer(function (string $address, string $jwt): void {
            [$status, $body] = $this->post($address, $jwt, '/api/v1/packages', PackageFixture::body());
            self::assertStringContainsString('503', $status);
            self::assertSame('asignacion_no_disponible', $body['error']);
            foreach (['clientes', 'lotes', 'paquetes', 'historial_estados'] as $table) {
                self::assertSame(0, self::$db->table($table)->count());
            }
        }, ['ASSIGNMENT_SERVICE_URL' => 'http://127.0.0.1:1', 'ASSIGNMENT_API_KEY' => 'solo-pruebas']);
    }

    public function test_real_http_price_python_assignment_and_mysql_persistence(): void
    {
        if (!getenv('PACKAGE_TEST_ASSIGNMENT_URL') || !getenv('PACKAGE_TEST_ASSIGNMENT_API_KEY')) {
            self::markTestSkipped('Configurar PACKAGE_TEST_ASSIGNMENT_URL y PACKAGE_TEST_ASSIGNMENT_API_KEY para probar Python real');
        }
        $this->withHttpServer(function (string $address, string $jwt): void {
            $body = PackageFixture::body();
            [$status, $quote] = $this->post($address, $jwt, '/api/v1/quotes', [
                'ubigeo_destino' => $body['direccion']['ubigeo_distrito'],
                ...array_intersect_key($body, array_flip(['peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm'])),
            ]);
            self::assertStringContainsString('200', $status);
            [$status, $first] = $this->post($address, $jwt, '/api/v1/packages', $body);
            self::assertStringContainsString('201', $status);
            self::assertEquals(0.23, $first['cotizacion']['monto']);
            self::assertEquals($quote['monto'], $first['cotizacion']['monto']);
            self::assertSame(1, $first['cotizacion']['id_tarifa']);
            [$status, $second] = $this->post($address, $jwt, '/api/v1/packages', $body);
            self::assertStringContainsString('201', $status);
            self::assertSame($first['lote']['id'], $second['lote']['id']);
            self::assertNotSame($first['tracking_id'], $second['tracking_id']);
            self::assertSame(1, self::$db->table('clientes')->count());
            self::assertSame(1, self::$db->table('lotes')->count());
            self::assertSame(2, self::$db->table('paquetes')->count());
            self::assertSame(2, self::$db->table('historial_estados')->count());
            self::assertSame('0.23', self::$db->table('paquetes')->where('id', $first['id'])->value('costo_estimado'));
        }, [
            'ASSIGNMENT_SERVICE_URL' => getenv('PACKAGE_TEST_ASSIGNMENT_URL'),
            'ASSIGNMENT_API_KEY' => getenv('PACKAGE_TEST_ASSIGNMENT_API_KEY'),
        ]);
    }

    private function post(string $address, string $jwt, string $path, array $payload): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$jwt}\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 10,
        ]]);
        $body = file_get_contents('http://' . $address . $path, false, $context);

        return [$http_response_header[0], json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }

    private function withHttpServer(callable $check, array $overrides = []): void
    {
        self::$db->table('usuarios')->where('id', 2)->update(['debe_cambiar_password' => false]);
        $user = new User(2, 'Test operator', 'operator@example.invalid', 'test-only', 'OPERADOR', true, null, false, '00000002', 1);
        $jwt = (new JwtTokenService($_ENV['JWT_SECRET'], $_ENV['JWT_ISSUER'], 60))->issue($user)->token;

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $root = dirname(__DIR__, 2);
        $environment = array_merge(getenv(), ['DB_DATABASE' => 'riva_package_registration_test', 'APP_ENV' => 'production'], $overrides);
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public'], [
            ['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w'],
        ], $pipes, $root, $environment);
        self::assertIsResource($server);

        try {
            $ready = false;
            for ($attempt = 0; $attempt < 40; $attempt++) {
                $health = @file_get_contents('http://' . $address . '/health', false, stream_context_create(['http' => ['timeout' => 0.2]]));
                if ($health !== false) {
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            self::assertTrue($ready, 'The isolated HTTP server did not start');
            $check($address, $jwt);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
