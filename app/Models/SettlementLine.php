<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Fruta liquidada de una compra (cliente + marca): cajas pagadas × precio real.
// Factura = pagadas × oficial; nota de crédito = factura − fruta (30/10/2026).
class SettlementLine extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'settlement_id',
        'producer_quota_line_id',
        'negotiation_type',
        'payment_scheme',
        'boxes_purchased',
        'boxes_shipped',
        'boxes_fallen',
        'boxes_rejected',
        'boxes_paid',
        'real_price',
        'amount',
        'transport_deduction',
        'official_price',
        'invoice_amount',
        'credit_note_amount',
    ];

    protected $casts = [
        'boxes_purchased' => 'integer',
        'boxes_shipped'   => 'integer',
        'boxes_fallen'    => 'integer',
        'boxes_rejected'  => 'integer',
        'boxes_paid'      => 'integer',
        'real_price'      => 'decimal:2',
        'amount'          => 'decimal:2',
        'transport_deduction' => 'decimal:2',
        'official_price'  => 'decimal:2',
        'invoice_amount'  => 'decimal:2',
        'credit_note_amount' => 'decimal:2',
    ];

    public function purchase()
    {
        return $this->belongsTo(ProducerQuotaLine::class, 'producer_quota_line_id');
    }
}
