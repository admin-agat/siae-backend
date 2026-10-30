<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Port extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    // port_type: SALIDA (Ecuador: GUAYAQUIL, PUERTO BOLÍVAR) | DESTINO (ST PETERSBURG...)
    // code/city existen desde la migración 2026_10_28_000001 (antes el modelo los pedía sin columna)
    protected $fillable = ['name', 'code', 'city', 'country', 'port_type', 'status'];

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
