<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'supply_id',
        'quantity_ordered',
        'unit_price',
        'tax_rate',
        'discount_percent',
        'retention_rate',
        'quantity_received',
        
    ];
        // Sin esto, los accessors (getXAttribute) no salen en el JSON que recibe el frontend
    protected $appends = ['subtotal_bruto', 'descuento', 'subtotal', 'iva', 'retencion', 'total'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }

    // Subtotal bruto de la línea, antes de descuento: cantidad x precio
    public function getSubtotalBrutoAttribute()
    {
        return round($this->quantity_ordered * $this->unit_price, 2);
    }

    // Monto de descuento de la línea
    public function getDescuentoAttribute()
    {
        return round($this->subtotal_bruto * ($this->discount_percent / 100), 2);
    }

    // Subtotal neto (base imponible): subtotal bruto - descuento
    public function getSubtotalAttribute()
    {
        return round($this->subtotal_bruto - $this->descuento, 2);
    }

    // Monto de IVA calculado sobre el subtotal neto
    public function getIvaAttribute()
    {
        return round($this->subtotal * ($this->tax_rate / 100), 2);
    }

    // Monto de Retención IR calculado sobre el subtotal neto
    public function getRetencionAttribute()
    {
        return round($this->subtotal * ($this->retention_rate / 100), 2);
    }

    // Total de la línea: subtotal + IVA - retención
    public function getTotalAttribute()
    {
        return round($this->subtotal + $this->iva - $this->retencion, 2);
    }
}