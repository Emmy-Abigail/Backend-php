<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use DateTimeImmutable;
use DateTimeZone;

/** Las fechas se guardan en UTC y se muestran en ISO 8601 con la hora de Lima. */
final class LimaTime
{
    public static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('America/Lima'))->format(DATE_ATOM);
    }
}
