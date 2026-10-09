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
        'dni',
        'correo',
        'telefono',
        'rol',
        'id_sede',
        'id_tipo_vehiculo',
        'placa',
        'password_hash',
        'debe_cambiar_password',
        'password_changed_at',
        'activo',
    ];

    protected $hidden = [
        'password_hash',
    ];
}