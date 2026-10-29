<?php

// Modelo ShipmentLine → tabla shipment_lines
// Cuántas cajas de UNA línea del pedido semanal van en UN barco (shipment).
// 28/10/2026: reemplaza a app/Models/ShipmenLine.php, que tenía mal el nombre y era una
// copia de Shipment — la clase ShipmentLine no existía y ShipmentController fallaba.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentLine extends Model
{
    protected $table = 'shipment_lines';

    // Valores permitidos por el CHECK de la base
    public const TIPOS = ['CAJA', 'CONTENEDOR'];
    public const NEGOTIATION_TYPES = ['CONTRATO', 'SPOT'];

    protected $fillable = [
        'shipment_id',
        'customer_order_line_id',
        'customer_id',
        'week',
        'destination_port_id',
        'arrival_date',
        'sku_id',
        'booking_id',
        'type',
        'container_quantity',
        'box_quantity',
        'incoterm',
        'negotiation_type',
        'status',
    ];

    protected $casts = [
        'box_quantity'       => 'integer',
        'container_quantity' => 'integer',
        'arrival_date'       => 'date:Y-m-d',
        'status'             => 'boolean',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function orderLine()
    {
        return $this->belongsTo(CustomerOrderLine::class, 'customer_order_line_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function destination()
    {
        return $this->belongsTo(Port::class, 'destination_port_id');
    }

    // sku(): pendiente — el modelo Sku aún no existe (tabla skus vacía, módulo SKU/Recetas por construir)

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
