<?php

namespace App\Services;

use App\Models\Supply;
use App\Models\SupplyPriceHistory;
use Illuminate\Support\Facades\DB;

/**
 * 31/10/2026 — Costo promedio ponderado por insumo, uno solo para toda la empresa.
 *
 * Se recorre en orden (fecha, id) todo lo activo del insumo:
 *   - INGRESO con costo  → promedio = (saldo × promedio + cantidad × costo) ÷ (saldo + cantidad)
 *   - INGRESO sin costo, DEVOLUCIÓN → entran al promedio vigente (no lo cambian)
 *   - EGRESO (despacho, merma...)    → baja el saldo; el promedio no cambia
 *   - Transferencias entre bodegas (motivos 5 y 9) → no cuentan: el material sigue en la empresa
 * Se recalcula desde cero cada vez, así editar o anular una compra vieja también corrige el promedio.
 * Los despachos ya hechos guardan el costo de su día (no se tocan).
 */
class CostoPromedio
{
    private const MOTIVOS_TRANSFERENCIA = [5, 9];

    /**
     * ['promedio' => ?float, 'ultima_compra' => ?float, 'por_movimiento' => [movement_id => promedio después de él],
     *  'fechas' => [movement_id => Y-m-d]] (ambos en orden fecha, id)
     * promedio = null si el insumo no tiene ninguna compra con costo (se respeta el costo cargado a mano).
     */
    public static function historial(int $supplyId): array
    {
        $lineas = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->where('l.supply_id', $supplyId)
            ->where('m.status', true)
            ->orderBy('m.date')->orderBy('m.id')->orderBy('l.id')
            ->get(['m.id', 'm.date', 'm.type', 'm.movement_reason_id', 'l.quantity', 'l.unit_cost', 'l.discount']);

        $saldo = 0.0;
        $promedio = null;
        $ultimaCompra = null;
        $porMovimiento = [];
        $fechas = [];

        foreach ($lineas as $l) {
            $cantidad = (float) $l->quantity;
            if (!in_array((int) $l->movement_reason_id, self::MOTIVOS_TRANSFERENCIA)) {
                if ($l->type === 'INGRESO') {
                    $costo = $cantidad > 0 ? ($cantidad * (float) $l->unit_cost - (float) $l->discount) / $cantidad : 0.0;
                    if ($costo > 0) {
                        $promedio = ($promedio === null || $saldo <= 0)
                            ? $costo
                            : ($saldo * $promedio + $cantidad * $costo) / ($saldo + $cantidad);
                        $ultimaCompra = $costo;
                    }
                    $saldo += $cantidad;
                } elseif ($l->type === 'DEVOLUCION') {
                    $saldo += $cantidad;
                } else {
                    $saldo -= $cantidad;
                }
            }
            $porMovimiento[$l->id] = $promedio;
            $fechas[$l->id] = substr((string) $l->date, 0, 10);
        }

        return ['promedio' => $promedio, 'ultima_compra' => $ultimaCompra, 'por_movimiento' => $porMovimiento, 'fechas' => $fechas];
    }

    /** Promedio vigente antes de una fecha (para valorizar el saldo inicial del kárdex). */
    public static function promedioAntesDe(array $historial, string $fecha): ?float
    {
        $promedio = null;
        foreach ($historial['por_movimiento'] as $id => $p) {
            if ($historial['fechas'][$id] >= $fecha) {
                break;
            }
            $promedio = $p;
        }
        return $promedio;
    }

    /** true si el costo del insumo sale de compras (entonces no se edita a mano). */
    public static function tieneCompras(int $supplyId): bool
    {
        return self::historial($supplyId)['promedio'] !== null;
    }

    /**
     * Recalcula y guarda el costo promedio (y la última compra) de los insumos dados.
     * Deja el cambio en el historial de precios.
     */
    public static function recalcular(iterable $supplyIds, ?int $userId = null): void
    {
        foreach (collect($supplyIds)->map(fn ($id) => (int) $id)->unique()->filter() as $supplyId) {
            $supply = Supply::find($supplyId);
            if (!$supply) {
                continue;
            }
            $h = self::historial($supplyId);
            if ($h['promedio'] === null) {
                // Sin compras (o todas anuladas): queda el costo de referencia cargado a mano.
                // last_purchase_cost = null es lo que la pantalla de insumos usa para dejarlo editable.
                if ($supply->last_purchase_cost !== null) {
                    $supply->update(['last_purchase_cost' => null]);
                }
                continue;
            }
            $nuevo = round($h['promedio'], 6);
            $cambios = ['last_purchase_cost' => $h['ultima_compra'] !== null ? round($h['ultima_compra'], 6) : null];
            if (abs($nuevo - (float) $supply->cost) > 0.0000005) {
                SupplyPriceHistory::create([
                    'supply_id' => $supplyId,
                    'old_cost' => $supply->cost,
                    'new_cost' => $nuevo,
                    'changed_by' => $userId,
                    'changed_at' => now(),
                ]);
                $cambios['cost'] = $nuevo;
            }
            $supply->update($cambios);
        }
    }
}
