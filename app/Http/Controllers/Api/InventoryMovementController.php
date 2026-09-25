<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryMovementController extends Controller
{
    // Motivos permitidos por rol y tipo — BODEGUERO/JEFE_BODEGA solo pueden usar
    // movimientos operativos, nunca ajustes libres (exclusivo COORDINADOR_INVENTARIO/ADMIN).
    // "ENTREGA A PRODUCTOR" (id 4) ya no está aquí: ese flujo ahora vive en /despacho-materiales.
    private const MOTIVOS_PERMITIDOS_POR_ROL = [
        'BODEGUERO' => [
            'INGRESO' => [1],       // COMPRA A PROVEEDOR
            'EGRESO' => [5],        // TRANSFERENCIA A OTRA BODEGA
            'DEVOLUCION' => [7],    // DEVOLUCIÓN DE PRODUCTOR
        ],
        'JEFE_BODEGA' => [
            'INGRESO' => [1],
            'EGRESO' => [5],
            'DEVOLUCION' => [7],
        ],
    ];

    // Motivo 9 (TRANSFERENCIA DE OTRA BODEGA) es system-managed: solo lo crea
    // confirmTransfer() al confirmar una transferencia — nunca seleccionable a
    // mano, sin importar el rol (ni siquiera ADMIN).
    private const MOTIVO_SYSTEM_MANAGED = 9;

    private function bodegaAsignada($user): ?Warehouse
    {
        return Warehouse::where('responsible_user_id', $user->id)->first();
    }

    private function validarMotivoPermitido($user, string $type, int $movementReasonId): bool
    {
        if ($movementReasonId === self::MOTIVO_SYSTEM_MANAGED) {
            return false; // nunca manual, sin importar el rol
        }

        if (!$user || !isset(self::MOTIVOS_PERMITIDOS_POR_ROL[$user->role])) {
            return true; // COORDINADOR_INVENTARIO / ADMIN: sin restricción
        }

        $permitidos = self::MOTIVOS_PERMITIDOS_POR_ROL[$user->role][$type] ?? [];

        return in_array($movementReasonId, $permitidos);
    }

    /**
     * NUEVO — valida que un conjunto de líneas de un EGRESO no exceda el
     * stock disponible en la bodega. Reutilizada por store() y update().
     * $excludeMovementId permite excluir el propio movimiento del cálculo
     * (necesario en update(): las líneas viejas del movimiento que se está
     * editando no deben contarse como "ya descontadas" al validar las nuevas).
     * Devuelve el arreglo de insumos insuficientes (vacío si todo está OK).
     */
    private function validarStockLineas(int $warehouseId, array $lines, ?int $excludeMovementId = null): array
    {
        $insuficientes = [];

        foreach ($lines as $line) {
            $disponible = $this->stockDisponible($warehouseId, $line['supply_id'], $excludeMovementId);

            if ($line['quantity'] > $disponible) {
                $insuficientes[] = [
                    'supply_id' => $line['supply_id'],
                    'solicitado' => $line['quantity'],
                    'disponible' => $disponible,
                ];
            }
        }

        return $insuficientes;
    }

    public function index(Request $request)
    {
        $query = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy', 'purchaseOrder'])
            ->where('status', true);

        $user = $request->user();

        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            $query->where('warehouse_id', $bodega->id ?? 0);
        } elseif ($request->filled('warehouse_id')) {
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'movement_reason_id' => 'required|exists:movement_reasons,id',
            'third_party_id' => 'nullable|exists:third_parties,id',
            'type' => 'required|in:INGRESO,EGRESO,DEVOLUCION',
            'date' => 'required|date',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
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
            'lines.*.reception_note' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            if (!$bodega || (int) $validated['warehouse_id'] !== $bodega->id) {
                return response()->json([
                    'message' => 'No tienes permiso para registrar movimientos en esta bodega.',
                ], 403);
            }
        }

        if (!$this->validarMotivoPermitido($user, $validated['type'], (int) $validated['movement_reason_id'])) {
            return response()->json([
                'message' => 'Este motivo no está permitido para tu rol en este tipo de movimiento.',
            ], 403);
        }

        // NUEVO: validación de stock ANTES de crear nada, igual que en transfer().
        // Solo aplica a EGRESO — INGRESO y DEVOLUCION suman stock, no lo restan.
        if ($validated['type'] === 'EGRESO') {
            $insuficientes = $this->validarStockLineas($validated['warehouse_id'], $validated['lines']);

            if (!empty($insuficientes)) {
                return response()->json([
                    'message' => 'Stock insuficiente en la bodega para uno o más insumos.',
                    'insuficientes' => $insuficientes,
                ], 422);
            }
        }

        $movement = DB::transaction(function () use ($validated, $request) {
            $movement = InventoryMovement::create([
                'warehouse_id' => $validated['warehouse_id'],
                'movement_reason_id' => $validated['movement_reason_id'],
                'third_party_id' => $validated['third_party_id'] ?? null,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
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
                    'reception_note' => $line['reception_note'] ?? null,
                ]);
            }

            if ($validated['type'] === 'INGRESO' && !empty($validated['purchase_order_id'])) {
                $this->actualizarRecepcionOC($validated['purchase_order_id'], $validated['lines']);
            }

            return $movement;
        });

        $this->actualizarPreciosSiEsIngreso($validated['type'], $validated['lines'], $request->user()?->id);

        return response()->json(
            $movement->load(['warehouse', 'reason', 'thirdParty', 'lines.supply', 'purchaseOrder']),
            201
        );
    }

    public function show($id)
    {
        $movement = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy', 'lines.supply', 'purchaseOrder'])
            ->findOrFail($id);

        return response()->json($movement);
    }

    public function update(Request $request, $id)
    {
        $movement = InventoryMovement::findOrFail($id);

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'movement_reason_id' => 'required|exists:movement_reasons,id',
            'third_party_id' => 'nullable|exists:third_parties,id',
            'type' => 'required|in:INGRESO,EGRESO,DEVOLUCION',
            'date' => 'required|date',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
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
            'lines.*.reception_note' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            if (!$bodega || (int) $validated['warehouse_id'] !== $bodega->id) {
                return response()->json([
                    'message' => 'No tienes permiso para modificar movimientos de esta bodega.',
                ], 403);
            }
        }

        if (!$this->validarMotivoPermitido($user, $validated['type'], (int) $validated['movement_reason_id'])) {
            return response()->json([
                'message' => 'Este motivo no está permitido para tu rol en este tipo de movimiento.',
            ], 403);
        }

        // NUEVO: misma validación de stock que en store(), pero excluyendo
        // este propio movimiento del cálculo (sus líneas actuales están a
        // punto de ser reemplazadas, no deben contar como "ya descontadas").
        if ($validated['type'] === 'EGRESO') {
            $insuficientes = $this->validarStockLineas($validated['warehouse_id'], $validated['lines'], $movement->id);

            if (!empty($insuficientes)) {
                return response()->json([
                    'message' => 'Stock insuficiente en la bodega para uno o más insumos.',
                    'insuficientes' => $insuficientes,
                ], 422);
            }
        }

        DB::transaction(function () use ($movement, $validated) {
            $movement->update([
                'warehouse_id' => $validated['warehouse_id'],
                'movement_reason_id' => $validated['movement_reason_id'],
                'third_party_id' => $validated['third_party_id'] ?? null,
                'type' => $validated['type'],
                'date' => $validated['date'],
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'week' => $validated['week'] ?? null,
                'year' => $validated['year'] ?? null,
                'delivery_note' => $validated['delivery_note'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'vapor' => $validated['vapor'] ?? null,
            ]);

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
                    'reception_note' => $line['reception_note'] ?? null,
                ]);
            }

            if ($validated['type'] === 'INGRESO' && !empty($validated['purchase_order_id'])) {
                $this->actualizarRecepcionOC($validated['purchase_order_id'], $validated['lines']);
            }
        });

        $this->actualizarPreciosSiEsIngreso($validated['type'], $validated['lines'], $request->user()?->id);

        return response()->json(
            $movement->load(['warehouse', 'reason', 'thirdParty', 'lines.supply', 'purchaseOrder'])
        );
    }

    public function destroy($id)
    {
        $movement = InventoryMovement::findOrFail($id);
        $movement->update(['status' => false]);

        return response()->json(['message' => 'Movimiento desactivado correctamente']);
    }

    private function actualizarRecepcionOC(int $purchaseOrderId, array $lines)
    {
        foreach ($lines as $line) {
            $poLine = PurchaseOrderLine::where('purchase_order_id', $purchaseOrderId)
                ->where('supply_id', $line['supply_id'])
                ->first();

            if ($poLine) {
                $poLine->increment('quantity_received', $line['quantity']);
            }
        }

        $orden = PurchaseOrder::with('lines')->find($purchaseOrderId);
        if (!$orden) {
            return;
        }

        $todoCompleto = $orden->lines->every(
            fn($l) => $l->quantity_received >= $l->quantity_ordered
        );
        $algoRecibido = $orden->lines->contains(
            fn($l) => $l->quantity_received > 0
        );

        $orden->update([
            'status' => $todoCompleto ? 'COMPLETA' : ($algoRecibido ? 'PARCIAL' : 'PENDIENTE'),
        ]);
    }

    private function actualizarPreciosSiEsIngreso(string $type, array $lines, ?int $userId)
    {
        if ($type !== 'INGRESO') {
            return;
        }

        foreach ($lines as $line) {
            $supply = \App\Models\Supply::find($line['supply_id']);
            $nuevoCosto = $line['unit_cost'] ?? 0;

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

    /**
     * Calcula el stock disponible de UN insumo en UNA bodega, con la misma
     * fórmula que stockGeneral() (INGRESO/DEVOLUCION suman, EGRESO resta),
     * restringido a movimientos activos (status = true). Se usa para
     * validar transferencias y cualquier EGRESO (store/update/transfer).
     *
     * NUEVO: $excludeMovementId permite sacar un movimiento puntual del
     * cálculo — necesario en update() para no descontar dos veces las
     * líneas del propio movimiento que se está editando.
     */
    private function stockDisponible(int $warehouseId, int $supplyId, ?int $excludeMovementId = null): float
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

    public function stockGeneral(Request $request)
    {
        $user = $request->user();

        $warehouseFilter = null;
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            $warehouseFilter = $bodega->id ?? 0;
        } elseif ($request->filled('warehouse_id')) {
            $warehouseFilter = $request->warehouse_id;
        }

        // 1) Existencia actual por bodega + insumo — misma fórmula de siempre
        //    (INGRESO/DEVOLUCION suman, EGRESO resta), solo movimientos activos.
        $existenciaQuery = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'l.supply_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.status', true)
            ->select(
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                's.id as supply_id',
                's.name as supply_name',
                DB::raw("SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) as existencia")
            );

        if ($warehouseFilter !== null) {
            $existenciaQuery->where('w.id', $warehouseFilter);
        }

        $existencia = $existenciaQuery
            ->groupBy('w.id', 'w.name', 's.id', 's.name')
            ->havingRaw("SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) > 0")
            ->get()
            ->keyBy(fn($row) => $row->warehouse_id . '-' . $row->supply_id);

        // 2) Cantidades en tránsito: EGRESOs de transferencia (motivo 5)
        //    todavía en 'PENDIENTE', agrupadas por bodega DESTINO + insumo
        //    (no por la bodega origen).
        $transitoQuery = DB::table('inventory_movement_lines as tl')
            ->join('inventory_movements as tm', 'tm.id', '=', 'tl.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'tl.supply_id')
            ->join('warehouses as w', 'w.id', '=', 'tm.destination_warehouse_id')
            ->where('tm.status', true)
            ->where('tm.type', 'EGRESO')
            ->where('tm.movement_reason_id', 5)
            ->where('tm.transfer_status', 'PENDIENTE')
            ->select(
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                's.id as supply_id',
                's.name as supply_name',
                DB::raw('SUM(tl.quantity) as en_transito')
            );

        if ($warehouseFilter !== null) {
            $transitoQuery->where('w.id', $warehouseFilter);
        }

        $transito = $transitoQuery
            ->groupBy('w.id', 'w.name', 's.id', 's.name')
            ->get()
            ->keyBy(fn($row) => $row->warehouse_id . '-' . $row->supply_id);

        // 3) Merge en PHP: unión de ambos conjuntos de combos bodega+insumo.
        //    Así, un insumo que llega por primera vez a una bodega (sin
        //    existencia previa) también aparece, con existencia = 0 y
        //    en_transito > 0, en vez de quedar invisible hasta que llegue.
        $claves = $existencia->keys()->merge($transito->keys())->unique();

        $stock = $claves->map(function ($clave) use ($existencia, $transito) {
            $filaExistencia = $existencia->get($clave);
            $filaTransito = $transito->get($clave);
            $base = $filaExistencia ?? $filaTransito;

            return [
                'warehouse_id' => $base->warehouse_id,
                'warehouse_name' => $base->warehouse_name,
                'supply_id' => $base->supply_id,
                'supply_name' => $base->supply_name,
                'existencia' => $filaExistencia->existencia ?? 0,
                'en_transito' => $filaTransito->en_transito ?? 0,
            ];
        })
            ->sortBy([['warehouse_name', 'asc'], ['supply_name', 'asc']])
            ->values();

        return response()->json($stock);
    }

    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'source_warehouse_id' => 'required|exists:warehouses,id',
            'destination_warehouse_id' => 'required|different:source_warehouse_id|exists:warehouses,id',
            'date' => 'required|date',
            'week' => 'nullable|integer|min:1|max:53',
            'year' => 'nullable|integer|min:2000|max:2100',
            'delivery_note' => 'nullable|string|max:255',
            'reference' => 'nullable|string',

            'lines' => 'required|array|min:1',
            'lines.*.supply_id' => 'required|exists:supplies,id',
            'lines.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            if (!$bodega || (int) $validated['source_warehouse_id'] !== $bodega->id) {
                return response()->json([
                    'message' => 'Solo puedes transferir desde tu propia bodega.',
                ], 403);
            }
        }

        // Validación de stock ANTES de crear nada — reutiliza validarStockLineas().
        $insuficientes = $this->validarStockLineas($validated['source_warehouse_id'], $validated['lines']);

        if (!empty($insuficientes)) {
            return response()->json([
                'message' => 'Stock insuficiente en la bodega origen para uno o más insumos.',
                'insuficientes' => $insuficientes,
            ], 422);
        }

        $motivoEgreso = 5;

        $egreso = DB::transaction(function () use ($validated, $request, $motivoEgreso) {
            $egreso = InventoryMovement::create([
                'warehouse_id' => $validated['source_warehouse_id'],
                'destination_warehouse_id' => $validated['destination_warehouse_id'],
                'movement_reason_id' => $motivoEgreso,
                'type' => 'EGRESO',
                'date' => $validated['date'],
                'week' => $validated['week'] ?? null,
                'year' => $validated['year'] ?? null,
                'delivery_note' => $validated['delivery_note'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'created_by_user_id' => $request->user()?->id,
                'transfer_status' => 'PENDIENTE',
            ]);

            foreach ($validated['lines'] as $line) {
                $egreso->lines()->create([
                    'supply_id' => $line['supply_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => 0,
                    'discount' => 0,
                    'total' => 0,
                ]);
            }

            return $egreso;
        });

        return response()->json(
            $egreso->load(['warehouse', 'destinationWarehouse', 'reason', 'lines.supply']),
            201
        );
    }

    public function pendingTransfers(Request $request)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['COORDINADOR_INVENTARIO', 'ADMIN'])) {
            return response()->json([
                'message' => 'No tienes permiso para ver transferencias pendientes.',
            ], 403);
        }

        $pendientes = InventoryMovement::with(['warehouse', 'destinationWarehouse', 'reason', 'createdBy', 'lines.supply'])
            ->where('type', 'EGRESO')
            ->where('movement_reason_id', 5)
            ->where('transfer_status', 'PENDIENTE')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return response()->json($pendientes);
    }

    public function confirmTransfer(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['COORDINADOR_INVENTARIO', 'ADMIN'])) {
            return response()->json([
                'message' => 'No tienes permiso para confirmar transferencias.',
            ], 403);
        }

        $egreso = InventoryMovement::where('type', 'EGRESO')
            ->where('movement_reason_id', 5)
            ->findOrFail($id);

        if ($egreso->transfer_status !== 'PENDIENTE') {
            return response()->json([
                'message' => 'Esta transferencia ya fue confirmada o no está pendiente.',
            ], 422);
        }

        $validated = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.supply_id' => 'required|exists:supplies,id',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.reception_note' => 'nullable|string|max:500',
        ]);

        $motivoIngreso = 9;

        $ingreso = DB::transaction(function () use ($egreso, $validated, $request, $motivoIngreso) {
            $ingreso = InventoryMovement::create([
                'warehouse_id' => $egreso->destination_warehouse_id,
                'movement_reason_id' => $motivoIngreso,
                'type' => 'INGRESO',
                'date' => now()->toDateString(),
                'delivery_note' => $egreso->delivery_note,
                'reference' => $egreso->reference,
                'created_by_user_id' => $request->user()?->id,
                'linked_movement_id' => $egreso->id,
                'transfer_status' => 'CONFIRMADA',
            ]);

            foreach ($validated['lines'] as $line) {
                $ingreso->lines()->create([
                    'supply_id' => $line['supply_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => 0,
                    'discount' => 0,
                    'total' => 0,
                    'reception_note' => $line['reception_note'] ?? null,
                ]);
            }

            $egreso->update([
                'linked_movement_id' => $ingreso->id,
                'transfer_status' => 'CONFIRMADA',
            ]);

            return $ingreso;
        });

        return response()->json([
            'egreso' => $egreso->fresh()->load(['warehouse', 'destinationWarehouse', 'reason', 'lines.supply']),
            'ingreso' => $ingreso->load(['warehouse', 'reason', 'lines.supply']),
        ], 201);
    }

    public function cancelTransfer(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['COORDINADOR_INVENTARIO', 'ADMIN'])) {
            return response()->json([
                'message' => 'No tienes permiso para cancelar transferencias.',
            ], 403);
        }

        $egreso = InventoryMovement::where('type', 'EGRESO')
            ->where('movement_reason_id', 5)
            ->findOrFail($id);

        if ($egreso->transfer_status !== 'PENDIENTE') {
            return response()->json([
                'message' => 'Esta transferencia ya no está pendiente, no se puede cancelar.',
            ], 422);
        }

        // Mismo patrón deactivate/reactivate de todo el sistema — nunca
        // DELETE. Al poner status = false, el EGRESO deja de contar en
        // stockDisponible()/stockGeneral() (que filtran m.status = true),
        // así que el stock "regresa" solo a la bodega origen sin necesidad
        // de crear ningún movimiento inverso.
        $egreso->update([
            'status' => false,
            'transfer_status' => 'CANCELADA',
        ]);

        return response()->json([
            'message' => 'Transferencia cancelada. El stock permanece en la bodega de origen.',
            'egreso' => $egreso->fresh()->load(['warehouse', 'destinationWarehouse', 'reason', 'lines.supply']),
        ]);
    }
}