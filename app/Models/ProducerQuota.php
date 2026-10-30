<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProducerQuota extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    // Estados válidos — deben coincidir con el CHECK producer_quotas_status_check (Paso 5b)
    public const ASIGNADO   = 'ASIGNADO';    // la jefa comercial dio el cupo
    public const DESPACHADO = 'DESPACHADO';  // ya retiró materiales contra este cupo
    public const LIQUIDADO  = 'LIQUIDADO';   // pagado: bloquea nuevos despachos

    protected $fillable = [
        'third_party_id',
        'week_number',
        'year',
        'total_cupo',
        'status',
        'created_by',
        'customer_order_id', // Fase 3: pedido semanal por el que Comercial dio el cupo
    ];

    protected $casts = [
        'total_cupo' => 'decimal:2',
    ];

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    // Cajas por marca (variety = brands.code)
    public function lines()
    {
        return $this->hasMany(ProducerQuotaLine::class);
    }

    // Pedido semanal del que sale este cupo (null = cupo manual creado en el despacho)
    public function order()
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // NUEVO — todos los despachos (EGRESO) hechos contra este cupo.
    // Un cupo puede tener varios si el productor retira en varios viajes.
    public function dispatches()
    {
        return $this->hasMany(InventoryMovement::class, 'producer_quota_id');
    }

    // Liquidación ACTIVA del cupo (null = sin liquidar)
    public function settlement()
    {
        return $this->hasOne(Settlement::class)->where('status', true);
    }
}