<?php

// Lote de pagos al banco (05/11/2026): las OC aprobadas que Contabilidad sube juntas en un TXT.
// GENERADO (se bajó el archivo) → PAGADO (el banco confirmó) o ANULADO (las OC vuelven a por pagar).
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankPaymentBatch extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = ['code', 'date', 'payments_count', 'total', 'file_name', 'content', 'status', 'created_by', 'paid_at', 'paid_by', 'cancellation_reason'];

    protected $hidden = ['content'];

    protected $casts = ['paid_at' => 'datetime', 'total' => 'decimal:2'];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // 19/11/2026 (fase 1b): una línea por orden pagada (OC, cuota de LQ o anticipo), con la cuenta congelada
    public function items()
    {
        return $this->hasMany(BankPaymentBatchItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
