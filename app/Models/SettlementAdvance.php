<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Anticipo/préstamo descontado en una liquidación
class SettlementAdvance extends Model
{
    protected $fillable = ['settlement_id', 'producer_advance_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function advance()
    {
        return $this->belongsTo(ProducerAdvance::class, 'producer_advance_id');
    }
}
