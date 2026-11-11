<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Contrato del productor o comercializadora (hoja "Base Productores" del Excel de liquidaciones).
// 06/11/2026: N° (CONTRATO 1, 2…), cupo semanal, precio y si se le cobra transferencia, evaluadores,
// materiales y transporte (si dice NO, ese descuento no se aplica al liquidar sus compras).
class Contract extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $table = 'contracts';

    protected $fillable = [
        'third_party_id', 'magap_contract_number', 'contracted_quantity',
        'harvest_day', 'start_date', 'end_date', 'status',
        'number', 'price', 'weekly_boxes', 'discount_condition',
        'charge_transfer', 'charge_evaluators', 'charge_materials', 'charge_transport',
        'notes', 'updated_by',
        'banana_code', 'farm_name', // 11/11/2026: código único bananero y finca (Excel de contratos)
    ];

    public const CONDICIONES = ['CON_DESCUENTO', 'SIN_DESCUENTO'];

    protected $casts = [
        'status' => 'boolean',
        'contracted_quantity' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'number' => 'integer',
        'price' => 'decimal:2',
        'weekly_boxes' => 'integer',
        'charge_transfer' => 'boolean',
        'charge_evaluators' => 'boolean',
        'charge_materials' => 'boolean',
        'charge_transport' => 'boolean',
    ];

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    /**
     * 11/11/2026: ¿vale el contrato para una semana de compra? Basta que su vigencia toque esa semana
     * (lunes a domingo). Sin fecha "desde" o "hasta" no tiene límite por ese lado.
     */
    public function vigenteEnSemana(int $anio, int $semana): bool
    {
        $lunes = \Carbon\Carbon::now()->setISODate($anio, $semana)->startOfWeek()->toDateString();
        $domingo = \Carbon\Carbon::now()->setISODate($anio, $semana)->endOfWeek()->toDateString();

        return (!$this->start_date || $this->start_date->toDateString() <= $domingo)
            && (!$this->end_date || $this->end_date->toDateString() >= $lunes);
    }

    /** 11/11/2026: fin de vigencia por defecto de un contrato nuevo: 31 de diciembre de su año. */
    public static function finDeAnio(?string $desde): string
    {
        $anio = $desde ? substr($desde, 0, 4) : now(config('app.zona_operacion'))->format('Y');

        return "$anio-12-31";
    }
}
