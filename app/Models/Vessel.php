<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vessel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'shipping_line_id',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function shippingLine()
    {
        return $this->belongsTo(ShippingLine::class);
    }
}