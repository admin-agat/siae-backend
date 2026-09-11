<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'customer_code',
        'third_party_id',
        'country',
        'contact_name',
        'negotiation_type',
        'status',
    ];

    /**
     * Un Customer siempre está ligado a un Tercero base (Party Pattern:
     * el Tercero guarda los datos generales — nombre, identificación —
     * y Customer agrega los campos específicos de cliente internacional).
     */
    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class, 'third_party_id');
    }
}