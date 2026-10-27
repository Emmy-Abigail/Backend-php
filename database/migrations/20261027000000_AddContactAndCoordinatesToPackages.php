<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddContactAndCoordinatesToPackages extends AbstractMigration
{
    public function up(): void
    {
        // Correo opcional: los clientes y paquetes registrados antes no lo tienen.
        $this->execute('ALTER TABLE clientes ADD COLUMN correo VARCHAR(150) NULL AFTER celular');

        // Punto exacto elegido en el mapa. Si falta, la ruta usa el centroide del distrito.
        $this->execute(<<<'SQL'
ALTER TABLE paquetes
    ADD COLUMN destinatario_correo VARCHAR(150) NULL AFTER destinatario_celular,
    ADD COLUMN destino_lat DECIMAL(9,6) NULL AFTER referencia,
    ADD COLUMN destino_lng DECIMAL(9,6) NULL AFTER destino_lat,
    ADD CONSTRAINT ck_paquetes_coordenadas CHECK ((destino_lat IS NULL) = (destino_lng IS NULL))
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE paquetes
    DROP CHECK ck_paquetes_coordenadas,
    DROP COLUMN destino_lng,
    DROP COLUMN destino_lat,
    DROP COLUMN destinatario_correo
SQL);
        $this->execute('ALTER TABLE clientes DROP COLUMN correo');
    }
}
