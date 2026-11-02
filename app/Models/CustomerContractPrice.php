<?php

// Precio de un contrato de cliente para una MARCA + PRESENTACIÓN (02/11/2026)
// Ej.: contrato GT FOB → PALMS BANANAS CONVENCIONAL $7.50, PALMS BANANAS PACKED $8.00...
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerContractPrice extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora

    protected $table = 'customer_contract_prices';

    protected $fillable = ['customer_contract_id', 'brand_id', 'presentation', 'box_price'];

    protected $casts = ['box_price' => 'decimal:2'];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
}
