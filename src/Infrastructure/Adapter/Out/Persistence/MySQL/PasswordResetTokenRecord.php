<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use Illuminate\Database\Eloquent\Model;

final class PasswordResetTokenRecord extends Model
{
    protected $table = 'tokens_recuperacion';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'token_hash',
        'expira_en',
        'usado_en',
        'created_at',
    ];
}
