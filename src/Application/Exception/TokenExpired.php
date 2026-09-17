<?php

declare(strict_types=1);

namespace App\Application\Exception;

use RuntimeException;

final class TokenExpired extends RuntimeException
{
}
