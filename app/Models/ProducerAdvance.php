<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Anticipo o préstamo entregado al productor; se descuenta en sus liquidaciones.
class ProducerAdvance extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

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
        // 19/11/2026 (fase 1b): 80 % pedido por Comercial, saldo a favor, aprobación y pago
        'producer_quota_id',
        'producer_quota_line_id',
        'source_settlement_id',
        'payment_order_code',
        'origin',
        'boxes',
        'unit_price',
        'scheduled_week',
        'scheduled_year',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'payment_status',
        'payment_method',
        'paid_at',
        'payment_reference',
        'paid_by',
        'voucher_path',
    ];

    public const ORIGENES = ['MANUAL', 'SPOT_80', 'SALDO'];

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'status' => 'boolean',
        'boxes'       => 'integer', // 19/11/2026
        'unit_price'  => 'decimal:4',
        'approved_at' => 'datetime',
        'paid_at'     => 'date:Y-m-d',
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

    // 19/11/2026 (fase 1b): se descuenta solo lo que ya se entregó: lo de antes del sistema (ANTERIOR), lo que salió
    // al banco (EN BANCO o PAGADA) y el SALDO A FAVOR DE AGAT (lo dejó una LQ). Lo que espera aprobación no cuenta.
    public function saldo(): float
    {
        $entregado = $this->origin === 'SALDO'
            || (in_array($this->approval_status, ['ANTERIOR', 'APROBADA'], true) && in_array($this->payment_status, ['EN_BANCO', 'PAGADA'], true));
        if (!$entregado) {
            return 0.0;
        }

        return round((float) $this->amount - $this->descontado(), 2);
    }

    public function quotaLine()
    {
        return $this->belongsTo(ProducerQuotaLine::class, 'producer_quota_line_id');
    }

    public function quota()
    {
        return $this->belongsTo(ProducerQuota::class, 'producer_quota_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Código de orden ORD-AAAA-NNNNN de la secuencia compartida (seguro con dos personas a la vez)
    public static function siguienteCodigoOrden(): string
    {
        $n = DB::selectOne("select nextval('payment_order_seq') as n")->n;

        return 'ORD-' . now(config('app.zona_operacion'))->year . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
