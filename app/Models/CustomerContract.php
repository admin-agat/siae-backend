<?php

// Modelo CustomerContract → tabla customer_contracts (contratos de VENTA con clientes)
// No confundir con Contract → contracts (contratos MAGAP con productores).
// Contrato de un cliente de Rusia: vigencia (start_date / end_date) + precio por caja.
// Ubicación: app/Models/CustomerContract.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerContract extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    // Nombre explícito de la tabla (regla del proyecto: siempre declararlo)
    protected $table = 'customer_contracts';

    // Campos que se pueden guardar desde el controlador
    protected $fillable = [
        'contract_number',
        'customer_id',
        'incoterm',
        'start_date',
        'end_date',
        'box_price',
        'weekly_boxes',
        'comment',
        'status',
        'created_by',
    ];

    protected $casts = [
        // 'date:Y-m-d' → el JSON devuelve "2026-01-15" (sin hora),
        // que es el formato que entiende <input type="date"> en el frontend
        'start_date'   => 'date:Y-m-d',
        'end_date'     => 'date:Y-m-d',
        'box_price'    => 'decimal:2',
        'weekly_boxes' => 'integer',
        'status'       => 'boolean',
    ];

    // Cliente dueño del contrato
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
