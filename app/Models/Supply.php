<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supply extends Model
{
    protected $fillable = [
        'supply_category_id',
        'code',
        'name',
        'description',
        'unit',
        'cost',
        'status',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(SupplyCategory::class, 'supply_category_id');
    }

    public function thirdParties()
{
    return $this->belongsToMany(\App\Models\ThirdParty::class, 'third_party_supplies');
}
}