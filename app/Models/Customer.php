<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'customer_code',      // CUST-00001, lo genera el backend
        'third_party_id',     // tercero base (UNIQUE: un tercero = un cliente)
        'country',
        'contact_name',
        'negotiation_type',   // CONTRATO | SPOT (CHECK en la base)
        'status',
    ];

    // Garantiza true/false en el JSON
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Un Customer siempre está ligado a un Tercero base (Party Pattern:
     * el Tercero guarda los datos generales — nombre, identificación —
     * y Customer agrega los campos específicos de cliente internacional).
     *
     * OJO: en el JSON esta relación sale como "third_party" (snake_case),
     * no como "thirdParty". El frontend debe leer customer.third_party.
     */
    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }
}