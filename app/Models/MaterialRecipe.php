<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialRecipe extends Model
{
    protected $fillable = [
        'supply_id',
        'base_cupo',      // siempre 'MARCA' desde el rediseño (cada marca calcula sobre su propio cupo)
        'ratio_per_box',  // cantidad de insumo por caja de esa marca
        'unit',
        'status',
        'brand',          // brands.code: GLOBAL_VILLAGE, PALMS_BANANA, PALMS_CON_BANDA, DONA_ELENA...
    ];

    // Relación con el insumo real de la tabla supplies
    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }

    // calcularRecomendado() eliminada: el cálculo vive ahora en
    // MaterialDispatchController::calcularMateriales() (cupo por marca).
}