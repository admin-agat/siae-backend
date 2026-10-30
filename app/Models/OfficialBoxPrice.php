<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Precio oficial por caja que fija el MAG (acuerdo ministerial), por tipo de caja y vigencia.
// La diferencia con el precio real de la compra es la nota de crédito del vendedor.
class OfficialBoxPrice extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'box_type_id',
        'valid_from',
        'valid_to',
        'price',
        'agreement_number',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'date:Y-m-d',
        'valid_to'   => 'date:Y-m-d',
        'price'      => 'decimal:2',
        'status'     => 'boolean',
    ];

    public function boxType()
    {
        return $this->belongsTo(BoxType::class);
    }

    /**
     * Precio vigente para un tipo de caja en una fecha (el de vigencia más reciente).
     */
    public static function vigente(?int $boxTypeId, string $fecha): ?self
    {
        if (!$boxTypeId) {
            return null;
        }

        return static::where('box_type_id', $boxTypeId)
            ->where('status', true)
            ->where('valid_from', '<=', $fecha)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $fecha))
            ->orderByDesc('valid_from')
            ->first();
    }
}
