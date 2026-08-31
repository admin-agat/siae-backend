<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $table = 'contracts';

    protected $fillable = [
        'third_party_id', 'magap_contract_number', 'contracted_quantity',
        'harvest_day', 'start_date', 'end_date', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'contracted_quantity' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }
}