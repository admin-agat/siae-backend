<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Destination extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = ['name', 'status'];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}