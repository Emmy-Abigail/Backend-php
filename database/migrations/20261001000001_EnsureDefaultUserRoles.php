<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class EnsureDefaultUserRoles extends AbstractMigration
{
    public function up(): void
    {
        $roleColumn = $this->fetchRow("SHOW COLUMNS FROM usuarios LIKE 'rol'");
        if ($roleColumn !== [] && str_contains((string) $roleColumn['Type'], "'SuperAdmin'")) {
            $this->execute("UPDATE usuarios SET rol = 'Admin' WHERE rol = 'SuperAdmin'");
        }

        $this->execute("ALTER TABLE usuarios MODIFY rol ENUM('Admin', 'Conductor') NOT NULL");
    }

    public function down(): void
    {
        $this->execute("ALTER TABLE usuarios MODIFY rol ENUM('Admin', 'Conductor') NOT NULL");
    }
}
