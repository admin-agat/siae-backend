<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'third_party_id',
        'warehouse_id',
        'date',
        'week',
        'status',
        'reference',
        'created_by',
    ];
    // Igual que en PurchaseOrderLine: expone los totales calculados en el JSON
    protected $appends = ['subtotal_15', 'subtotal_5', 'subtotal_0', 'iva_15', 'iva_5', 'retencion_total', 'total'];

    /**
     * Líneas de insumos de esta orden de compra.
     */
    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * Proveedor al que se le hizo la orden.
     */
    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    /**
     * Bodega destino donde deben entrar los insumos.
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Usuario que creó la orden.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Suma el subtotal (neto) de todas las líneas que tienen ese % de IVA específico
    private function subtotalPorTasa($tasa)
    {
        return round(
            $this->lines->where('tax_rate', $tasa)->sum(fn($l) => $l->subtotal),
            2
        );
    }

    public function getSubtotal15Attribute()
    {
        return $this->subtotalPorTasa(15.00);
    }

    public function getSubtotal5Attribute()
    {
        return $this->subtotalPorTasa(5.00);
    }

    public function getSubtotal0Attribute()
    {
        return $this->subtotalPorTasa(0.00);
    }

    public function getIva15Attribute()
    {
        return round($this->lines->where('tax_rate', 15.00)->sum(fn($l) => $l->iva), 2);
    }

    public function getIva5Attribute()
    {
        return round($this->lines->where('tax_rate', 5.00)->sum(fn($l) => $l->iva), 2);
    }

    public function getRetencionTotalAttribute()
    {
        return round($this->lines->sum(fn($l) => $l->retencion), 2);
    }

    public function getTotalAttribute()
    {
        return round($this->lines->sum(fn($l) => $l->total), 2);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

}