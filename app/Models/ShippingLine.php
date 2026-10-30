<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingLine extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $table = 'shipping_lines';

    protected $fillable = [
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}