<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThirdParty extends Model
{
    protected $table = 'third_parties';

    protected $fillable = [
        'name', 'type', 'identification', 'zone', 'phone', 'email', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Datos extendidos de cliente (solo si type = 'customer').
     */
    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    /**
     * Contratos asociados a este tercero (típicamente un productor).
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