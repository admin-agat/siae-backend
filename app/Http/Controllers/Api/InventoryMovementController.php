<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryMovementController extends Controller
{
    /**
     * Lista movimientos con sus relaciones básicas.
     * Filtros opcionales: warehouse_id, type (INGRESO/EGRESO/DEVOLUCION), date desde/hasta.
     */
    public function index(Request $request)
    {
        $query = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy'])
            ->where('status', true);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        return response()->json($query->orderByDesc('date')->orderByDesc('id')->get());
    }

    /**
     * Crea un movimiento (cabecera) junto con todas sus líneas de producto,
     * dentro de una transacción: si una línea falla, no se guarda nada.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'movement_reason_id' => 'required|exists:movement_reasons,id',
            'third_party_id' => 'nullable|exists:third_parties,id',
            // Se agrega DEVOLUCION como tercer tipo válido (confirmado con el
            // formato físico de campo: Ingreso/Egreso/Devolución de Cartón y Material)
            'type' => 'required|in:INGRESO,EGRESO,DEVOLUCION',
            'date' => 'required|date',
            'purchase_order' => 'nullable|string|max:255',
            'week' => 'nullable|integer|min:1|max:53',
            'year' => 'nullable|integer|min:2000|max:2100',
            'delivery_note' => 'nullable|string|max:255',
            'reference' => 'nullable|string',
            // Nombre del barco/embarque. Nullable porque solo se conoce
            // después de la Asignación de Cupo (flujo aún no implementado),
            // así que la mayoría de los movimientos la tendrán vacía por ahora.
            'vapor' => 'nullable|string|max:255',

            // Líneas de detalle: al menos 1 producto por movimiento
            'lines' => 'required|array|min:1',
            'lines.*.supply_id' => 'required|exists:supplies,id',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
        ]);

        $movement = DB::transaction(function () use ($validated, $request) {
            $movement = InventoryMovement::create([
                'warehouse_id' => $validated['warehouse_id'],
                'movement_reason_id' => $validated['movement_reason_id'],
                'third_party_id' => $validated['third_party_id'] ?? null,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'purchase_order' => $validated['purchase_order'] ?? null,
                'week' => $validated['week'] ?? null,
                'year' => $validated['year'] ?? null,
                'delivery_note' => $validated['delivery_note'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'vapor' => $validated['vapor'] ?? null,
                'created_by_user_id' => $request->user()?->id,
            ]);

            foreach ($validated['lines'] as $line) {
                $quantity = $line['quantity'];
                $unitCost = $line['unit_cost'] ?? 0;
                $discount = $line['discount'] ?? 0;

                $movement->lines()->create([
                    'supply_id' => $line['supply_id'],
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'discount' => $discount,
                    'total' => ($quantity * $unitCost) - $discount,
                ]);
            }

            return $movement;
        });

        // Solo un INGRESO real (compra) actualiza el precio de referencia;
        // una DEVOLUCION no es una compra, así que no dispara este ajuste.
        $this->actualizarPreciosSiEsIngreso($validated['type'], $validated['lines'], $request->user()?->id);

        return response()->json(
            $movement->load(['warehouse', 'reason', 'thirdParty', 'lines.supply']),
            201
        );
    }

    /**
     * Muestra un movimiento con su detalle completo.
     */
    public function show($id)
    {
        $movement = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy', 'lines.supply'])
            ->findOrFail($id);

        return response()->json($movement);
    }

    /**
     * Actualiza la cabecera y REEMPLAZA todas las líneas (se borran las
     * viejas y se crean las nuevas), dentro de una transacción.
     */
    public function update(Request $request, $id)
    {
        $movement = InventoryMovement::findOrFail($id);

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'movement_reason_id' => 'required|exists:movement_reasons,id',
            'third_party_id' => 'nullable|exists:third_parties,id',
            'type' => 'required|in:INGRESO,EGRESO,DEVOLUCION',
            'date' => 'required|date',
            'purchase_order' => 'nullable|string|max:255',
            'week' => 'nullable|integer|min:1|max:53',
            'year' => 'nullable|integer|min:2000|max:2100',
            'delivery_note' => 'nullable|string|max:255',
            'reference' => 'nullable|string',
            'vapor' => 'nullable|string|max:255',

            'lines' => 'required|array|min:1',
            'lines.*.supply_id' => 'required|exists:supplies,id',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($movement, $validated) {
            $movement->update([
                'warehouse_id' => $validated['warehouse_id'],
                'movement_reason_id' => $validated['movement_reason_id'],
                'third_party_id' => $validated['third_party_id'] ?? null,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'purchase_order' => $validated['purchase_order'] ?? null,
                'week' => $validated['week'] ?? null,
                'year' => $validated['year'] ?? null,
                'delivery_note' => $validated['delivery_note'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'vapor' => $validated['vapor'] ?? null,
            ]);

            // Reemplaza el detalle completo: más simple y confiable que
            // intentar hacer "diff" línea por línea.
            $movement->lines()->delete();

            foreach ($validated['lines'] as $line) {
                $quantity = $line['quantity'];
                $unitCost = $line['unit_cost'] ?? 0;
                $discount = $line['discount'] ?? 0;

                $movement->lines()->create([
                    'supply_id' => $line['supply_id'],
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'discount' => $discount,
                    'total' => ($quantity * $unitCost) - $discount,
                ]);
            }
        });

        $this->actualizarPreciosSiEsIngreso($validated['type'], $validated['lines'], $request->user()?->id);

        return response()->json(
            $movement->load(['warehouse', 'reason', 'thirdParty', 'lines.supply'])
        );
    }

    /**
     * Soft delete: nunca se borra físico.
     */
    public function destroy($id)
    {
        $movement = InventoryMovement::findOrFail($id);
        $movement->update(['status' => false]);

        return response()->json(['message' => 'Movimiento desactivado correctamente']);
    }

    /**
     * Si el movimiento es un INGRESO (compra real), actualiza el precio de
     * referencia (cost) de cada insumo según el precio de compra de esa línea,
     * y registra el cambio en supply_price_history. Política de la empresa:
     * sin margen, el precio de compra ES el precio que se cobra al productor.
     * DEVOLUCION nunca dispara esto: no es una compra, es material que vuelve.
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

    // Devuelve el stock actual de TODAS las bodegas a la vez, para la vista
    // general del jefe. Existencia = SUM(INGRESO) + SUM(DEVOLUCION) - SUM(EGRESO),
    // agrupado por bodega e insumo.
    // IMPORTANTE: antes el CASE solo distinguía INGRESO de "todo lo demás",
    // lo que hacía que una DEVOLUCION restara del stock en vez de sumar.
    // Corregido para tratar DEVOLUCION igual que INGRESO (el material vuelve
    // a bodega), y solo EGRESO resta.
    public function stockGeneral()
    {
        $stock = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'l.supply_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->select(
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                's.id as supply_id',
                's.name as supply_name',
                DB::raw("SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) as existencia")
            )
            ->groupBy('w.id', 'w.name', 's.id', 's.name')
            ->havingRaw("SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) > 0")
            ->orderBy('w.name')
            ->orderBy('s.name')
            ->get();

        return response()->json($stock);
    }

}