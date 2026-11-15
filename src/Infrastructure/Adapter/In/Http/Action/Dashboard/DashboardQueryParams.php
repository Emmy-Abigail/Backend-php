<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Dashboard;

use DateTimeImmutable;

/**
 * Valida los filtros comunes del dashboard: desde y hasta (obligatorios, AAAA-MM-DD, días de Lima)
 * e id_sede (opcional). Sigue el mismo formato de errores 422 que el listado de paquetes.
 */
final readonly class DashboardQueryParams
{
    public const MAX_RANGE_DAYS = 366;

    /** @param array<string, string> $errors */
    private function __construct(
        public ?string $from,
        public ?string $to,
        public ?int $sedeId,
        public array $errors,
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function fromQuery(array $params): self
    {
        $errors = [];

        foreach (['desde', 'hasta', 'id_sede'] as $field) {
            if (array_key_exists($field, $params) && !is_string($params[$field])) {
                $errors[$field] = 'Debe ser un texto, no una lista ni un objeto';
            }
        }

        $from = self::text($params['desde'] ?? null);
        $to = self::text($params['hasta'] ?? null);
        foreach (['desde' => $from, 'hasta' => $to] as $field => $value) {
            if (isset($errors[$field])) {
                continue;
            }
            if ($value === null) {
                $errors[$field] = 'Es obligatorio (AAAA-MM-DD)';
            } elseif (self::date($value) === null) {
                $errors[$field] = 'Use el formato AAAA-MM-DD';
            }
        }

        if (!isset($errors['desde']) && !isset($errors['hasta']) && $from !== null && $to !== null) {
            $fromDate = self::date($from);
            $toDate = self::date($to);
            if ($from > $to) {
                $errors['desde'] = 'La fecha "desde" no puede ser posterior a "hasta"';
            } elseif ($fromDate !== null && $toDate !== null && $fromDate->diff($toDate)->days >= self::MAX_RANGE_DAYS) {
                $errors['hasta'] = 'El rango no puede superar ' . self::MAX_RANGE_DAYS . ' días';
            }
        }

        $sedeId = null;
        if (!isset($errors['id_sede'])) {
            $rawSede = self::text($params['id_sede'] ?? null);
            if ($rawSede !== null) {
                $sedeId = self::positiveInt($rawSede);
                if ($sedeId === null) {
                    $errors['id_sede'] = 'Debe ser un entero mayor o igual a 1';
                }
            }
        }

        return new self($from, $to, $sedeId, $errors);
    }

    public static function positiveInt(mixed $value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $value = ltrim($value, '0');
        // Se limita la longitud antes del cast para evitar que PHP sature enteros enormes.
        if ($value === '' || strlen($value) > 9) {
            return null;
        }

        return (int) $value;
    }

    public function hasDateErrors(): bool
    {
        return isset($this->errors['desde']) || isset($this->errors['hasta']);
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
