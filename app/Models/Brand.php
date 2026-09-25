<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'code',
        'status',
    ];

    // Garantiza que status llegue al frontend como true/false
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Recetas de materiales (BOM) de esta marca.
     *
     * OJO: material_recipes NO tiene brand_id. Guarda el CÓDIGO de la marca
     * en la columna 'brand' (ej. 'GLOBAL_VILLAGE'). Por eso la relación se
     * arma por code: hasMany(Modelo, columna_en_recetas, columna_en_brands).
     *
     * No existen recetas compartidas: cada marca tiene su receta completa.
     */
    public function materialRecipes()
    {
        return $this->hasMany(MaterialRecipe::class, 'brand', 'code');
    }
}