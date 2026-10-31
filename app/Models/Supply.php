<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supply extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'supply_category_id',
        'code',
        'name',
        'description',
        'unit',
        'cost',               // costo promedio ponderado (App\Services\CostoPromedio), 31/10/2026
        'last_purchase_cost', // último precio de compra, solo referencia
        'status',
    ];

    protected $casts = [
        // 6 decimales: con decimal:2 un costo de $0.0456 se leía como $0.05 (y así se despachaba)
        'cost' => 'decimal:6',
        'last_purchase_cost' => 'decimal:6',
        'status' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(SupplyCategory::class, 'supply_category_id');
    }

    public function thirdParties()
{
    return $this->belongsToMany(\App\Models\ThirdParty::class, 'third_party_supplies');
}
}