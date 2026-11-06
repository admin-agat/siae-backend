<?php

// Bancos para las cuentas de los proveedores (05/11/2026). "code" = código del banco en el archivo de
// pagos de Banco Pichincha; lo llena Contabilidad con la tabla que entrega el banco.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = ['name', 'code', 'status'];

    protected $casts = ['status' => 'boolean'];
}
