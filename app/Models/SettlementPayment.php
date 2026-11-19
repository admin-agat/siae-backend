<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Pago de una liquidación: 1 = 100 % / 80 %, 2 = 20 %. paid_at null = pendiente.
class SettlementPayment extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'settlement_id',
        'installment',
        'description',
        'amount',
        'paid_at',
        'reference',
        'paid_by',
        // 19/11/2026 (fase 1b): una cuota por LQ con código de orden, aprobación del jefe de Liquidaciones y pago de Contabilidad
        'payment_order_code',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'scheduled_week',
        'scheduled_year',
        'payee_third_party_id',
        'payment_status',
        'payment_method',
        'voucher_path',
    ];

    protected $casts = [
        'installment' => 'integer',
        'amount'      => 'decimal:2',
        'paid_at'     => 'date:Y-m-d',
        'approved_at' => 'datetime', // 19/11/2026
    ];

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class);
    }
}
