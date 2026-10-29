<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'code',
        'registration_date',
        'departure_date',
        'vessel_name',
        'shipping_line_id',
        'departure_port_id',
        'week_start',
        'week_end',
        'year',
        'comment',
        'created_by',
        'status',
        // Fase 2 (28/10/2026): el barco pertenece a un pedido semanal
        'customer_order_id',
        'vessel_id',
        'destination_port_id',
        'eta',
        'voyage', // 29/10/2026: N° de viaje (UNI1401); el barco (vessel) queda solo con su nombre
        'loading_from', // ventana de carga (cierre de embarque); departure_date es el zarpe
        'loading_to',
    ];

    protected $casts = [
        'status' => 'boolean',
        'registration_date' => 'date:Y-m-d',
        'departure_date' => 'date:Y-m-d',
        'eta' => 'date:Y-m-d',
        'loading_from' => 'date:Y-m-d',
        'loading_to' => 'date:Y-m-d',
    ];

    public function order()
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class);
    }

    public function destinationPort()
    {
        return $this->belongsTo(Port::class, 'destination_port_id');
    }

    public function shippingLine()
    {
        return $this->belongsTo(ShippingLine::class);
    }

    public function departurePort()
    {
        return $this->belongsTo(Port::class, 'departure_port_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(ShipmentLine::class);
    }

    // Cierre de embarque: bookings con lo realmente embarcado
    public function bookings()
    {
        return $this->hasMany(ShipmentBooking::class);
    }
}