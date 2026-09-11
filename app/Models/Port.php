<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Port extends Model
{
    protected $fillable = ['name', 'code', 'city', 'status'];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function tariffItems()
    {
        return $this->hasMany(PortTariffItem::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}