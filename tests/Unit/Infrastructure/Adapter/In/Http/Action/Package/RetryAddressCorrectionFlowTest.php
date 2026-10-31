<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Package;

use App\Application\Exception\PricingUnavailable;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Application\Service\Package\RetryPackageService;
use App\Infrastructure\Adapter\In\Http\Action\Package\RetryPackageAction;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageRetrySourceRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** HTTP, reglas y lectura SQL reales; registro sustituido para no llamar a Python ni SMTP. */
final class RetryAddressCorrectionFlowTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db = $capsule->getConnection();
        foreach ([
            'CREATE TABLE estados_paquete (id INTEGER PRIMARY KEY, codigo TEXT)',
            'CREATE TABLE motivos_fallo (id INTEGER PRIMARY KEY, codigo TEXT)',
            'CREATE TABLE historial_estados (id INTEGER PRIMARY KEY, id_paquete INTEGER, estado_nuevo_id INTEGER, id_motivo_fallo INTEGER, created_at TEXT)',
            'CREATE TABLE clientes (id INTEGER PRIMARY KEY, tipo_documento TEXT, numero_documento TEXT, nombres_razon_social TEXT, celular TEXT, correo TEXT)',
            'CREATE TABLE paquetes (id INTEGER PRIMARY KEY, tracking_id TEXT, id_sede INTEGER, id_remitente INTEGER, id_paquete_origen INTEGER, estado_actual_id INTEGER, destinatario_nombres TEXT, destinatario_dni TEXT, destinatario_celular TEXT, destinatario_correo TEXT, ubigeo_destino TEXT, tipo_via TEXT, nombre_via TEXT, numero TEXT, manzana TEXT, lote_predio TEXT, referencia TEXT, destino_lat NUMERIC, destino_lng NUMERIC, peso_kg NUMERIC, largo_cm NUMERIC, ancho_cm NUMERIC, alto_cm NUMERIC)',
        ] as $sql) {
            $this->db->unprepared($sql);
        }
        $this->db->table('estados_paquete')->insert([['id' => 5, 'codigo' => 'FALLIDO']]);
        $this->db->table('motivos_fallo')->insert([['id' => 22, 'codigo' => 'DIRECCION_ERRONEA'], ['id' => 1, 'codigo' => 'CLIENTE_AUSENTE']]);
        $this->db->table('clientes')->insert([
            'id' => 1, 'tipo_documento' => 'DNI', 'numero_documento' => '45678912',
            'nombres_razon_social' => 'Remitente', 'celular' => '987654321', 'correo' => 'remitente@example.com',
        ]);
        $this->db->table('paquetes')->insert([
            'id' => 10, 'tracking_id' => 'OLD2345678', 'id_sede' => 1, 'id_remitente' => 1, 'estado_actual_id' => 5,
            'destinatario_nombres' => 'Maria', 'destinatario_dni' => '41234567',
            'destinatario_celular' => '912345678', 'destinatario_correo' => 'destinatario@example.com',
            'ubigeo_destino' => '150122', 'tipo_via' => 'Av.', 'nombre_via' => 'Antigua', 'numero' => '123',
            'destino_lat' => -12.12, 'destino_lng' => -77.03,
            'peso_kg' => '5.25', 'largo_cm' => '40.5', 'ancho_cm' => '30.0', 'alto_cm' => '20.0',
        ]);
        $this->db->table('historial_estados')->insert([
            'id' => 1, 'id_paquete' => 10, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 22, 'created_at' => '2026-10-31 10:00:00',
        ]);
    }

    private function call(?array $body = null, ?RegisterPackageUseCase $register = null): ResponseInterface
    {
        if ($register === null) {
            $register = $this->createMock(RegisterPackageUseCase::class);
            $register->expects($this->never())->method('execute');
        }
        $action = new RetryPackageAction(new RetryPackageService(new MySqlPackageRetrySourceRepository($this->db), $register));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/packages/10/retry')
            ->withAttribute('authUser', new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', 'OPERADOR', false, 1));
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $action($request, new Response(), ['id' => '10']);
    }

    private function snapshot(): array
    {
        return array_map(fn (string $table): array => $this->db->table($table)->orderBy('id')->get()->all(), ['paquetes', 'historial_estados', 'clientes']);
    }

    private function newPackage(): RegisterPackageResult
    {
        return new RegisterPackageResult(20, 'NEW2345678', '2026-10-31T12:00:00+00:00', new PackageQuote(1, '15.50'), ['id' => 7, 'estado' => 'EN_PREPARACION']);
    }

    public function test_confirmar_direccion_reutiliza_registro_y_no_modifica_el_intento_fallido(): void
    {
        $before = $this->snapshot();
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RegisterPackageCommand $c): bool =>
                $c->originPackageId === 10 && $c->operator->idSede === 1
                && $c->details->address['ubigeo_distrito'] === '150101'
                && $c->details->address['nombre_via'] === 'Ica'
                && $c->details->address['numero'] === '172'
                && $c->details->address['lat'] === '-12.046597'
                && $c->details->address['lng'] === '-77.032719'
                && $c->details->recipient['nombres'] === 'Maria'
                && $c->details->recipient['correo'] === 'destinatario@example.com'
                && $c->details->sender['correo'] === 'remitente@example.com'
                && $c->details->weightUnits === 525 && $c->details->lengthUnits === 405))
            ->willReturn($this->newPackage());
        $response = $this->call(['direccion' => [
            'ubigeo_distrito' => '150101', 'tipo_via' => 'Jr.', 'nombre_via' => 'Ica', 'numero' => '172',
            'lat' => -12.046597123, 'lng' => -77.032719123,
        ]], $register);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(10, $body['id_paquete_origen']);
        self::assertSame('NEW2345678', $body['tracking_id']);
        self::assertSame('EN_ALMACEN', $body['estado']);
        self::assertEquals($before, $this->snapshot());
    }

    public static function invalidCorrections(): array
    {
        return [
            'sin cuerpo' => [null, 'direccion'],
            'sin campos' => [[], 'direccion'],
            'direccion vacia' => [['direccion' => []], 'direccion'],
            'direccion sin cambios' => [['direccion' => ['nombre_via' => 'Antigua']], 'direccion'],
            'solo espacios nuevos' => [['direccion' => ['nombre_via' => '  Antigua  ']], 'direccion'],
            'solo celular' => [['destinatario' => ['celular' => '987654321']], 'direccion'],
            'direccion y celular' => [['direccion' => ['numero' => '5'], 'destinatario' => ['celular' => '987654321']], 'destinatario'],
            'peso' => [['direccion' => ['numero' => '5'], 'peso_kg' => 4], 'peso_kg'],
            'estado anidado' => [['direccion' => ['estado' => 'EN_RUTA']], 'direccion.estado'],
            'direccion texto' => [['direccion' => 'Ica 172'], 'direccion'],
            'campo obligatorio vacio' => [['direccion' => ['nombre_via' => '']], 'direccion.nombre_via'],
            'tipo incorrecto' => [['direccion' => ['numero' => 5]], 'direccion.numero'],
            'ubigeo invalido' => [['direccion' => ['ubigeo_distrito' => 'abc']], 'direccion.ubigeo_distrito'],
            'fuera de Lima' => [['direccion' => ['lat' => 50.69, 'lng' => 4.05]], 'direccion.lat'],
            'coordenada sola' => [['direccion' => ['lat' => -12.05]], 'direccion.coordenadas'],
            'coordenada texto' => [['direccion' => ['lat' => '-12.05', 'lng' => -77.03]], 'direccion.lat'],
        ];
    }

    #[DataProvider('invalidCorrections')]
    public function test_no_reasigna_antes_de_recibir_una_direccion_corregida_valida(?array $corrections, string $field): void
    {
        $before = $this->snapshot();
        $response = $this->call($corrections);
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, json_decode((string) $response->getBody(), true)['campos']);
        self::assertEquals($before, $this->snapshot());
    }

    public function test_otro_motivo_se_reasigna_directamente_como_antes(): void
    {
        $this->db->table('historial_estados')->update(['id_motivo_fallo' => 1]);
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RegisterPackageCommand $c): bool => $c->details->address['nombre_via'] === 'Antigua' && $c->originPackageId === 10))
            ->willReturn($this->newPackage());
        self::assertSame(201, $this->call(register: $register)->getStatusCode());
    }

    public function test_cambiar_solo_la_via_sin_nuevo_punto_no_reutiliza_coordenadas_erroneas(): void
    {
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RegisterPackageCommand $c): bool => $c->details->address['nombre_via'] === 'Nueva' && $c->details->address['lat'] === null && $c->details->address['lng'] === null))
            ->willReturn($this->newPackage());
        self::assertSame(201, $this->call(['direccion' => ['nombre_via' => 'Nueva']], $register)->getStatusCode());
    }

    public function test_si_falla_la_reasignacion_no_guarda_la_direccion_en_el_paquete_original(): void
    {
        $before = $this->snapshot();
        $register = $this->createMock(RegisterPackageUseCase::class);
        $register->expects($this->once())->method('execute')->willThrowException(new PricingUnavailable());
        $response = $this->call(['direccion' => ['numero' => '5']], $register);
        self::assertSame(503, $response->getStatusCode());
        self::assertEquals($before, $this->snapshot());
    }
}
