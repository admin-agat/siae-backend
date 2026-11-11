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
        'farm_id',             // 04/11/2026: finca del catálogo (farm_name/mag_code = copia al comprar)
        'farm_name',
        'mag_code',
        'executive',           // ejecutivo comercial que hizo la compra
        'cut_date',            // día de corte
        'delivery_port_id',    // puerto/terminal donde entrega
        'transport_deduction', // transporte de contenedor negociado (se descuenta al liquidar)
        'transport_recognized_rate', // 11/11/2026: $/caja que se le reconoce por transporte (se suma al liquidar, por tarja)
        'notes',
        'pickup_warehouse_id', // 29/10/2026: bodega donde retira cartón y materiales
        // 06/11/2026: liquidación como el Excel
        'contract_id',         // contrato del vendedor (solo compras de CONTRATO)
        'contract_part',       // 0 = CONTRATO 1, 1 = CONTRATO 1.1, 2 = 1.2… (liquidación partida)
        // 10/11/2026: Comercial envía la compra a bodega para que despache los materiales
        'sent_to_warehouse_at',
        'sent_to_warehouse_by',
        'changed_after_sent_at', // cambiaron cajas, marca o bodega después de enviarla
    ];

    protected $casts = [
        'quantity_cajas' => 'integer',
        'official_price' => 'decimal:2',
        'real_price'     => 'decimal:2',
        'cut_date'       => 'date:Y-m-d',
        'transport_deduction' => 'decimal:2',
        'transport_recognized_rate' => 'decimal:4',
        'contract_part'  => 'integer',
        'sent_to_warehouse_at'  => 'datetime',
        'changed_after_sent_at' => 'datetime',
    ];

    // Quién la envió a bodega
    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_to_warehouse_by');
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function block()
    {
        return $this->belongsTo(PurchaseBlock::class, 'purchase_block_id');
    }

    // Productor real (si se le compró a un comercial)
    public function producer()
    {
        return $this->belongsTo(ThirdParty::class, 'producer_id');
    }

    // Finca de donde sale la fruta (del productor real o, en compra directa, del vendedor)
    public function farm()
    {
        return $this->belongsTo(Farm::class);
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