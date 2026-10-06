<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class EnsureDefaultUserRoles extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('usuarios')) {
            return;
        }

        $row = $this->fetchRow("SHOW COLUMNS FROM usuarios LIKE 'rol'");
        if (!$row || !isset($row['Type'])) {
            return;
        }

        $columnType = (string) $row['Type'];

        $hasLegacy = str_contains($columnType, "'Admin'") || str_contains($columnType, "'SuperAdmin'");
        $hasV3 = str_contains($columnType, "'ADMINISTRADOR'");

        if ($hasLegacy && !$hasV3) {
            $this->execute("ALTER TABLE usuarios MODIFY rol ENUM('ADMINISTRADOR', 'OPERADOR', 'CONDUCTOR') NOT NULL");
        }
    }

    public function down(): void
    {
        // No-op para mantener consistencia y evitar regresiones
    }
}