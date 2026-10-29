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

    /**
     * Contratos del cliente (precio por caja con vigencia). Un mismo cliente compra
     * por CONTRATO y por SPOT a la vez: el tipo va en cada línea de pedido/embarque,
     * no en el cliente. El historial de precios de contrato son estos registros;
     * el de SPOT son las líneas de pedido (precio congelado en cada una).
     */
    public function contracts()
    {
        return $this->hasMany(CustomerContract::class, 'customer_id');
    }

    /**
     * Marcas del cliente (tabla customer_brand). Un cliente puede tener varias marcas
     * y una marca puede ser de varios clientes (DOÑA ELENA es de GT y de RVI).
     * Rusia no escribe la marca en el correo: Comercial la elige entre estas al comprar la fruta.
     */
    public function brands()
    {
        return $this->belongsToMany(Brand::class, 'customer_brand')->withTimestamps();
    }
}