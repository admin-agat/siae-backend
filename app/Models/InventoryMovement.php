<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryMovement extends Model
{
    protected $fillable = [
        'warehouse_id',
        'movement_reason_id',
        'third_party_id',
        'type',
        'date',
        'purchase_order_id',
        'week',
        'year',
        'delivery_note',
        'reference',
        'vapor',
        'created_by_user_id',
        'status',
        'linked_movement_id',
        // Solo se usan en el EGRESO de una transferencia entre bodegas
        // mientras está PENDIENTE de confirmación.
        'transfer_status',
        'destination_warehouse_id',
        // NUEVO — Despacho de Materiales. Solo tienen valor en un EGRESO
        // con motivo ENTREGA A PRODUCTOR (id 4) generado desde /despacho-materiales.
        // Si producer_quota_id es NULL, el movimiento NO vino de un despacho por cupo.
        'producer_quota_id',     // cupo de la semana que originó el despacho
        'received_by_name',      // nombre de quien retira físicamente en bodega
        'received_by_document',  // cédula de quien retira
    ];

    protected $casts = [
        'date' => 'date',
        'status' => 'boolean',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Bodega destino de una transferencia — solo tiene valor en el EGRESO
    // mientras transfer_status es PENDIENTE o CONFIRMADA.
    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function reason()
    {
        return $this->belongsTo(MovementReason::class, 'movement_reason_id');
    }

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lines()
    {
        return $this->hasMany(InventoryMovementLine::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // NUEVO — cupo (producer_quotas) que originó este despacho.
    // La liquidación lo usa para sumar todo lo despachado contra un cupo.
    public function producerQuota()
    {
        return $this->belongsTo(ProducerQuota::class, 'producer_quota_id');
    }

    /**
     * NUEVO — ÚNICA fórmula de stock disponible del sistema.
     * Movida aquí desde InventoryMovementController (antes era private y
     * MaterialDispatchController no podía reutilizarla).
     *
     * INGRESO/DEVOLUCION suman, EGRESO resta, solo movimientos activos (status = true).
     * $excludeMovementId saca un movimiento puntual del cálculo: se usa al
     * editar, para no descontar dos veces las líneas del propio movimiento.
     */
    public static function stockDisponible(int $warehouseId, int $supplyId, ?int $excludeMovementId = null): float
    {
        $query = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->where('m.warehouse_id', $warehouseId)
            ->where('l.supply_id', $supplyId)
            ->where('m.status', true);

        if ($excludeMovementId !== null) {
            $query->where('m.id', '!=', $excludeMovementId);
        }

        return (float) $query
            ->selectRaw("COALESCE(SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END), 0) as existencia")
            ->value('existencia');
    }
}