<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Motivo de fallo "Otros": el conductor describe el motivo en un campo propio (motivo_otro),
 * aparte de la observación, y se guarda en el historial del paquete.
 */
final class AddOtherFailureReason extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO motivos_fallo (id, codigo, nombre) VALUES (4, 'OTRO', 'Otros')
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE historial_estados
    ADD COLUMN motivo_otro VARCHAR(255) NULL AFTER id_motivo_fallo
SQL);
    }

    public function down(): void
    {
        // Validar antes de cualquier DDL para no dejar la reversión a medias.
        $used = $this->fetchRow(<<<'SQL'
SELECT COUNT(*) AS total
FROM historial_estados h
INNER JOIN motivos_fallo m ON m.id = h.id_motivo_fallo
WHERE m.codigo = 'OTRO'
SQL);

        if ((int) $used['total'] > 0) {
            throw new RuntimeException('No se puede revertir: existen fallos registrados con el motivo Otros.');
        }

        $this->execute('ALTER TABLE historial_estados DROP COLUMN motivo_otro');
        $this->execute("DELETE FROM motivos_fallo WHERE codigo = 'OTRO'");
    }
}
