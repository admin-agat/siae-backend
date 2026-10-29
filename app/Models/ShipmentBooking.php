<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fila del CIERRE DE EMBARQUE (hoja "Asignación de carga WK-xx" de Logística):
// un booking del barco con sus documentos y lo REALMENTE embarcado.
class ShipmentBooking extends Model
{
    public const MODALIDADES = ['BODEGA', 'CONTENEDOR'];
    public const TIPOS = ['CONTRATO', 'SPOT'];

    protected $fillable = [
        'shipment_id',
        'customer_id',
        'brand_id',
        'negotiation_type',
        'cargo_mode',
        'holds',
        'containers_qty',
        'booking_number',
        'dae_number',
        'bl_number',
        'aucp',
        'order_ref',
        'forma_a',
        'consignee_name',
        'consignee_address',
        'notify_name',
        'notify_address',
        'quota_boxes',
        'shipped_boxes',
        'port_entry_boxes',
        'sample_boxes',
        'net_weight_per_box',
        'gross_weight_per_box',
        'dae_status',
        'arrival_note',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'containers_qty'       => 'integer',
        'quota_boxes'          => 'integer',
        'shipped_boxes'        => 'integer',
        'port_entry_boxes'     => 'integer',
        'sample_boxes'         => 'integer',
        'net_weight_per_box'   => 'decimal:2',
        'gross_weight_per_box' => 'decimal:2',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
}
