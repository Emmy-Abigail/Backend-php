<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

require_once __DIR__ . '/RouteDatabaseFixture.php';

final class DeliveryDatabaseFixture
{
    public static function create(Connection $db): void
    {
        RouteDatabaseFixture::create($db);
        $schema = $db->getSchemaBuilder();
        $schema->table('lotes', static function (Blueprint $table): void {
            $table->dateTime('closed_at')->nullable();
        });
        $schema->table('paquetes', static function (Blueprint $table): void {
            $table->string('tracking_id')->default('RIVA-TEST');
            $table->unsignedTinyInteger('id_sede')->default(1);
        });
        $schema->create('motivos_fallo', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('codigo')->unique();
        });
        $schema->table('historial_estados', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id_motivo_fallo')->nullable();
            $table->text('comentario')->nullable();
            $table->string('evidencia_key')->nullable();
        });
        $db->table('estados_paquete')->insert(['id' => 5, 'codigo' => 'FALLIDO']);
        $db->table('motivos_fallo')->insert(['id' => 1, 'codigo' => 'CLIENTE_AUSENTE']);
        $db->table('lotes')->where('id', 210)->update(['estado' => 'EN_RUTA', 'started_at' => '2026-10-24 14:00:00']);
        $db->table('paquetes')->where('id_lote', 210)->update(['estado_actual_id' => 3]);
        $db->table('paquetes')->where('id', 4)->update(['id_sede' => 2]);
    }
}
