<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\ProducerQuota;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    /**
     * NUEVO (28/10/2026) — Bloquea la bodega y vuelve a validar el stock DENTRO de la
     * transacción. La validación de antes (fuera de la transacción) responde rápido al
     * usuario, pero entre esa validación y el guardado otro usuario podía sacar el mismo
     * material. Si ya no alcanza, se lanza un 422 y DB::transaction() deshace todo.
     */
    private function bloquearYValidarStock(int $warehouseId, array $lines, ?int $excludeMovementId = null, string $mensaje = 'Stock insuficiente en la bodega para uno o más insumos.'): void
    {
        InventoryMovement::bloquearBodega($warehouseId);

        $insuficientes = $this->validarStockLineas($warehouseId, $lines, $excludeMovementId);
        if (!empty($insuficientes)) {
            throw new HttpResponseException(response()->json([
                'message' => $mensaje,
                'insuficientes' => $insuficientes,
            ], 422));
        }
    }

    // Motivos que tienen su propio flujo y NO se editan/anulan como movimiento suelto:
    // 5 = transferencia (se cancela con cancel-transfer), 9 = ingreso de transferencia (lo crea el sistema).
    private const MOTIVOS_CON_FLUJO_PROPIO = [5, 9];

    // Devuelve un mensaje si el movimiento no se puede editar/anular por aquí (null si sí se puede)
    private function bloqueoEdicion(InventoryMovement $movement, bool $anular = false): ?string
    {
        if (in_array($movement->movement_reason_id, self::MOTIVOS_CON_FLUJO_PROPIO)) {
            return 'Las transferencias se gestionan desde Transferencias Pendientes, no se editan aquí.';
        }
        // 29/10/2026: devolución ligada a un cupo → se puede anular (y registrar de nuevo),
        // no editar; y nada si el cupo ya se liquidó (el valor ya se descontó al productor)
        if ($movement->producer_quota_id && $movement->type === 'DEVOLUCION') {
            if ($movement->producerQuota?->status === ProducerQuota::LIQUIDADO) {
                return 'El cupo de esta devolución ya fue liquidado: no se puede modificar.';
            }
            if (!$anular) {
                return 'Una devolución ligada a un cupo no se edita: anúlala y regístrala de nuevo.';
            }
        } elseif ($movement->producer_quota_id) {
            return 'Los despachos de materiales se anulan desde el Historial de Despachos.';
        }
        if (!$movement->status) {
            return 'Este movimiento ya está anulado.';
        }
        return null;
    }

    /**
     * 29/10/2026 — Cupos (productor + semana) sin liquidar con material retirado, y lo que
     * aún puede devolver de cada insumo. Lo usa Nuevo movimiento → DEVOLUCIÓN.
     * GET /inventory-movements/returnable-quotas/{thirdPartyId}
     */
    public function returnableQuotas($thirdPartyId)
    {
        $cupos = ProducerQuota::where('third_party_id', $thirdPartyId)
            ->where('status', '!=', ProducerQuota::LIQUIDADO)
            ->whereHas('dispatches', fn ($q) => $q->where('type', 'EGRESO')->where('status', true))
            ->orderByDesc('year')->orderByDesc('week_number')
            ->get(['id', 'week_number', 'year', 'status']);

        return response()->json($cupos->map(fn ($c) => [
            'id'       => $c->id,
            'week'     => $c->week_number,
            'year'     => $c->year,
            'supplies' => $this->materialesDelCupo($c->id)
                ->map(fn ($m) => [
                    'supply_id' => $m->supply_id,
                    'name'      => $m->name,
                    'unit'      => $m->unit,
                    'retirado'  => (float) $m->retirado,
                    'devuelto'  => (float) $m->devuelto,
                    'pendiente' => round((float) $m->retirado - (float) $m->devuelto, 2),
                ])
                ->filter(fn ($m) => $m['pendiente'] > 0)->values(),
        ])->filter(fn ($c) => $c['supplies']->isNotEmpty())->values());
    }

    // Por insumo: retirado (despachos activos) y devuelto (devoluciones activas) de un cupo
    private function materialesDelCupo(int $cupoId)
    {
        return DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'l.supply_id')
            ->where('m.producer_quota_id', $cupoId)
            ->where('m.status', true)
            ->whereIn('m.type', ['EGRESO', 'DEVOLUCION'])
            ->groupBy('l.supply_id', 's.name', 's.unit')
            ->selectRaw("l.supply_id, s.name, s.unit,
                SUM(CASE WHEN m.type = 'EGRESO' THEN l.quantity ELSE 0 END) as retirado,
                SUM(CASE WHEN m.type = 'DEVOLUCION' THEN l.quantity ELSE 0 END) as devuelto")
            ->orderBy('s.name')
            ->get()
            ->keyBy('supply_id');
    }

    /**
     * Valida la devolución contra el cupo y devuelve su id (null = devolución sin cupo).
     * Si el productor tiene material retirado en cupos sin liquidar, el cupo es obligatorio:
     * así la devolución se resta de su liquidación. No se devuelve más de lo retirado.
     * Se llama dentro de la transacción (bloquea el cupo frente a una liquidación simultánea).
     */
    private function validarDevolucionCupo(array $v): ?int
    {
        $fallar = fn (string $m) => throw new HttpResponseException(response()->json(['message' => $m], 422));
        $cupoId = $v['producer_quota_id'] ?? null;
        $terceroId = $v['third_party_id'] ?? null;

        if (!$cupoId) {
            $tieneAbiertos = $terceroId && ProducerQuota::where('third_party_id', $terceroId)
                ->where('status', '!=', ProducerQuota::LIQUIDADO)
                ->whereHas('dispatches', fn ($q) => $q->where('type', 'EGRESO')->where('status', true))
                ->exists();
            if ($tieneAbiertos) {
                $fallar('Este productor tiene materiales retirados en semanas sin liquidar: elige la semana (cupo) a la que devuelve.');
            }
            return null;
        }

        $cupo = ProducerQuota::lockForUpdate()->find($cupoId);
        if ((int) $cupo->third_party_id !== (int) $terceroId) {
            $fallar('El cupo elegido no es de este productor.');
        }
        if ($cupo->status === ProducerQuota::LIQUIDADO) {
            $fallar("El cupo de la semana {$cupo->week_number} ya fue liquidado: no se le puede devolver material.");
        }

        $saldo = $this->materialesDelCupo($cupo->id);
        $pedido = collect($v['lines'])->groupBy('supply_id')->map(fn ($ls) => $ls->sum('quantity'));
        foreach ($pedido as $supplyId => $cantidad) {
            $m = $saldo->get($supplyId);
            $pendiente = $m ? round((float) $m->retirado - (float) $m->devuelto, 2) : 0;
            if ($cantidad > $pendiente) {
                $nombre = $m->name ?? DB::table('supplies')->where('id', $supplyId)->value('name');
                $fallar("$nombre: se devuelven " . (float) $cantidad . " pero de la semana {$cupo->week_number} solo quedan $pendiente por devolver.");
            }
        }

        return $cupo->id;
    }

    // NUEVO: una OC cancelada o cerrada no puede recibir mercadería (antes la "revivía" a PARCIAL/COMPLETA)
    // 29/10/2026: además, solo se reciben insumos de la OC y nunca más de lo pendiente
    // (antes una OC COMPLETA seguía aceptando mercadería). Al editar un ingreso, lo que ese
    // mismo ingreso ya había recibido vuelve a contar como pendiente.
    private function validarOrdenRecibible(?int $purchaseOrderId, array $lines = [], ?InventoryMovement $movimiento = null): ?string
    {
        if (!$purchaseOrderId) {
            return null;
        }
        $orden = PurchaseOrder::find($purchaseOrderId);
        if ($orden && in_array($orden->status, ['CANCELADA', 'CERRADA'])) {
            return "La orden de compra {$orden->code} está {$orden->status}: no se puede recibir mercadería contra ella.";
        }

        $propio = $movimiento && $movimiento->status && (int) $movimiento->purchase_order_id === $purchaseOrderId
            ? $movimiento->lines()->get()->groupBy('supply_id')->map(fn ($ls) => (float) $ls->sum('quantity'))
            : collect();
        $lineasOC = PurchaseOrderLine::with('supply:id,name')->where('purchase_order_id', $purchaseOrderId)->get()->keyBy('supply_id');

        foreach (collect($lines)->groupBy('supply_id') as $supplyId => $ls) {
            $cantidad = (float) $ls->sum('quantity');
            $lineaOC = $lineasOC->get($supplyId);
            if (!$lineaOC) {
                return "Hay un insumo que no está en la orden de compra {$orden->code}.";
            }
            $pendiente = (float) $lineaOC->quantity_ordered - (float) $lineaOC->quantity_received + (float) ($propio[$supplyId] ?? 0);
            if ($cantidad > $pendiente + 0.0001) {
                return "{$lineaOC->supply?->name}: se reciben " . (float) $cantidad . " pero de la orden {$orden->code} solo quedan " . max(0, round($pendiente, 2)) . ' por recibir.';
            }
        }

        return null;
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
            // Solo DEVOLUCION: cupo (productor + semana) al que vuelve el material
            'producer_quota_id' => 'nullable|integer|exists:producer_quotas,id',

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

        if ($validated['type'] === 'INGRESO' && ($mensaje = $this->validarOrdenRecibible($validated['purchase_order_id'] ?? null, $validated['lines']))) {
            return response()->json(['message' => $mensaje], 422);
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
            // 28/10/2026: bloqueo de la bodega + revalidación (evita doble salida simultánea)
            if ($validated['type'] === 'EGRESO') {
                $this->bloquearYValidarStock((int) $validated['warehouse_id'], $validated['lines']);
            }

            // 29/10/2026: devolución del productor ligada al cupo de la semana (se resta en su liquidación)
            $cupoId = $validated['type'] === 'DEVOLUCION' ? $this->validarDevolucionCupo($validated) : null;

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
                'producer_quota_id' => $cupoId,
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

    public function show(Request $request, $id)
    {
        $movement = InventoryMovement::with(['warehouse', 'reason', 'thirdParty', 'createdBy', 'lines.supply', 'purchaseOrder'])
            ->findOrFail($id);

        // 28/10/2026: un BODEGUERO solo ve movimientos de su bodega (origen o destino).
        // Antes podía abrir cualquier recibo cambiando el número en la URL.
        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            $bodegaId = $this->bodegaAsignada($user)?->id;
            if (!$bodegaId || ($movement->warehouse_id !== $bodegaId && $movement->destination_warehouse_id !== $bodegaId)) {
                return response()->json(['message' => 'No tienes permiso para ver este movimiento.'], 403);
            }
        }

        return response()->json($movement);
    }

    // Solo COORDINADOR_INVENTARIO/ADMIN (middleware 'rol' en routes/api.php)
    public function update(Request $request, $id)
    {
        $movement = InventoryMovement::findOrFail($id);

        if ($mensaje = $this->bloqueoEdicion($movement)) {
            return response()->json(['message' => $mensaje], 422);
        }

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

        if ($validated['type'] === 'INGRESO' && ($mensaje = $this->validarOrdenRecibible($validated['purchase_order_id'] ?? null, $validated['lines'], $movement))) {
            return response()->json(['message' => $mensaje], 422);
        }

        DB::transaction(function () use ($movement, $validated) {
            if ($validated['type'] === 'EGRESO') {
                $this->bloquearYValidarStock((int) $validated['warehouse_id'], $validated['lines'], $movement->id);
            }

            // 28/10/2026: antes de reemplazar las líneas se DESCUENTA lo que este movimiento
            // había recibido en su OC. Antes se volvía a sumar todo encima → la OC quedaba
            // con el doble de recibido (y marcaba COMPLETA sin estarlo).
            $this->revertirRecepcionOC($movement);

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

            $movement->lines()->get()->each->delete(); // uno por uno: queda en la bitácora

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

    // Solo COORDINADOR_INVENTARIO/ADMIN (middleware 'rol' en routes/api.php). Baja lógica.
    public function destroy($id)
    {
        $movement = InventoryMovement::findOrFail($id);

        if ($mensaje = $this->bloqueoEdicion($movement, true)) {
            return response()->json(['message' => $mensaje], 422);
        }

        // Anular un INGRESO o DEVOLUCIÓN resta stock: se verifica que la bodega no quede
        // negativa (ese material ya pudo haber salido en otro EGRESO).
        if (in_array($movement->type, ['INGRESO', 'DEVOLUCION'])) {
            $lineas = $movement->lines()->get(['supply_id', 'quantity'])->toArray();
            foreach ($lineas as $l) {
                $disponibleSinEste = InventoryMovement::stockDisponible($movement->warehouse_id, $l['supply_id'], $movement->id);
                if ($disponibleSinEste < 0) {
                    return response()->json([
                        'message' => 'No se puede anular: parte de este ingreso ya salió de la bodega y el stock quedaría negativo.',
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($movement) {
            InventoryMovement::bloquearBodega($movement->warehouse_id);
            $this->revertirRecepcionOC($movement); // devuelve a la OC lo que este ingreso había recibido
            $movement->update(['status' => false]);
        });

        return response()->json(['message' => 'Movimiento desactivado correctamente']);
    }

    // NUEVO: deshace en la OC lo recibido por un INGRESO (al editarlo o anularlo)
    private function revertirRecepcionOC(InventoryMovement $movement): void
    {
        if ($movement->type !== 'INGRESO' || !$movement->purchase_order_id || !$movement->status) {
            return;
        }

        foreach ($movement->lines()->get(['supply_id', 'quantity']) as $line) {
            $poLine = PurchaseOrderLine::where('purchase_order_id', $movement->purchase_order_id)
                ->where('supply_id', $line->supply_id)
                ->first();
            if ($poLine) {
                // Nunca por debajo de cero
                $poLine->update(['quantity_received' => max(0, $poLine->quantity_received - $line->quantity)]);
            }
        }

        $this->recalcularEstadoOC($movement->purchase_order_id);
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

        $this->recalcularEstadoOC($purchaseOrderId);
    }

    // Estado de la OC según lo recibido. Una OC CANCELADA o CERRADA se queda así
    // (antes recibir mercadería la "revivía" a PARCIAL/COMPLETA).
    private function recalcularEstadoOC(int $purchaseOrderId): void
    {
        $orden = PurchaseOrder::with('lines')->find($purchaseOrderId);
        if (!$orden || in_array($orden->status, ['CANCELADA', 'CERRADA'])) {
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
            // 28/10/2026: bloqueo de la bodega origen + revalidación (evita doble salida simultánea)
            $this->bloquearYValidarStock((int) $validated['source_warehouse_id'], $validated['lines'], null, 'Stock insuficiente en la bodega origen para uno o más insumos.');

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

        // 28/10/2026: lo recibido debe ser de los insumos enviados y NUNCA más de lo enviado
        // (puede ser menos: algo se perdió o dañó en el traslado). Antes se aceptaba
        // cualquier insumo y cualquier cantidad → se "creaba" stock en la bodega destino.
        $enviado = $egreso->lines()->get()->groupBy('supply_id')->map(fn ($ls) => (float) $ls->sum('quantity'));
        $recibido = collect($validated['lines'])->groupBy('supply_id')->map(fn ($ls) => (float) $ls->sum('quantity'));
        foreach ($recibido as $supplyId => $cantidad) {
            if (!$enviado->has($supplyId)) {
                return response()->json(['message' => 'Hay insumos que no estaban en la transferencia enviada.'], 422);
            }
            if ($cantidad > $enviado[$supplyId] + 0.0001) {
                return response()->json([
                    'message' => "Se recibe más de lo enviado en un insumo ({$cantidad} recibido, {$enviado[$supplyId]} enviado).",
                ], 422);
            }
        }

        $motivoIngreso = 9;

        $ingreso = DB::transaction(function () use ($egreso, $validated, $request, $motivoIngreso) {
            // Bloquea la fila del EGRESO: si dos coordinadores confirman a la vez,
            // el segundo ve que ya no está PENDIENTE (antes se creaban dos INGRESOS)
            $bloqueado = InventoryMovement::whereKey($egreso->id)->lockForUpdate()->first();
            if ($bloqueado->transfer_status !== 'PENDIENTE') {
                throw new HttpResponseException(response()->json([
                    'message' => 'Esta transferencia ya fue confirmada o no está pendiente.',
                ], 422));
            }

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