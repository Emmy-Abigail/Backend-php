<?php

declare(strict_types=1);

namespace App\Domain\Package;

use InvalidArgumentException;

final readonly class PackageDetails
{
    /** @param array<string, string|null> $sender @param array<string, string|null> $recipient @param array<string, string|null> $address */
    private function __construct(
        public array $sender,
        public array $recipient,
        public array $address,
        public int $weightUnits,
        public int $lengthUnits,
        public int $widthUnits,
        public int $heightUnits,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $errors = [];
        $allowed = ['remitente', 'destinatario', 'direccion', 'peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm'];
        foreach (array_diff(array_keys($input), $allowed) as $field) {
            $errors[(string) $field] = 'Campo no permitido; los datos operativos los establece el sistema';
        }
        $groups = [];
        foreach (['remitente', 'destinatario', 'direccion'] as $group) {
            $groups[$group] = is_array($input[$group] ?? null) ? $input[$group] : [];
            if (!is_array($input[$group] ?? null)) {
                $errors[$group] = 'Debe ser un objeto';
            }
        }
        $text = static function (string $group, string $key, int $max, bool $required = true) use ($groups, &$errors): ?string {
            $value = $groups[$group][$key] ?? null;
            if ($value === null && !$required) {
                return null;
            }
            if (!is_string($value)) {
                $errors["{$group}.{$key}"] = 'Debe ser texto';
                return null;
            }
            $value = trim($value);
            if (!$required && $value === '') {
                return null;
            }
            if (!preg_match('/^.{1,' . $max . '}$/us', $value)) {
                $errors["{$group}.{$key}"] = "Debe tener entre 1 y {$max} caracteres";
            }
            return $value;
        };
        $email = static function (string $group) use ($text, &$errors): ?string {
            $value = $text($group, 'correo', 150, false);
            if ($value === null || isset($errors["{$group}.correo"])) {
                return null;
            }
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors["{$group}.correo"] = 'Debe ser un correo valido';
                return null;
            }
            return strtolower($value);
        };
        $sender = [
            'tipo_documento' => $text('remitente', 'tipo_documento', 3),
            'numero_documento' => $text('remitente', 'numero_documento', 11),
            'nombres' => $text('remitente', 'nombres', 150),
            'celular' => $text('remitente', 'celular', 20),
            'correo' => $email('remitente'),
        ];
        $recipient = [
            'nombres' => $text('destinatario', 'nombres', 150),
            'dni' => $text('destinatario', 'dni', 8),
            'celular' => $text('destinatario', 'celular', 20),
            'correo' => $email('destinatario'),
        ];
        $address = [];
        try {
            $address = DeliveryAddress::fromArray($groups['direccion']);
        } catch (InvalidPackageData $exception) {
            $errors = array_replace($errors, $exception->errors);
        }
        foreach (['remitente' => $sender, 'destinatario' => $recipient] as $group => $fields) {
            foreach (array_diff(array_keys($groups[$group]), array_keys($fields)) as $field) {
                $errors["{$group}.{$field}"] = 'Campo no permitido';
            }
        }
        $digits = match ($sender['tipo_documento']) {
            'DNI' => 8,
            'RUC' => 11,
            default => null,
        };
        if ($digits === null) {
            $errors['remitente.tipo_documento'] = 'Debe ser DNI o RUC';
        } elseif (!preg_match('/^[0-9]{' . $digits . '}$/D', $sender['numero_documento'] ?? '')) {
            $errors['remitente.numero_documento'] = "Debe tener {$digits} digitos";
        }
        if (!preg_match('/^[0-9]{8}$/D', $recipient['dni'] ?? '')) {
            $errors['destinatario.dni'] = 'Debe tener 8 digitos';
        }
        foreach (['remitente', 'destinatario'] as $group) {
            $phone = $group === 'remitente' ? $sender['celular'] : $recipient['celular'];
            $phone = preg_replace('/[\s-]+/', '', $phone ?? '') ?? '';
            if (!preg_match('/^(?:\+?51)?(9[0-9]{8})$/D', $phone, $matches)) {
                $errors["{$group}.celular"] = 'Debe ser un celular peruano de 9 digitos; prefijo +51 opcional';
            } elseif ($group === 'remitente') {
                $sender['celular'] = $matches[1];
            } else {
                $recipient['celular'] = $matches[1];
            }
        }
        $measurements = [];
        foreach (['peso_kg' => [2, 10, 5000], 'largo_cm' => [1, 100, 1500], 'ancho_cm' => [1, 100, 1500], 'alto_cm' => [1, 100, 1500]] as $field => [$scale, $min, $max]) {
            try {
                $units = DecimalQuantity::units($input[$field] ?? null, $scale);
                if ($units < $min || $units > $max) {
                    throw new InvalidArgumentException($field === 'peso_kg' ? 'Debe estar entre 0.1 y 50 kg' : 'Debe estar entre 10 y 150 cm');
                }
                $measurements[$field] = $units;
            } catch (InvalidArgumentException $exception) {
                $errors[$field] = $exception->getMessage();
            }
        }
        if ($errors !== []) {
            throw new InvalidPackageData($errors);
        }

        return new self($sender, $recipient, $address, $measurements['peso_kg'], $measurements['largo_cm'], $measurements['ancho_cm'], $measurements['alto_cm']);
    }

    /** @return array<string, string> */
    public function measurements(): array
    {
        return [
            'peso_kg' => DecimalQuantity::format($this->weightUnits, 2),
            'largo_cm' => DecimalQuantity::format($this->lengthUnits, 1),
            'ancho_cm' => DecimalQuantity::format($this->widthUnits, 1),
            'alto_cm' => DecimalQuantity::format($this->heightUnits, 1),
        ];
    }
}
