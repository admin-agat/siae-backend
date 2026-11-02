<?php

// Gasto de un viaje (02/11/2026): puerto y trámites, transporte, termógrafos y sellos, seguro,
// comisión u otro. customer_id NULL = de todo el viaje (se reparte entre los clientes por cajas).
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentExpense extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora

    public const CATEGORIAS = [
        'PUERTO_TRAMITES'    => 'Puerto y trámites',
        'TRANSPORTE'         => 'Transporte',
        'TERMOGRAFOS_SELLOS' => 'Termógrafos y sellos',
        'SEGURO'             => 'Seguro',
        'COMISION'           => 'Comisiones',
        'OTRO'               => 'Otro',
    ];

    protected $table = 'shipment_expenses';

    protected $fillable = [
        'shipment_id', 'customer_id', 'category', 'description', 'supplier_id',
        'invoice_number', 'invoice_date', 'amount', 'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date:Y-m-d',
        'amount'       => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(ThirdParty::class, 'supplier_id');
    }
}
