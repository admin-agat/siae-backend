<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'warehouse_id',
        'movement_reason_id',
        'third_party_id',
        'type', // INGRESO | EGRESO
        'date',
        'purchase_order',
        'week',
        'year',
        'delivery_note',
        'reference',
        'created_by_user_id',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'status' => 'boolean',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
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

        /**
     * Si el movimiento es un INGRESO, actualiza el precio de referencia (cost)
     * de cada insumo según el precio de compra de esa línea, y registra el
     * cambio en supply_price_history. Política de la empresa: sin margen,
     * el precio de compra ES el precio que se cobra al productor.
     */
    private function actualizarPreciosSiEsIngreso(string $type, array $lines, ?int $userId)
    {
        if ($type !== 'INGRESO') {
            return;
        }

        foreach ($lines as $line) {
            $supply = \App\Models\Supply::find($line['supply_id']);
            $nuevoCosto = $line['unit_cost'] ?? 0;

            // Solo registra histórico y actualiza si el precio realmente cambió
            if ($supply && $nuevoCosto > 0 && $nuevoCosto != $supply->cost) {
                \App\Models\SupplyPriceHistory::create([
                    'supply_id' => $supply->id,
                    'old_cost' => $supply->cost,
                    'new_cost' => $nuevoCosto,
                    'changed_by' => $userId,
                ]);

                $supply->update(['cost' => $nuevoCosto]);
            }
        }
    }
}