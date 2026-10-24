<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/** Esquema mínimo aislado para comprobar transacciones sin modificar tablas de la aplicación. */
final class RouteDatabaseFixture
{
    public static function create(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->create('usuarios', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('rol');
            $table->boolean('activo')->default(true);
            $table->boolean('debe_cambiar_password')->default(false);
        });
        $schema->create('lotes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('codigo');
            $table->unsignedBigInteger('id_conductor')->nullable();
            $table->string('estado');
            $table->dateTime('started_at')->nullable();
            $table->index(['id_conductor', 'estado']);
        });
        $schema->create('estados_paquete', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('codigo')->unique();
        });
        $schema->create('paquetes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('id_lote')->index();
            $table->unsignedTinyInteger('estado_actual_id');
            $table->unsignedTinyInteger('orden_parada')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        $schema->create('historial_estados', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('id_paquete');
            $table->unsignedTinyInteger('estado_anterior_id');
            $table->unsignedTinyInteger('estado_nuevo_id');
            $table->unsignedBigInteger('id_usuario_ejecutor');
            $table->dateTime('created_at');
        });
        $db->table('usuarios')->insert([
            ['id' => 31, 'rol' => 'CONDUCTOR'], ['id' => 32, 'rol' => 'CONDUCTOR'],
        ]);
        $db->table('estados_paquete')->insert([
            ['id' => 1, 'codigo' => 'EN_ALMACEN'], ['id' => 2, 'codigo' => 'ASIGNADO'],
            ['id' => 3, 'codigo' => 'EN_RUTA'], ['id' => 4, 'codigo' => 'ENTREGADO'],
        ]);
        $db->table('lotes')->insert([
            ['id' => 210, 'codigo' => 'L-210', 'id_conductor' => 31, 'estado' => 'ASIGNADO'],
            ['id' => 211, 'codigo' => 'L-211', 'id_conductor' => 31, 'estado' => 'ASIGNADO'],
            ['id' => 212, 'codigo' => 'L-212', 'id_conductor' => 32, 'estado' => 'ASIGNADO'],
        ]);
        $db->table('paquetes')->insert([
            ['id' => 1, 'id_lote' => 210, 'estado_actual_id' => 2, 'orden_parada' => 2],
            ['id' => 2, 'id_lote' => 210, 'estado_actual_id' => 2, 'orden_parada' => 1],
            ['id' => 3, 'id_lote' => 211, 'estado_actual_id' => 2, 'orden_parada' => 1],
            ['id' => 4, 'id_lote' => 212, 'estado_actual_id' => 2, 'orden_parada' => 1],
        ]);
    }
}
