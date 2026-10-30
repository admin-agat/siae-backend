<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Liquidación de un cupo (productor + semana). Ver SettlementController.
class Settlement extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'code',
        'producer_quota_id',
        'third_party_id',
        'week',
        'year',
        'date',
        'boxes_shipped',
        'boxes_fallen',
        'boxes_rejected',
        'boxes_paid',
        'fruit_amount',
        'materials_amount',
        'advances_amount',
        'transport_amount', // transporte de contenedor negociado al comprar
        'net_amount',
        'invoice_amount',     // pagadas × precio oficial (lo que factura el vendedor)
        'credit_note_amount', // factura − fruta: nota de crédito que debe entregar
        'invoice_number',
        'credit_note_number',
        'credit_note_date',
        'documents_by',
        'notes',
        'status',
        'cancel_reason',
        'created_by',
    ];

    protected $casts = [
        'date'             => 'date:Y-m-d',
        'credit_note_date' => 'date:Y-m-d',
        'invoice_amount'   => 'decimal:2',
        'credit_note_amount' => 'decimal:2',
        'boxes_shipped'    => 'integer',
        'boxes_fallen'     => 'integer',
        'boxes_rejected'   => 'integer',
        'boxes_paid'       => 'integer',
        'fruit_amount'     => 'decimal:2',
        'materials_amount' => 'decimal:2',
        'advances_amount'  => 'decimal:2',
        'transport_amount' => 'decimal:2',
        'net_amount'       => 'decimal:2',
        'status'           => 'boolean',
    ];

    public function quota()
    {
        return $this->belongsTo(ProducerQuota::class, 'producer_quota_id');
    }

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function lines()
    {
        return $this->hasMany(SettlementLine::class);
    }

    public function materials()
    {
        return $this->hasMany(SettlementMaterial::class);
    }

    public function advances()
    {
        return $this->hasMany(SettlementAdvance::class);
    }

    public function payments()
    {
        return $this->hasMany(SettlementPayment::class)->orderBy('installment');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Quien registró el N° de factura / nota de crédito
    public function documentsUser()
    {
        return $this->belongsTo(User::class, 'documents_by');
    }
}
