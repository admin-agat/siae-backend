<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovementLine extends Model
{
    protected $fillable = [
        'inventory_movement_id',
        'supply_id',
        'quantity',
        'unit_cost',
        'discount',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function movement()
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }
}