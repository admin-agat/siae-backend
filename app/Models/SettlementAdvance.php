<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Anticipo/préstamo descontado en una liquidación
class SettlementAdvance extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = ['settlement_id', 'producer_advance_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function advance()
    {
        return $this->belongsTo(ProducerAdvance::class, 'producer_advance_id');
    }
}
