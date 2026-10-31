<?php

declare(strict_types=1);

namespace App\Domain\Package;

/** Validacion compartida por el registro y la correccion de una direccion. */
final class DeliveryAddress
{
    public const FIELDS = ['ubigeo_distrito', 'tipo_via', 'nombre_via', 'numero', 'manzana', 'lote', 'referencia', 'lat', 'lng'];

    /** @param array<string, mixed> $input @return array<string, string|null> */
    public static function fromArray(array $input): array
    {
        $errors = [];
        foreach (array_diff(array_keys($input), self::FIELDS) as $field) {
            $errors["direccion.{$field}"] = 'Campo no permitido';
        }
        $address = [];
        foreach (['ubigeo_distrito' => 6, 'tipo_via' => 20, 'nombre_via' => 120, 'numero' => 10, 'manzana' => 10, 'lote' => 10, 'referencia' => 255] as $field => $max) {
            $required = in_array($field, ['ubigeo_distrito', 'tipo_via', 'nombre_via'], true);
            $value = $input[$field] ?? null;
            $address[$field] = null;
            if ($value === null && !$required) {
                continue;
            }
            if (!is_string($value)) {
                $errors["direccion.{$field}"] = 'Debe ser texto';
                continue;
            }
            $value = trim($value);
            if ($value === '' && !$required) {
                continue;
            }
            if (!preg_match('/^.{1,' . $max . '}$/us', $value)) {
                $errors["direccion.{$field}"] = "Debe tener entre 1 y {$max} caracteres";
            }
            $address[$field] = $value;
        }
        if (!preg_match('/^[0-9]{6}$/D', $address['ubigeo_distrito'] ?? '')) {
            $errors['direccion.ubigeo_distrito'] = 'Debe ser un UBIGEO de 6 digitos';
        }
        $address['lat'] = $address['lng'] = null;
        $hasLat = ($input['lat'] ?? null) !== null;
        $hasLng = ($input['lng'] ?? null) !== null;
        if ($hasLat !== $hasLng) {
            $errors['direccion.' . ($hasLat ? 'lng' : 'lat')] = 'Envie latitud y longitud juntas';
        } elseif ($hasLat) {
            foreach (['lat' => Coordinates::LIMA_LAT, 'lng' => Coordinates::LIMA_LNG] as $key => [$min, $max]) {
                $value = $input[$key];
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    $errors["direccion.{$key}"] = 'Debe ser un numero';
                } elseif ($value < $min || $value > $max) {
                    $errors["direccion.{$key}"] = 'El punto debe estar dentro de Lima Metropolitana o Callao';
                } else {
                    $address[$key] = number_format(round((float) $value, 6), 6, '.', '');
                }
            }
        }
        if ($errors !== []) {
            throw new InvalidPackageData($errors);
        }

        return $address;
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $changes @return array<string, string|null> */
    public static function patch(array $current, array $changes): array
    {
        $errors = [];
        if ($changes === [] || array_is_list($changes)) {
            $errors['direccion'] = 'Envie al menos un campo de la direccion';
        }
        foreach (array_diff(array_keys($changes), self::FIELDS) as $field) {
            $errors["direccion.{$field}"] = 'Solo se pueden editar campos de la direccion';
        }
        if (array_key_exists('lat', $changes) !== array_key_exists('lng', $changes)) {
            $errors['direccion.coordenadas'] = 'Envie latitud y longitud juntas';
        }
        if ($errors !== []) {
            throw new InvalidPackageData($errors);
        }

        // Una ubicacion anterior no debe sobrevivir a una direccion fisica distinta.
        if (!array_key_exists('lat', $changes)) {
            foreach (['ubigeo_distrito', 'tipo_via', 'nombre_via', 'numero', 'manzana', 'lote'] as $field) {
                $newValue = $changes[$field] ?? null;
                $oldValue = $current[$field] ?? null;
                if (is_string($newValue)) {
                    $newValue = trim($newValue);
                    $newValue = $newValue === '' ? null : $newValue;
                }
                if (is_string($oldValue)) {
                    $oldValue = trim($oldValue);
                    $oldValue = $oldValue === '' ? null : $oldValue;
                }
                if (array_key_exists($field, $changes) && $newValue !== $oldValue) {
                    $current['lat'] = $current['lng'] = null;
                    break;
                }
            }
        }

        return self::fromArray(array_replace($current, $changes));
    }
}
