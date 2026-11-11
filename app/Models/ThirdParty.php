<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThirdParty extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $table = 'third_parties';

    // 'address' agregado (columna nueva, opcional). Si no está aquí,
    // Laravel ignora la dirección al guardar sin dar ningún error.
    protected $fillable = [
        'name', 'type', 'identification', 'zone', 'phone', 'email', 'address', 'status',
        // 06/11/2026: los pone Liquidaciones (Contratos y retención), no el formulario de Terceros
        'retention_regime',   // NORMAL (tramos por cajas) | BPA (tasa fija) | NO_RETENER (asociaciones y cooperativas)
        'payment_short_name', // nombre corto en la referencia de pago al banco (ej. JEHOVA JIREH)
        'magap_registered_boxes', // 11/11/2026: cajas semanales registradas en el MAG (Excel de contratos)
    ];

    public const REGIMENES = ['NORMAL', 'BPA', 'NO_RETENER'];

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
     * Contratos del productor o comercializadora: N°, cupo semanal, precio y qué se le cobra (06/11/2026).
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

    /**
     * 05/11/2026: cuenta bancaria para pagarle (solo Contabilidad la ve; no sale en /third-parties).
     */
    public function bankAccount(): HasOne
    {
        return $this->hasOne(ThirdPartyBankAccount::class);
    }
}
