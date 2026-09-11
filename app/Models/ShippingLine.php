<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingLine extends Model
{
    protected $table = 'shipping_lines';

    protected $fillable = [
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}