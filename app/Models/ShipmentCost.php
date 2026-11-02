<?php

// Tarifas de un viaje (02/11/2026): lo que cobra la nave por contenedor (ventas CFR/CIF) y el flete
// total de la carga bajo cubierta. Lo registra Finanzas.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentCost extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora

    protected $table = 'shipment_costs';

    protected $fillable = ['shipment_id', 'container_rate', 'under_deck_amount', 'notes', 'updated_by'];

    protected $casts = [
        'container_rate'    => 'decimal:2',
        'under_deck_amount' => 'decimal:2',
    ];
}
