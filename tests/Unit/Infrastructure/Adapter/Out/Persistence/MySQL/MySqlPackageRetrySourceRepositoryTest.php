<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Domain\Package\PackageDetails;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageRetrySourceRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** Ejecuta consultas reales en SQLite en memoria; no accede a la BD del proyecto. */
final class MySqlPackageRetrySourceRepositoryTest extends TestCase
{
    private Connection $db;
    private MySqlPackageRetrySourceRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        $this->db->unprepared('CREATE TABLE estados_paquete (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)');
        $this->db->unprepared('CREATE TABLE clientes (id INTEGER PRIMARY KEY, tipo_documento TEXT, numero_documento TEXT, nombres_razon_social TEXT, celular TEXT, correo TEXT)');
        $this->db->unprepared('CREATE TABLE paquetes (id INTEGER PRIMARY KEY, id_paquete_origen INTEGER, id_sede INTEGER, id_remitente INTEGER, destinatario_nombres TEXT, destinatario_dni TEXT, destinatario_celular TEXT, destinatario_correo TEXT, ubigeo_destino TEXT, tipo_via TEXT, nombre_via TEXT, numero TEXT, manzana TEXT, lote_predio TEXT, referencia TEXT, destino_lat NUMERIC, destino_lng NUMERIC, peso_kg NUMERIC, largo_cm NUMERIC, ancho_cm NUMERIC, alto_cm NUMERIC, estado_actual_id INTEGER)');
        $this->db->unprepared('CREATE TABLE motivos_fallo (id INTEGER PRIMARY KEY, codigo TEXT)');
        $this->db->unprepared('CREATE TABLE historial_estados (id INTEGER PRIMARY KEY, id_paquete INTEGER, estado_nuevo_id INTEGER, id_motivo_fallo INTEGER, created_at TEXT)');
        $this->db->table('motivos_fallo')->insert([['id' => 22, 'codigo' => 'DIRECCION_ERRONEA'], ['id' => 1, 'codigo' => 'CLIENTE_AUSENTE']]);
        $this->db->table('estados_paquete')->insert([['id' => 4, 'codigo' => 'ENTREGADO', 'nombre' => 'Entregado'], ['id' => 5, 'codigo' => 'FALLIDO', 'nombre' => 'Fallido']]);
        $this->db->table('clientes')->insert(['id' => 1, 'tipo_documento' => 'DNI', 'numero_documento' => '45678912', 'nombres_razon_social' => 'Remitente SAC', 'celular' => '987654321']);
        $package = [
            'id_sede' => 1, 'id_remitente' => 1, 'destinatario_nombres' => 'Maria Quispe', 'destinatario_dni' => '41234567',
            'destinatario_celular' => '912345678', 'ubigeo_destino' => '150104', 'tipo_via' => 'Av.', 'nombre_via' => 'Grau',
            'numero' => '123', 'manzana' => null, 'lote_predio' => 'B', 'referencia' => null,
            'peso_kg' => '5.25', 'largo_cm' => '40.5', 'ancho_cm' => '30.0', 'alto_cm' => '20.0',
        ];
        $this->db->table('paquetes')->insert([
            $package + ['id' => 2, 'id_paquete_origen' => null, 'estado_actual_id' => 5],
            $package + ['id' => 6, 'id_paquete_origen' => 2, 'estado_actual_id' => 4],
            $package + ['id' => 7, 'id_paquete_origen' => null, 'estado_actual_id' => 5],
            // Cadena del envío 2: 2 (original) -> 6 -> 8 -> 9 (tercer reintento).
            $package + ['id' => 8, 'id_paquete_origen' => 6, 'estado_actual_id' => 5],
            $package + ['id' => 9, 'id_paquete_origen' => 8, 'estado_actual_id' => 5],
        ]);
        $this->repository = new MySqlPackageRetrySourceRepository($this->db);
    }

    public function test_lee_el_paquete_en_el_formato_del_registro(): void
    {
        $source = $this->repository->findRetrySource(7);

        self::assertSame(1, $source->sedeId);
        self::assertSame('FALLIDO', $source->status);
        self::assertFalse($source->alreadyRetried);
        self::assertSame('Remitente SAC', $source->registrationInput['remitente']['nombres']);
        self::assertSame('B', $source->registrationInput['direccion']['lote']);

        // Los datos leídos pasan la misma validación que un registro nuevo, sin perder decimales.
        $details = PackageDetails::fromArray($source->registrationInput);
        self::assertSame(525, $details->weightUnits);
        self::assertSame(405, $details->lengthUnits);
    }

    public function test_detecta_si_ya_tiene_reintento(): void
    {
        self::assertTrue($this->repository->findRetrySource(2)->alreadyRetried);
        self::assertTrue($this->repository->findRetrySource(6)->alreadyRetried);
        // El 9 es el último de la cadena: todavía no tiene un reintento propio.
        self::assertFalse($this->repository->findRetrySource(9)->alreadyRetried);
        self::assertNull($this->repository->findRetrySource(999));
    }

    public function test_el_reintento_lee_el_punto_corregido_como_numeros(): void
    {
        $this->db->table('paquetes')->where('id', 7)->update(['destino_lat' => '-12.046597', 'destino_lng' => '-77.032719']);
        $source = $this->repository->findRetrySource(7);
        self::assertSame(-12.046597, $source->registrationInput['direccion']['lat']);
        self::assertSame(-77.032719, $source->registrationInput['direccion']['lng']);
        self::assertSame('-12.046597', PackageDetails::fromArray($source->registrationInput)->address['lat']);
    }

    public function test_recupera_solo_el_motivo_del_ultimo_evento_por_fecha_e_id(): void
    {
        self::assertNull($this->repository->findRetrySource(7)->failureReason);
        $this->db->table('historial_estados')->insert([
            ['id' => 1, 'id_paquete' => 7, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 22, 'created_at' => '2026-10-31 10:00:00'],
            ['id' => 2, 'id_paquete' => 2, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 1, 'created_at' => '2026-10-31 10:01:00'],
        ]);
        $source = $this->repository->findRetrySource(7);
        self::assertSame('FALLIDO', $source->lastStatus);
        self::assertSame('DIRECCION_ERRONEA', $source->failureReason);
        $this->db->table('historial_estados')->insert(['id' => 3, 'id_paquete' => 7, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 1, 'created_at' => '2026-10-31 10:00:00']);
        self::assertSame('CLIENTE_AUSENTE', $this->repository->findRetrySource(7)->failureReason);
        $this->db->table('historial_estados')->insert(['id' => 4, 'id_paquete' => 7, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 22, 'created_at' => '2026-10-30 10:00:00']);
        self::assertSame('CLIENTE_AUSENTE', $this->repository->findRetrySource(7)->failureReason);
    }

    public function test_copia_los_correos_sin_perderlos_al_cambiar_solo_la_direccion(): void
    {
        $this->db->table('clientes')->update(['correo' => 'remitente@example.com']);
        $this->db->table('paquetes')->where('id', 7)->update(['destinatario_correo' => 'destinatario@example.com']);
        $input = $this->repository->findRetrySource(7)->registrationInput;
        self::assertSame('remitente@example.com', $input['remitente']['correo']);
        self::assertSame('destinatario@example.com', $input['destinatario']['correo']);
    }

    public function test_cuenta_los_reintentos_anteriores_de_la_cadena(): void
    {
        self::assertSame(0, $this->repository->findRetrySource(2)->previousRetries);
        self::assertSame(1, $this->repository->findRetrySource(6)->previousRetries);
        self::assertSame(2, $this->repository->findRetrySource(8)->previousRetries);
        self::assertSame(3, $this->repository->findRetrySource(9)->previousRetries);
        self::assertSame(0, $this->repository->findRetrySource(7)->previousRetries);
    }
}
