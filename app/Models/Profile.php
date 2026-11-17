<?php

// Perfil de permisos (30/10/2026): nombre + tipo base (rol) + pantallas. Lo administra solo el ADMIN.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    public const TIPOS_BASE = ['ADMIN', 'COORDINADOR_INVENTARIO', 'JEFE_BODEGA', 'BODEGUERO', 'EXPORTACIONES', 'COMERCIAL', 'GERENCIA', 'FINANZAS', 'CONTABILIDAD', 'DEPARTAMENTO', 'LIQUIDACIONES', 'LEGAL', 'TARJADOR']; // 12/11/2026: LEGAL (contratos de productores); 17/11/2026: TARJADOR (tarjas en el puerto)

    /** Pantallas de la lista que ese tipo base no puede tener (las marcadas 'solo' para otros tipos). */
    public static function pantallasVedadas(string $rol, array $pantallas): array
    {
        if ($rol === 'ADMIN') {
            return [];
        }
        // 17/11/2026: el TARJADOR (puerto) solo puede tener Tarjas; ninguna otra pantalla, ni por excepción
        if ($rol === 'TARJADOR') {
            return array_values(array_diff($pantallas, ['tarjas']));
        }
        $config = config('pantallas');

        return array_values(array_filter($pantallas, fn ($k) => isset($config[$k]['solo']) && !in_array($rol, $config[$k]['solo'], true)));
    }

    protected $fillable = ['name', 'base_role', 'description', 'screens', 'status'];

    protected $casts = [
        'screens' => 'array',
        'status'  => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
