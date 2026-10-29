<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tarja: cajas que llegan a puerto en un camión, de una compra (producer_quota_line).
// Se pagan EMBARCADAS + CAÍDAS; las RECHAZADAS no.
class TallySheet extends Model
{
    protected $fillable = [
        'producer_quota_line_id',
        'shipment_id',
        'date',
        'number',
        'truck_plate',
        'boxes_shipped',
        'boxes_fallen',
        'boxes_rejected',
        'notes',
        'container_number', // N° de contenedor (cuando va en contenedor)
        'created_by',
    ];

    protected $casts = [
        'date'           => 'date:Y-m-d',
        'boxes_shipped'  => 'integer',
        'boxes_fallen'   => 'integer',
        'boxes_rejected' => 'integer',
    ];

    public function purchase()
    {
        return $this->belongsTo(ProducerQuotaLine::class, 'producer_quota_line_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
