<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// Bitácora de auditoría (30/10/2026): quién hizo qué, sobre qué registro y cuándo.
// Solo se inserta (no tiene updated_at y nunca se edita ni se borra).
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'table_name',
        'record_id',
        'changes',
        'ip',
        'route',
    ];

    protected $casts = [
        'changes'    => 'array',
        'created_at' => 'datetime:Y-m-d H:i:s',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra una acción. $usuario se pasa en el login (todavía no hay sesión en la petición).
     */
    public static function registrar(string $accion, ?string $tabla, ?int $registroId, ?array $cambios = null, ?User $usuario = null): void
    {
        $usuario ??= Auth::guard('sanctum')->user() ?? Auth::user();
        // Solo si viene de una petición de la API (en comandos de consola no hay IP ni ruta)
        $peticion = app()->bound('request') && request()->route() ? request() : null;

        static::create([
            'user_id'    => $usuario?->id,
            'user_name'  => $usuario?->name ?? 'SISTEMA',
            'action'     => $accion,
            'table_name' => $tabla,
            'record_id'  => $registroId,
            'changes'    => $cambios ?: null,
            'ip'         => $peticion?->ip(),
            'route'      => $peticion ? mb_substr($peticion->method() . ' ' . $peticion->path(), 0, 255) : null,
        ]);
    }
}
