<?php

declare(strict_types=1);

namespace App\Domain\Package;

use DateTimeImmutable;

/**
 * Fecha Estimada de Entrega = Fecha de Registro + 2 días (enunciado, "Cotización y Tiempos de Entrega":
 * regla operativa de entrega en 24 a 48 horas, todos los días). Se calcula desde la fecha de ingreso
 * guardada, así todos los paquetes la tienen sin duplicar el dato en la base.
 */
final class DeliveryEstimate
{
    public const DAYS = 2;

    public static function from(DateTimeImmutable $registeredAt): DateTimeImmutable
    {
        return $registeredAt->modify('+' . self::DAYS . ' days');
    }
}
