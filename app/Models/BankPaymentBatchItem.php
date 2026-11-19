<?php

// 19/11/2026 (fase 1b): línea de un lote al banco = una orden pagada (OC, cuota de LQ o anticipo) con una copia
// congelada de la cuenta del beneficiario. Número de cuenta e identificación van cifrados y nunca salen en la API.
// EN_BANCO → PAGADO o FALLIDO (el banco la rechazó) o ANULADO (se anuló el lote).
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankPaymentBatchItem extends Model
{
    use \App\Models\Concerns\Auditable;

    public const ORIGENES = ['OC', 'LIQUIDACION', 'ANTICIPO'];

    protected $fillable = [
        'bank_payment_batch_id', 'source_type', 'purchase_order_id', 'settlement_payment_id', 'producer_advance_id',
        'payee_third_party_id', 'amount', 'bank_id', 'account_type', 'account_number', 'holder_id_type', 'holder_id',
        'holder_name', 'reference', 'status', 'failure_reason', 'paid_at', 'bank_reference',
    ];

    protected $hidden = ['account_number', 'holder_id'];

    protected $casts = [
        'account_number' => 'encrypted',
        'holder_id'      => 'encrypted',
        'amount'         => 'decimal:2',
        'paid_at'        => 'date:Y-m-d',
    ];

    public function batch()
    {
        return $this->belongsTo(BankPaymentBatch::class, 'bank_payment_batch_id');
    }

    public function producerAdvance()
    {
        return $this->belongsTo(ProducerAdvance::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function payee()
    {
        return $this->belongsTo(ThirdParty::class, 'payee_third_party_id');
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }
}
