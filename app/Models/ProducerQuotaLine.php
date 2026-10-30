<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProducerQuotaLine extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'producer_quota_id',
        'variety',
        'quantity_cajas',
        // Fase 3 (28/10/2026): compra de Comercial
        'customer_id',     // cliente para el que se compra (GT, RVI...)
        'brand_id',        // marca (variety guarda su code, que usa el despacho)
        'official_price',  // precio oficial por caja (ministerio), ej. 7.50
        'real_price',      // precio real pagado al productor, ej. 7.10
        // Liquidación (29/10/2026)
        'negotiation_type', // CONTRATO / SPOT
        'payment_scheme',   // '100' (pago completo) / '80_20' (80 % al embarcar, 20 % después)
        // Compra por barco + marca (29/10/2026, hoja semanal de Comercial)
        'purchase_block_id',   // bloque: barco + marca + modalidad + meta
        'producer_id',         // productor real cuando se le compra a un comercial
        'farm_name',
        'mag_code',
        'executive',           // ejecutivo comercial que hizo la compra
        'cut_date',            // día de corte
        'delivery_port_id',    // puerto/terminal donde entrega
        'transport_deduction', // transporte de contenedor negociado (se descuenta al liquidar)
        'notes',
        'pickup_warehouse_id', // 29/10/2026: bodega donde retira cartón y materiales
    ];

    protected $casts = [
        'quantity_cajas' => 'integer',
        'official_price' => 'decimal:2',
        'real_price'     => 'decimal:2',
        'cut_date'       => 'date:Y-m-d',
        'transport_deduction' => 'decimal:2',
    ];

    public function block()
    {
        return $this->belongsTo(PurchaseBlock::class, 'purchase_block_id');
    }

    // Productor real (si se le compró a un comercial)
    public function producer()
    {
        return $this->belongsTo(ThirdParty::class, 'producer_id');
    }

    // Bodega donde el vendedor retira cartón y materiales para esta compra
    public function pickupWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'pickup_warehouse_id');
    }

    public function deliveryPort()
    {
        return $this->belongsTo(Port::class, 'delivery_port_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    // Tarjas (lo que llegó a puerto) de esta compra
    public function tallies()
    {
        return $this->hasMany(TallySheet::class);
    }

    public function quota()
    {
        return $this->belongsTo(ProducerQuota::class, 'producer_quota_id');
    }
}