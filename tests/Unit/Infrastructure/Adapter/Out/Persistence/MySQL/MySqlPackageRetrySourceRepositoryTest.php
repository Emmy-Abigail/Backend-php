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
        $this->db->unprepared('CREATE TABLE clientes (id INTEGER PRIMARY KEY, tipo_documento TEXT, numero_documento TEXT, nombres_razon_social TEXT, celular TEXT)');
        $this->db->unprepared('CREATE TABLE paquetes (id INTEGER PRIMARY KEY, id_paquete_origen INTEGER, id_sede INTEGER, id_remitente INTEGER, destinatario_nombres TEXT, destinatario_dni TEXT, destinatario_celular TEXT, ubigeo_destino TEXT, tipo_via TEXT, nombre_via TEXT, numero TEXT, manzana TEXT, lote_predio TEXT, referencia TEXT, peso_kg NUMERIC, largo_cm NUMERIC, ancho_cm NUMERIC, alto_cm NUMERIC, estado_actual_id INTEGER)');
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
        // El 6 es un reintento del 2, pero todavía no tiene un reintento propio.
        self::assertFalse($this->repository->findRetrySource(6)->alreadyRetried);
        self::assertNull($this->repository->findRetrySource(999));
    }
}
