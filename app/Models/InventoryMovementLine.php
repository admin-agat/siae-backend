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
        // CORREGIDO — el controlador ya la enviaba (confirmTransfer), pero al no
        // estar aquí Laravel la descartaba en silencio. Requiere el Paso 6.
        'reception_note',
        // NUEVO — Despacho de Materiales: lo que calculó la fórmula,
        // para comparar contra "quantity" (lo realmente entregado).
        'recommended_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        // 4 decimales: hay insumos con costo de fracción de centavo
        // (con decimal:2 se veían truncados en pantalla y en la liquidación)
        'unit_cost' => 'decimal:4',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'recommended_quantity' => 'decimal:2',
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