<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Material cobrado en la liquidación: (retirado − devuelto) × costo promedio del retiro
class SettlementMaterial extends Model
{
    protected $fillable = [
        'settlement_id',
        'supply_id',
        'dispatched_qty',
        'returned_qty',
        'charged_qty',
        'unit_cost',
        'amount',
    ];

    protected $casts = [
        'dispatched_qty' => 'decimal:2',
        'returned_qty'   => 'decimal:2',
        'charged_qty'    => 'decimal:2',
        'unit_cost'      => 'decimal:4',
        'amount'         => 'decimal:2',
    ];

    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }
}
