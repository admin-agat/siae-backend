<?php
namespace App\Models;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
class User extends Authenticatable
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

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
        'role', // tipo base (lo pone el perfil): ADMIN, COORDINADOR_INVENTARIO, JEFE_BODEGA, BODEGUERO, EXPORTACIONES, COMERCIAL
        'phone',
        'status', // false = desactivado: no puede iniciar sesión
        // 30/10/2026: permisos por persona
        'profile_id',
        'boss_id',         // "asistente de"
        'screens_added',   // pantallas de más, solo para esta persona
        'screens_removed', // pantallas de menos, solo para esta persona
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
            'status' => 'boolean',
            'screens_added' => 'array',
            'screens_removed' => 'array',
        ];
    }

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    public function boss()
    {
        return $this->belongsTo(User::class, 'boss_id');
    }

    /**
     * Pantallas que el usuario puede abrir (30/10/2026): las de su perfil + las agregadas − las quitadas.
     * El ADMIN tiene todas. Un perfil desactivado no da ninguna (solo quedan las agregadas a la persona).
     * Sin perfil (ej. creado fuera de la pantalla Usuarios): las pantallas iniciales de su tipo base.
     */
    public function pantallas(): array
    {
        $todas = array_keys(config('pantallas'));
        if ($this->esAdmin()) {
            return $todas;
        }
        $perfil = match (true) {
            !$this->profile_id => array_keys(array_filter(config('pantallas'), fn ($p) => in_array($this->role, $p['roles'], true))),
            (bool) $this->profile?->status => $this->profile->screens ?? [],
            default => [],
        };
        $efectivas = array_diff(array_unique(array_merge($perfil, $this->screens_added ?? [])), $this->screens_removed ?? []);

        return array_values(array_intersect($todas, $efectivas)); // en el orden del menú, sin claves viejas
    }

    public function puede(string ...$pantallas): bool
    {
        return $this->esAdmin() || (bool) array_intersect($pantallas, $this->pantallas());
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