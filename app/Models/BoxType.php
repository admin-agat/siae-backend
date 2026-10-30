<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tipo de caja de banano (22XU, 208...). El precio oficial del MAG depende del tipo de caja.
class BoxType extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
