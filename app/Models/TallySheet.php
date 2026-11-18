<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tarja: cajas que llegan a puerto en un camión, de una compra (producer_quota_line).
// 17/11/2026: EMBARCADAS es el total; RECIBIDAS = embarcadas − caídas − rechazadas − faltantes (lo que se paga).
class TallySheet extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'producer_quota_line_id',
        'shipment_id',
        'date',
        'number',
        'truck_plate',
        'boxes_shipped',
        'boxes_fallen',
        'boxes_rejected',
        'boxes_missing', // 17/11/2026: FALTANTES
        'notes',
        'container_number', // N° de contenedor (cuando va en contenedor)
        'created_by',
        // 17/11/2026: baja lógica con motivo (ya no se borran) y clave del celular para no duplicar al reenviar
        'status',
        'deactivation_reason',
        'client_key',
    ];

    protected $casts = [
        'date'           => 'date:Y-m-d',
        'boxes_shipped'  => 'integer',
        'boxes_fallen'   => 'integer',
        'boxes_rejected' => 'integer',
        'boxes_missing'  => 'integer',
        'status'         => 'boolean', // 17/11/2026
    ];

    protected $appends = ['boxes_received'];

    // 17/11/2026: RECIBIDAS (no se guarda: se calcula)
    public function getBoxesReceivedAttribute(): int
    {
        return (int) $this->boxes_shipped - (int) $this->boxes_fallen - (int) $this->boxes_rejected - (int) $this->boxes_missing;
    }

    public function purchase()
    {
        return $this->belongsTo(ProducerQuotaLine::class, 'producer_quota_line_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
