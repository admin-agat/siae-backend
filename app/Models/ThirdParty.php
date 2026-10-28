<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThirdParty extends Model
{
    protected $table = 'third_parties';

    // 'address' agregado (columna nueva, opcional). Si no está aquí,
    // Laravel ignora la dirección al guardar sin dar ningún error.
    protected $fillable = [
        'name', 'type', 'identification', 'zone', 'phone', 'email', 'address', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Datos extendidos de cliente (solo si type = 'CLIENTE').
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * Contratos MAGAP asociados a este tercero (típicamente un productor).
     * Tabla contracts (2025). No confundir con customer_contracts (contratos de venta con clientes).
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Fincas asociadas a este tercero (si es productor).
     */
    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }
}
