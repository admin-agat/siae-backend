<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Contrato del productor o comercializadora (hoja "Base Productores" del Excel de liquidaciones).
// 06/11/2026: N° (CONTRATO 1, 2…), cupo semanal, precio y si se le cobra transferencia, evaluadores,
// materiales y transporte (si dice NO, ese descuento no se aplica al liquidar sus compras).
class Contract extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $table = 'contracts';

    protected $fillable = [
        'third_party_id', 'magap_contract_number', 'contracted_quantity',
        'harvest_day', 'start_date', 'end_date', 'status',
        'number', 'price', 'weekly_boxes', 'discount_condition',
        'charge_transfer', 'charge_evaluators', 'charge_materials', 'charge_transport',
        'notes', 'updated_by',
    ];

    public const CONDICIONES = ['CON_DESCUENTO', 'SIN_DESCUENTO'];

    protected $casts = [
        'status' => 'boolean',
        'contracted_quantity' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'number' => 'integer',
        'price' => 'decimal:2',
        'weekly_boxes' => 'integer',
        'charge_transfer' => 'boolean',
        'charge_evaluators' => 'boolean',
        'charge_materials' => 'boolean',
        'charge_transport' => 'boolean',
    ];

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }
}
