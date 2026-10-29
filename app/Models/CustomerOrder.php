<?php

// Modelo CustomerOrder → tabla customer_orders
// Cabecera del pedido semanal que envía Rusia por correo (un solo pedido por semana/año).
// Ubicación: app/Models/CustomerOrder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOrder extends Model
{
    protected $table = 'customer_orders';

    protected $fillable = [
        'week',
        'year',
        'expected_boxes', // total de cajas de la semana; las líneas deben sumar exactamente esto
        'received_at',
        'comment',
        'status',
        'created_by',
    ];

    protected $casts = [
        'week'        => 'integer',
        'year'        => 'integer',
        'expected_boxes' => 'integer',
        'received_at' => 'date:Y-m-d',
        'status'      => 'boolean',
    ];

    // Renglones del correo (cliente, cajas, incoterm, carga, puerto, modalidad, precio)
    public function lines()
    {
        return $this->hasMany(CustomerOrderLine::class, 'customer_order_id');
    }

    // Barcos en los que Logística distribuyó este pedido (Fase 2)
    public function shipments()
    {
        return $this->hasMany(Shipment::class, 'customer_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
