<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class InitialAdministratorSeeder extends AbstractSeed
{
    public function run(): void
    {
        $names = $this->requiredEnvironmentValue('INITIAL_ADMIN_NOMBRES');
        $email = strtolower($this->requiredEnvironmentValue('INITIAL_ADMIN_CORREO'));
        $password = $this->requiredEnvironmentValue('INITIAL_ADMIN_PASSWORD');

        if (strlen($names) > 150) {
            throw new RuntimeException('INITIAL_ADMIN_NOMBRES no puede superar 150 caracteres');
        }

        if (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('INITIAL_ADMIN_CORREO no tiene un formato válido');
        }

        if (!$this->hasStrongPassword($password)) {
            throw new RuntimeException(
                'INITIAL_ADMIN_PASSWORD debe tener entre 12 y 72 caracteres, mayúscula, minúscula, número y símbolo',
            );
        }

        $connection = $this->getAdapter()->getConnection();
        $statement = $connection->prepare('SELECT id FROM usuarios WHERE correo = :correo LIMIT 1');
        $statement->execute(['correo' => $email]);

        if ($statement->fetch() !== false) {
            return;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('No fue posible generar el hash de la contraseña inicial');
        }

        $this->table('usuarios')->insert([
            'nombres' => $names,
            'correo' => $email,
            'rol' => 'Admin',
            'password_hash' => $passwordHash,
            'debe_cambiar_password' => false,
            'activo' => true,
        ])->saveData();
    }

    private function requiredEnvironmentValue(string $key): string
    {
        $value = $_ENV[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('La variable de entorno %s es obligatoria', $key));
        }

        return $value;
    }

    private function hasStrongPassword(string $password): bool
    {
        return strlen($password) >= 12
            && strlen($password) <= 72
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }
}
