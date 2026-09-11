<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortTariffItemPriceHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'port_tariff_item_id',
        'old_amount',
        'new_amount',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'changed_at' => 'datetime',
    ];

    public function tariffItem()
    {
        return $this->belongsTo(PortTariffItem::class, 'port_tariff_item_id');
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}