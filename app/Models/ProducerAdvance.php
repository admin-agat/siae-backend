<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Anticipo o préstamo entregado al productor; se descuenta en sus liquidaciones.
class ProducerAdvance extends Model
{
    public const TIPOS = ['ANTICIPO', 'PRESTAMO'];

    protected $fillable = [
        'third_party_id',
        'date',
        'type',
        'amount',
        'concept',
        'reference',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    // Lo ya descontado en liquidaciones ACTIVAS (una liquidación anulada libera el anticipo)
    public function descontado(): float
    {
        return (float) DB::table('settlement_advances as sa')
            ->join('settlements as s', 's.id', '=', 'sa.settlement_id')
            ->where('sa.producer_advance_id', $this->id)
            ->where('s.status', true)
            ->sum('sa.amount');
    }

    public function saldo(): float
    {
        return round((float) $this->amount - $this->descontado(), 2);
    }
}
