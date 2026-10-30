<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Bloque de compra de la semana: barco + marca + cliente + modalidad + puerto + META de cajas.
// Las compras de Comercial (producer_quota_lines) cuelgan de un bloque.
class PurchaseBlock extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    public const MODALIDADES = ['BAJO_CUBIERTA', 'CONTENEDOR'];

    protected $fillable = [
        'customer_order_id',
        'shipment_id',
        'brand_id',
        'customer_id',
        'cargo_mode',
        'departure_port_id',
        'target_boxes',
        'notes',
    ];

    protected $casts = [
        'target_boxes' => 'integer',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function departurePort()
    {
        return $this->belongsTo(Port::class, 'departure_port_id');
    }

    public function purchases()
    {
        return $this->hasMany(ProducerQuotaLine::class);
    }
}
