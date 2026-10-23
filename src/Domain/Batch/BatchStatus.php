<?php

declare(strict_types=1);

namespace App\Domain\Batch;

enum BatchStatus: string
{
    case EnPreparacion = 'EN_PREPARACION';
    case Asignado = 'ASIGNADO';
    case EnRuta = 'EN_RUTA';
    case Cerrado = 'CERRADO';
}
