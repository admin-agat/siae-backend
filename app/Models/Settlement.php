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
        // 17/11/2026 (fase 0): LQ separadas por CONTRATO (una por contrato) y SPOT
        'negotiation_type',
        'contract_id',
        'group_label',
        'third_party_id',
        'week',
        'year',
        'date',
        'boxes_shipped',
        'boxes_fallen',
        'boxes_rejected',
        'boxes_missing', // 17/11/2026: FALTANTES; boxes_paid = RECIBIDAS
        'boxes_paid',
        'fruit_amount',
        'materials_amount',
        'advances_amount',
        'transport_amount', // transporte de contenedor negociado al comprar
        'transport_recognized_amount', // 11/11/2026: transporte que se le reconoce (se suma al pago)
        'bonus_amount', // 13/11/2026: bono / extra al productor (se suma al pago, aparte de la fruta)
        'net_amount',
        'invoice_amount',     // pagadas × precio oficial (lo que factura el vendedor)
        'credit_note_amount', // factura − fruta: nota de crédito que debe entregar
        // 17/11/2026: retención IR banano congelada (RetencionBanano)
        'retention_regime',
        'retention_base',
        'retention_rate',
        'retention_amount',
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
        'boxes_missing'    => 'integer',
        'retention_base'   => 'decimal:2',
        'retention_rate'   => 'decimal:4',
        'retention_amount' => 'decimal:2',
        'boxes_paid'       => 'integer',
        'fruit_amount'     => 'decimal:2',
        'materials_amount' => 'decimal:2',
        'advances_amount'  => 'decimal:2',
        'transport_amount' => 'decimal:2',
        'transport_recognized_amount' => 'decimal:2',
        'bonus_amount'     => 'decimal:2',
        'net_amount'       => 'decimal:2',
        'status'           => 'boolean',
    ];

    public function quota()
    {
        return $this->belongsTo(ProducerQuota::class, 'producer_quota_id');
    }

    // 17/11/2026: contrato de la LQ (null en SPOT y en las liquidaciones viejas)
    public function contract()
    {
        return $this->belongsTo(Contract::class);
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
