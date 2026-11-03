<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supply extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'supply_category_id',
        'code',
        'name',
        'description',
        'unit',
        'cost',               // costo promedio ponderado (App\Services\CostoPromedio), 31/10/2026
        'last_purchase_cost', // último precio de compra, solo referencia
        'status',
    ];

    protected $casts = [
        // 6 decimales: con decimal:2 un costo de $0.0456 se leía como $0.05 (y así se despachaba)
        'cost' => 'decimal:6',
        'last_purchase_cost' => 'decimal:6',
        'status' => 'boolean',
    ];

    // 03/11/2026: solo lo que se pesa admite decimales; lo que se cuenta por pieza (TAPA, FONDO, FRASCOS,
    // ROLLOS, SACOS, CAJAS...) va en enteros. Misma regla que el redondeo del Despacho de materiales.
    public const UNIDADES_CON_DECIMALES = ['LIBRAS'];

    public static function permiteDecimales(?string $unidad): bool
    {
        return in_array(mb_strtoupper(trim((string) $unidad)), self::UNIDADES_CON_DECIMALES, true);
    }

    /**
     * Revisa las líneas [supply_id, $campo] y devuelve un mensaje si alguna lleva decimales en un insumo
     * que se cuenta por pieza; null si todo está bien.
     */
    public static function errorDecimales(array $lineas, string $campo = 'quantity'): ?string
    {
        $conDecimales = collect($lineas)->filter(fn ($l) => isset($l[$campo]) && floor((float) $l[$campo]) != (float) $l[$campo]);
        if ($conDecimales->isEmpty()) {
            return null;
        }
        $insumos = self::whereIn('id', $conDecimales->pluck('supply_id'))->get()->reject(fn ($s) => self::permiteDecimales($s->unit));

        return $insumos->isEmpty() ? null
            : 'Estos insumos se cuentan por pieza y no aceptan decimales: ' . $insumos->map(fn ($s) => "{$s->code} {$s->name} ({$s->unit})")->implode(', ') . '.';
    }

    public function category()
    {
        return $this->belongsTo(SupplyCategory::class, 'supply_category_id');
    }

    public function thirdParties()
{
    return $this->belongsToMany(\App\Models\ThirdParty::class, 'third_party_supplies');
}
}