<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Pago de una liquidación: 1 = 100 % / 80 %, 2 = 20 %. paid_at null = pendiente.
class SettlementPayment extends Model
{
    protected $fillable = [
        'settlement_id',
        'installment',
        'description',
        'amount',
        'paid_at',
        'reference',
        'paid_by',
    ];

    protected $casts = [
        'installment' => 'integer',
        'amount'      => 'decimal:2',
        'paid_at'     => 'date:Y-m-d',
    ];

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
