<?php

// Modelo CustomerOrderLine → tabla customer_order_lines
// Un renglón del pedido semanal. negotiation_type va AQUÍ (no en el cliente):
//   CONTRATO → box_price se copia del contrato vigente (customer_contract_id) y queda congelado
//   SPOT     → box_price se escribe a mano (puede llegar después)
// Ubicación: app/Models/CustomerOrderLine.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrderLine extends Model
{
    protected $table = 'customer_order_lines';

    protected $fillable = [
        'customer_order_id',
        'customer_id',
        'boxes',
        'incoterm',
        'cargo_type',
        'containers_qty',
        'boxes_per_container',
        'departure_port_id',
        'negotiation_type',
        'customer_contract_id',
        'box_price',
        'order_number',
        'notes',
        'status',
    ];

    protected $casts = [
        'boxes'               => 'integer',
        'containers_qty'      => 'integer',
        'boxes_per_container' => 'integer',
        'box_price'           => 'decimal:2',
        'status'              => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function departurePort()
    {
        return $this->belongsTo(Port::class, 'departure_port_id');
    }

    public function contract()
    {
        return $this->belongsTo(CustomerContract::class, 'customer_contract_id');
    }

    // Reparto de esta línea en barcos (Fase 2). Una línea puede ir en varios barcos.
    public function shipmentLines()
    {
        return $this->hasMany(ShipmentLine::class, 'customer_order_line_id');
    }
}
