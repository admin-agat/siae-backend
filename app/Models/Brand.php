<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    protected $fillable = [
        'name',
        'code',
        'box_type_id', // tipo de caja: define el precio oficial MAG (30/10/2026)
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
    // Clientes que usan esta marca (tabla customer_brand)
    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_brand')->withTimestamps();
    }

    public function boxType()
    {
        return $this->belongsTo(BoxType::class);
    }

    public function materialRecipes()
    {
        return $this->hasMany(MaterialRecipe::class, 'brand', 'code');
    }
}