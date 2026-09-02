<?php
namespace App\Models;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // Rol del usuario: ADMIN, JEFE_BODEGA o COORDINADOR_INVENTARIO — controla acceso a módulos
    ];
    /**
     * Los atributos que deben ocultarse al serializar (ej. en respuestas JSON).
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];
    /**
     * Obtiene los atributos que deben convertirse (casts).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Helper: true si el usuario es ADMIN (acceso total al sistema).
     */
    public function esAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }

    /**
     * Helper: true si el usuario tiene un rol restringido al módulo de bodegas
     * (JEFE_BODEGA o COORDINADOR_INVENTARIO).
     */
    public function esRolBodega(): bool
    {
        return in_array($this->role, ['JEFE_BODEGA', 'COORDINADOR_INVENTARIO']);
    }


    
}