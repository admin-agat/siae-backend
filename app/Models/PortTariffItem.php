<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortTariffItem extends Model
{
    protected $fillable = ['port_id', 'concept', 'amount', 'status'];

    protected $casts = [
        'status' => 'boolean',
        'amount' => 'decimal:2',
    ];

    public function port()
    {
        return $this->belongsTo(Port::class);
    }

    public function priceHistory()
    {
        return $this->hasMany(PortTariffItemPriceHistory::class);
    }
}