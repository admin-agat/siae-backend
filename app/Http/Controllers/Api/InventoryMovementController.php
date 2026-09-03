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
    /**
     * Busca la bodega donde el usuario autenticado es responsable
     * (responsible_user_id). Se usa para restringir a los BODEGUERO a
     * solo su propia bodega en index/store/update/stockGeneral.
     * Devuelve null si el usuario no tiene ninguna bodega asignada.
     */
    private function bodegaAsignada($user): ?Warehouse
    {
        return Warehouse::where('responsible_user_id', $user->id)->first();
    }

    /**
     * Lista movimientos con sus relaciones básicas.
     * Filtros opcionales: warehouse_id, type (INGRESO/EGRESO/DEVOLUCION), date desde/hasta.
     */
    public function index(Request $request)
    {
        $query = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy', 'purchaseOrder'])
            ->where('status', true);

        $user = $request->user();

        // Un BODEGUERO solo puede ver movimientos de SU bodega asignada.
        // Se ignora cualquier warehouse_id que mande el frontend: la
        // restricción manda siempre del lado del backend, no del filtro
        // que el cliente decida enviar.
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            // Si no tiene bodega asignada, 0 no existe como id -> no ve nada
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

    /**
     * Crea un movimiento (cabecera) junto con todas sus líneas de producto,
     * dentro de una transacción: si una línea falla, no se guarda nada.
     *
     * Si el movimiento es un INGRESO ligado a una Orden de Compra
     * (purchase_order_id), además de crear el movimiento se actualiza
     * cuánto se ha recibido de cada línea de esa OC y se recalcula su
     * estado (PENDIENTE / PARCIAL / COMPLETA) — ver actualizarRecepcionOC().
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
            // Reemplaza al viejo campo de texto libre "purchase_order":
            // ahora es una relación real a la tabla purchase_orders.
            // Solo aplica a INGRESO (una compra que se recibe); EGRESO y
            // DEVOLUCION no llevan OC, por eso es nullable.
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
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

        // Un BODEGUERO solo puede guardar movimientos de SU propia bodega,
        // aunque manipule el request y mande otro warehouse_id a mano.
        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            if (!$bodega || (int) $validated['warehouse_id'] !== $bodega->id) {
                return response()->json([
                    'message' => 'No tienes permiso para registrar movimientos en esta bodega.',
                ], 403);
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
                ]);
            }

            // Si es un Ingreso ligado a una OC, se registra lo recibido y
            // se recalcula el estado de la orden. Va DENTRO de la
            // transacción: si algo falla, ni el movimiento ni la
            // actualización de la OC quedan a medias.
            if ($validated['type'] === 'INGRESO' && !empty($validated['purchase_order_id'])) {
                $this->actualizarRecepcionOC($validated['purchase_order_id'], $validated['lines']);
            }

            return $movement;
        });

        // Solo un INGRESO real (compra) actualiza el precio de referencia;
        // una DEVOLUCION no es una compra, así que no dispara este ajuste.
        $this->actualizarPreciosSiEsIngreso($validated['type'], $validated['lines'], $request->user()?->id);

        return response()->json(
            $movement->load(['warehouse', 'reason', 'thirdParty', 'lines.supply', 'purchaseOrder']),
            201
        );
    }

    /**
     * Muestra un movimiento con su detalle completo.
     */
    public function show($id)
    {
        $movement = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy', 'lines.supply', 'purchaseOrder'])
            ->findOrFail($id);

        return response()->json($movement);
    }

    /**
     * Actualiza la cabecera y REEMPLAZA todas las líneas (se borran las
     * viejas y se crean las nuevas), dentro de una transacción.
     *
     * OJO con la recepción de OC en un update: si el movimiento ya había
     * sumado cantidades a purchase_order_lines.quantity_received y el
     * usuario edita las cantidades, aquí NO se resta lo viejo antes de
     * sumar lo nuevo (revertir automáticamente es más riesgoso que útil
     * para este caso de uso). Por eso, si hay que corregir una recepción
     * ya guardada, mejor hacerlo con un ajuste manual en la OC en vez de
     * editar el movimiento — dejamos esto documentado para no olvidarlo.
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
        ]);

        // Igual que en store(): un BODEGUERO no puede editar (ni mover) un
        // movimiento hacia una bodega que no es la suya. Se valida contra
        // el warehouse_id NUEVO que viene en el request, que es el que
        // terminaría guardado.
        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            if (!$bodega || (int) $validated['warehouse_id'] !== $bodega->id) {
                return response()->json([
                    'message' => 'No tienes permiso para modificar movimientos de esta bodega.',
                ], 403);
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

            if ($validated['type'] === 'INGRESO' && !empty($validated['purchase_order_id'])) {
                $this->actualizarRecepcionOC($validated['purchase_order_id'], $validated['lines']);
            }
        });

        $this->actualizarPreciosSiEsIngreso($validated['type'], $validated['lines'], $request->user()?->id);

        return response()->json(
            $movement->load(['warehouse', 'reason', 'thirdParty', 'lines.supply', 'purchaseOrder'])
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
     * Cierra el ciclo Orden de Compra → Ingreso: por cada línea del
     * movimiento, suma la cantidad recibida a la línea correspondiente
     * de la OC (buscada por purchase_order_id + supply_id). Se SUMA
     * (increment) en vez de reemplazar, para soportar entregas parciales
     * en varias fechas (ej. hoy llegan 60 de 100, la próxima semana 40 más).
     *
     * Después recalcula el estado de la orden completa:
     * - COMPLETA: todas las líneas recibieron >= lo pedido
     * - PARCIAL: al menos una línea recibió algo, pero no todo llegó
     * - PENDIENTE: no ha llegado nada todavía (no debería pasar aquí,
     *   ya que este método solo se llama cuando SÍ hay un Ingreso, pero
     *   se deja como caso de resguardo)
     */
    private function actualizarRecepcionOC(int $purchaseOrderId, array $lines)
    {
        foreach ($lines as $line) {
            $poLine = PurchaseOrderLine::where('purchase_order_id', $purchaseOrderId)
                ->where('supply_id', $line['supply_id'])
                ->first();

            // Si el insumo recibido no corresponde a ninguna línea de esa OC
            // (ej. error de digitación), simplemente no se actualiza nada
            // de la OC para esa línea — no rompe el guardado del movimiento.
            if ($poLine) {
                $poLine->increment('quantity_received', $line['quantity']);
            }
        }

        $orden = PurchaseOrder::with('lines')->find($purchaseOrderId);
        if (!$orden) {
            return;
        }

        $todoCompleto = $orden->lines->every(
            fn ($l) => $l->quantity_received >= $l->quantity_ordered
        );
        $algoRecibido = $orden->lines->contains(
            fn ($l) => $l->quantity_received > 0
        );

        $orden->update([
            'status' => $todoCompleto ? 'COMPLETA' : ($algoRecibido ? 'PARCIAL' : 'PENDIENTE'),
        ]);
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
    public function stockGeneral(Request $request)
    {
        $query = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'l.supply_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->select(
                'w.id as warehouse_id',
                'w.name as warehouse_name',
                's.id as supply_id',
                's.name as supply_name',
                DB::raw("SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) as existencia")
            );

        // Un BODEGUERO solo ve el stock de SU bodega asignada. Si no tiene
        // ninguna asignada, se filtra por un id que no existe (0) para que
        // devuelva vacío en vez de mostrar todo por error.
        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            $query->where('w.id', $bodega->id ?? 0);
        }

        $stock = $query
            ->groupBy('w.id', 'w.name', 's.id', 's.name')
            ->havingRaw("SUM(CASE WHEN m.type IN ('INGRESO', 'DEVOLUCION') THEN l.quantity ELSE -l.quantity END) > 0")
            ->orderBy('w.name')
            ->orderBy('s.name')
            ->get();

        return response()->json($stock);
    }
}