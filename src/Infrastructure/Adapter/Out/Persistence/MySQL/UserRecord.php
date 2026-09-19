<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use Illuminate\Database\Eloquent\Model;

final class UserRecord extends Model
{
    protected $table = 'usuarios';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'nombres',
        'correo',
        'telefono',
        'password_hash',
        'rol',
        'debe_cambiar_password',
        'activo',
    ];

    protected $hidden = [
        'password_hash',
    ];
}