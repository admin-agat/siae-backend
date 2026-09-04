<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SupplyCategory extends Model
{
    protected $fillable = [
        'name',
        'group_label', // CARTON, EMPAQUE, CONTENEDOR — campo simple, sin tabla propia
        'chargeable_to_producer',
        'code_prefix', // calcula el proximo digito del codigo
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'chargeable_to_producer' => 'boolean',
    ];

    public function supplies()
    {
        return $this->hasMany(Supply::class);
    }
}