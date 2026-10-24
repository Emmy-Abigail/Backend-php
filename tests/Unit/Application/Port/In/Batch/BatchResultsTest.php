<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Port\In\Batch;

use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\GetBatchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../../Support/BatchFixture.php';

final class BatchResultsTest extends TestCase
{
    #[Test]
    public function el_resumen_muestra_las_fechas_en_hora_de_lima_y_el_peso_como_numero(): void
    {
        $data = BatchFixture::summary('ASIGNADO', 1, BatchFixture::driverData())->toArray();

        // 13:00 UTC es 08:00 en Lima.
        self::assertSame('2026-10-21T08:00:00-05:00', $data['created_at']);
        self::assertSame('2026-10-21T10:00:00-05:00', $data['despachado_at']);
        self::assertSame(8.5, $data['peso_total_kg']);
        self::assertSame(2, $data['cantidad_paquetes']);
        self::assertSame('ASIGNADO', $data['estado']);
        self::assertSame(['id' => 31, 'nombres' => 'Juan Perez', 'placa' => 'AB5555'], $data['conductor']);
    }

    #[Test]
    public function un_lote_en_preparacion_no_tiene_conductor_ni_fecha_de_despacho(): void
    {
        $data = BatchFixture::summary()->toArray();

        self::assertNull($data['conductor']);
        self::assertNull($data['despachado_at']);
        self::assertSame(0, $data['version']);
        self::assertSame(['id' => 3, 'codigo' => 'SUR', 'nombre' => 'Sur'], $data['zona_destino']);
        self::assertSame('MOTORIZADO', $data['vehiculo']['codigo']);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function pages(): array
    {
        return [
            'sin resultados' => [0, 20, 0],
            'una pagina exacta' => [20, 20, 1],
            'una mas' => [21, 20, 2],
            'tamano chico' => [5, 2, 3],
            'tamano maximo' => [250, 100, 3],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function calcula_el_total_de_paginas(int $total, int $size, int $expectedPages): void
    {
        $data = (new BatchPage([BatchFixture::summary()], 1, $size, $total))->toArray();

        self::assertSame(['page' => 1, 'size' => $size, 'total' => $total, 'total_pages' => $expectedPages], $data['meta']);
        self::assertCount(1, $data['data']);
    }

    #[Test]
    public function la_vista_del_operador_trae_paquetes_progreso_y_conductores_disponibles(): void
    {
        $result = new GetBatchResult(
            BatchFixture::summary(),
            [BatchFixture::package(1), BatchFixture::package(2), BatchFixture::package(3, null, 'ASIGNADO')],
            [BatchFixture::candidate(31), BatchFixture::candidate(32, 1, true)],
            'OPERADOR',
        );

        $data = $result->toArray();

        self::assertSame(3, $data['progreso']['total']);
        self::assertSame(
            ['EN_ALMACEN' => 2, 'ASIGNADO' => 1, 'EN_RUTA' => 0, 'ENTREGADO' => 0, 'FALLIDO' => 0],
            $data['progreso']['por_estado'],
        );
        self::assertCount(3, $data['paquetes']);
        self::assertCount(2, $data['conductores_disponibles']);
        self::assertArrayNotHasKey('paradas', $data);

        // El operador no recibe los datos personales del destinatario.
        self::assertArrayNotHasKey('destinatario', $data['paquetes'][0]);
        self::assertSame(['ubigeo' => '150122', 'nombre' => 'Miraflores'], $data['paquetes'][0]['distrito']);
        self::assertSame(30.0, $data['paquetes'][0]['lado_mayor_cm']);
        self::assertSame(
            ['id' => 32, 'nombres' => 'Juan Perez', 'placa' => 'AB5555', 'lotes_pendientes' => 1, 'en_ruta' => true],
            $data['conductores_disponibles'][1],
        );
    }

    #[Test]
    public function la_vista_del_conductor_trae_las_paradas_con_destinatario_y_direccion(): void
    {
        $result = new GetBatchResult(
            BatchFixture::summary('ASIGNADO', 1, BatchFixture::driverData()),
            [BatchFixture::package(5003, 1, 'ASIGNADO'), BatchFixture::package(4991, 2, 'ASIGNADO')],
            [],
            'CONDUCTOR',
        );

        $data = $result->toArray();

        self::assertArrayNotHasKey('paquetes', $data);
        self::assertArrayNotHasKey('conductores_disponibles', $data);
        self::assertSame([1, 2], array_column($data['paradas'], 'orden'));
        self::assertSame(5003, $data['paradas'][0]['id_paquete']);
        self::assertSame('41234567', $data['paradas'][0]['destinatario']['dni']);
        self::assertSame('Los Robles', $data['paradas'][0]['direccion']['nombre_via']);
        self::assertSame(2, $data['progreso']['por_estado']['ASIGNADO']);
    }

    #[Test]
    public function el_resultado_del_despacho_resume_conductor_operador_y_paradas(): void
    {
        $data = BatchFixture::dispatchResult()->toArray();

        self::assertSame('ASIGNADO', $data['estado']);
        self::assertSame(1, $data['version']);
        self::assertSame(['id' => 5, 'nombres' => 'Ana Torres'], $data['despachado_por']);
        self::assertSame('2026-10-21T10:00:00-05:00', $data['despachado_at']);
        self::assertSame([1, 2], array_column($data['paradas'], 'orden'));
        self::assertSame(5003, $data['paradas'][0]['id_paquete']);
    }
}
