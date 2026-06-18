<?php
// Modelo Comprador — representa un comprador internacional de banano

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comprador extends Model
{
    // Indica a Laravel que la tabla está en el schema comercial_internacional
    protected $table = 'comercial_internacional.compradores';

    // Campos que se pueden llenar masivamente
    protected $fillable = [
        'razon_social',
        'pais',
        'ciudad',
        'contacto_nombre',
        'contacto_email',
        'contacto_telefono',
        'tipo',
        'moneda',
        'condicion_pago',
        'activo',
        'observaciones',
    ];

    // Casteo de tipos de datos
    protected $casts = [
        'activo' => 'boolean',  // Asegura que activo sea true/false
    ];
}