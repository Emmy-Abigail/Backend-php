<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PrepareBatchesForAutomaticAssignment extends AbstractMigration
{
    public function up(): void
    {
        // Validate historical batches before issuing any MySQL DDL.
        $invalidBatches = $this->fetchRow(<<<'SQL'
SELECT COUNT(*) AS total
FROM lotes l
LEFT JOIN usuarios u ON u.id = l.id_conductor
WHERE u.id IS NULL OR u.rol <> 'CONDUCTOR' OR u.id_tipo_vehiculo IS NULL
SQL);

        if ((int) $invalidBatches['total'] > 0) {
            throw new RuntimeException('No se puede migrar: existen lotes sin un conductor con tipo de vehiculo valido.');
        }

        // Temporarily allow NULL so existing batches can inherit their driver's vehicle.
        $this->execute(<<<'SQL'
ALTER TABLE lotes
    MODIFY COLUMN estado ENUM('EN_PREPARACION', 'ASIGNADO', 'EN_RUTA', 'CERRADO') NOT NULL DEFAULT 'EN_PREPARACION',
    MODIFY COLUMN id_conductor BIGINT NULL,
    ADD COLUMN id_tipo_vehiculo TINYINT NULL AFTER id_zona_destino,
    ADD COLUMN version INT NOT NULL DEFAULT 0,
    ADD COLUMN despachado_por BIGINT NULL,
    ADD COLUMN despachado_at DATETIME NULL
SQL);

        $this->execute(<<<'SQL'
UPDATE lotes l
INNER JOIN usuarios u ON u.id = l.id_conductor
SET l.id_tipo_vehiculo = u.id_tipo_vehiculo
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE lotes
    MODIFY COLUMN id_tipo_vehiculo TINYINT NOT NULL,
    ADD CONSTRAINT fk_lotes_tipo_vehiculo FOREIGN KEY (id_tipo_vehiculo) REFERENCES tipos_vehiculo(id),
    ADD CONSTRAINT fk_lotes_despachado_por FOREIGN KEY (despachado_por) REFERENCES usuarios(id),
    ADD CONSTRAINT ck_lotes_estado_conductor CHECK (
           (estado = 'EN_PREPARACION' AND id_conductor IS NULL)
        OR (estado <> 'EN_PREPARACION' AND id_conductor IS NOT NULL)
    ),
    ADD INDEX idx_lotes_asignacion_automatica (id_sede, id_zona_destino, id_tipo_vehiculo, estado, created_at)
SQL);
    }

    public function down(): void
    {
        // The previous schema cannot represent batches awaiting dispatch.
        $pendingBatches = $this->fetchRow(<<<'SQL'
SELECT COUNT(*) AS total
FROM lotes
WHERE estado = 'EN_PREPARACION' OR id_conductor IS NULL
SQL);

        if ((int) $pendingBatches['total'] > 0) {
            throw new RuntimeException('No se puede revertir: existen lotes en preparacion. Despachelos antes de volver al esquema anterior.');
        }

        $this->execute(<<<'SQL'
ALTER TABLE lotes
    DROP CHECK ck_lotes_estado_conductor,
    DROP FOREIGN KEY fk_lotes_conductor,
    DROP FOREIGN KEY fk_lotes_tipo_vehiculo,
    DROP FOREIGN KEY fk_lotes_despachado_por,
    DROP INDEX idx_lotes_asignacion_automatica,
    DROP COLUMN id_tipo_vehiculo,
    DROP COLUMN version,
    DROP COLUMN despachado_por,
    DROP COLUMN despachado_at,
    MODIFY COLUMN id_conductor BIGINT NOT NULL,
    MODIFY COLUMN estado ENUM('ASIGNADO', 'EN_RUTA', 'CERRADO') NOT NULL DEFAULT 'ASIGNADO'
SQL);

        $this->execute('ALTER TABLE lotes ADD CONSTRAINT fk_lotes_conductor FOREIGN KEY (id_conductor) REFERENCES usuarios(id)');
    }
}
