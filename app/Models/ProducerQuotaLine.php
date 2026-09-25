<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProducerQuotaLine extends Model
{
    protected $fillable = [
        'producer_quota_id',
        'variety',
        'quantity_cajas',
    ];

    public function quota()
    {
        return $this->belongsTo(ProducerQuota::class, 'producer_quota_id');
    }
}