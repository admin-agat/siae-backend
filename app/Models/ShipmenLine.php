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
    ];

    protected $casts = [
        'status' => 'boolean',
        'registration_date' => 'date',
        'departure_date' => 'date',
    ];

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
}