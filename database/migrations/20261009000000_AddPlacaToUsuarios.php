<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPlacaToUsuarios extends AbstractMigration
{
    public function up(): void
    {
        // 1) Columna placa: NULL para ADMINISTRADOR/OPERADOR, obligatoria para CONDUCTOR
        $this->execute('ALTER TABLE usuarios ADD COLUMN placa VARCHAR(8) NULL AFTER id_tipo_vehiculo');

        // 2) Conductores existentes sin placa (datos de desarrollo): asignar temporal única
        $this->execute(<<<'SQL'
UPDATE usuarios
SET placa = CONCAT('TMP', LPAD(id, 4, '0'))
WHERE rol = 'CONDUCTOR' AND placa IS NULL
SQL);

        // 3) Unicidad de placa (MySQL permite múltiples NULL en UNIQUE KEY)
        $this->execute('ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_placa (placa)');

        // 4) El CHECK por rol ahora exige placa en CONDUCTOR y la prohíbe en otros roles
        $this->execute('ALTER TABLE usuarios DROP CHECK ck_usuarios_rol');
        $this->execute(<<<'SQL'
ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_rol CHECK (
       (rol = 'ADMINISTRADOR' AND id_sede IS NULL     AND id_tipo_vehiculo IS NULL     AND placa IS NULL)
    OR (rol = 'OPERADOR'      AND id_sede IS NOT NULL AND id_tipo_vehiculo IS NULL     AND placa IS NULL)
    OR (rol = 'CONDUCTOR'     AND id_sede IS NULL     AND id_tipo_vehiculo IS NOT NULL AND placa IS NOT NULL))
SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE usuarios DROP CHECK ck_usuarios_rol');
        $this->execute(<<<'SQL'
ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_rol CHECK (
       (rol = 'ADMINISTRADOR' AND id_sede IS NULL     AND id_tipo_vehiculo IS NULL)
    OR (rol = 'OPERADOR'      AND id_sede IS NOT NULL AND id_tipo_vehiculo IS NULL)
    OR (rol = 'CONDUCTOR'     AND id_sede IS NULL     AND id_tipo_vehiculo IS NOT NULL))
SQL);
        $this->execute('ALTER TABLE usuarios DROP INDEX uq_usuarios_placa');
        $this->execute('ALTER TABLE usuarios DROP COLUMN placa');
    }
}