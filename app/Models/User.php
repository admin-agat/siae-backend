<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    // Traits necesarios para autenticación y tokens
    use Notifiable, HasApiTokens;

    // Campos que se pueden llenar masivamente
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    // Campos ocultos en respuestas JSON
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Casting de tipos
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }
}