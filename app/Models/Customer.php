<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'third_party_id', 'customer_code', 'country', 'contact_name', 'negotiation_type', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }
}