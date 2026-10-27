<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SetIllustrativeBaseTariff extends AbstractMigration
{
    private const RATE = '2.50';

    public function up(): void
    {
        // Nueva fila vigente con el valor del documento de arquitectura. La tarifa anterior
        // se conserva porque los paquetes ya registrados la referencian.
        $this->execute(sprintf("INSERT INTO tarifas (tarifa_base_kg, creado_por) VALUES (%s, NULL)", self::RATE));
    }

    public function down(): void
    {
        $tariff = $this->fetchRow(sprintf(
            'SELECT id FROM tarifas WHERE tarifa_base_kg = %s AND creado_por IS NULL ORDER BY id DESC LIMIT 1',
            self::RATE,
        ));
        if ($tariff === false) {
            return;
        }

        $used = $this->fetchRow(sprintf('SELECT COUNT(*) AS total FROM paquetes WHERE id_tarifa = %d', (int) $tariff['id']));
        if ((int) $used['total'] > 0) {
            throw new RuntimeException('No se puede revertir: hay paquetes registrados con esta tarifa.');
        }

        $this->execute(sprintf('DELETE FROM tarifas WHERE id = %d', (int) $tariff['id']));
    }
}
