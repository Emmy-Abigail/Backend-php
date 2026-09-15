<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use Illuminate\Database\Eloquent\Model;

final class UserRecord extends Model
{
    protected $table = 'usuarios';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $hidden = [
        'password_hash',
    ];
}
