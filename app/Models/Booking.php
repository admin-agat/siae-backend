<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_number',
        'shipping_line_id',
        'vessel_id',
        'voyage_number',
        'week',
        'year',
        'departure_week',
        'departure_year',
        'entry_date',
        'eta',
        'status',
        'destination_id',
        'port_id',
        'weekly_quota',
        'estimated_departure',
        'boxes_quantity',
    ];

    protected $casts = [
        'status' => 'boolean',
        'entry_date' => 'date',
        'eta' => 'date',
        'estimated_departure' => 'date',
    ];

    public function shippingLine()
    {
        return $this->belongsTo(ShippingLine::class);
    }

    public function vessel()
    {
        return $this->belongsTo(Vessel::class);
    }

    // Nuevos métodos de relación:
    public function destination()
    {
        return $this->belongsTo(Destination::class);
    }

    public function port()
    {
        return $this->belongsTo(Port::class);
    }
}