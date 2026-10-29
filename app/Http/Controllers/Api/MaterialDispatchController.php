<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\MaterialRecipe;
use App\Models\ProducerQuota;
use App\Models\Warehouse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialDispatchController extends Controller
{
    // movement_reasons.id de "ENTREGA A PRODUCTOR" (tipo EGRESO)
    private const MOTIVO_ENTREGA_PRODUCTOR = 4;

    // Roles que pueden ANULAR un despacho (el bodeguero no)
    private const ROLES_ANULAN = ['COORDINADOR_INVENTARIO', 'ADMIN'];

    /* =================================================================
     |  ENDPOINTS
     |  Regla: UN RETIRO = UNA SOLA MARCA. Si el productor lleva dos marcas
     |  en el mismo viaje, se registran dos retiros (uno por marca).
     * ================================================================= */

    /**
     * POST /material-dispatch/calculate
     * Body: { warehouse_id, third_party_id (opcional), brand_code, cajas }
     * Si llega third_party_id, descuenta lo ya retirado de ESA MARCA esta semana.
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id'      => 'required|exists:warehouses,id',
            'third_party_id'    => 'nullable|exists:third_parties,id',
            'brand_code'        => 'required|string',
            'cajas'             => 'required|integer|min:1',
            'producer_quota_id' => 'nullable|exists:producer_quotas,id', // cupo asignado por Comercial
        ]);

        $marca = $this->marcaActiva($validated['brand_code']);

        // Fase 3: si se eligió un cupo de Comercial, las cajas son las ASIGNADAS de esa marca
        $cupoComercial = null;
        if (!empty($validated['producer_quota_id'])) {
            $cupoExistente = $cupoComercial = $this->cupoComercialValido((int) $validated['producer_quota_id'], (int) ($validated['third_party_id'] ?? 0), $marca->code, (int) $validated['warehouse_id']);
            $validated['cajas'] = $this->cajasAsignadas($cupoExistente, $marca->code, (int) $validated['warehouse_id']);
        } else {
            // Cupo manual de este productor en la semana actual (si ya retiró antes)
            $cupoExistente = !empty($validated['third_party_id'])
                ? $this->cupoDeLaSemana((int) $validated['third_party_id'])
                : null;
        }

        $materiales = $this->calcularMateriales(
            $marca,
            (int) $validated['cajas'],
            (int) $validated['warehouse_id'],
            $this->yaDespachado($cupoExistente?->id, $marca->id, $this->bodegaParaDescontar($cupoComercial, $marca->code, (int) $validated['warehouse_id']))
        );

        if ($materiales->isEmpty()) {
            throw ValidationException::withMessages([
                'brand_code' => "La marca {$marca->name} no tiene receta activa.",
            ]);
        }

        return response()->json([
            'materiales'     => $materiales->sortBy('name')->values(),
            // La pantalla lo usa para avisar "este productor ya tiene cupo esta semana"
            'cupo_existente' => $cupoExistente?->load('lines'),
            'cajas'          => (int) $validated['cajas'],
        ]);
    }

    /**
     * GET /material-dispatch/quotas/{thirdPartyId} — Fase 3 (28/10/2026)
     * Cupos que Comercial le asignó a este productor/comercializadora y que aún no están
     * liquidados, por semana y marca. La pantalla de despacho los muestra para elegir.
     */
    // 29/10/2026: una fila por cupo + marca + BODEGA DE RETIRO (la que eligió Comercial en la
    // compra). La pantalla solo deja elegir las de la bodega desde la que se despacha.
    public function quotas($thirdPartyId)
    {
        $cupos = ProducerQuota::with(['lines' => fn ($q) => $q->whereNotNull('customer_id')])
            ->where('third_party_id', $thirdPartyId)
            ->whereNotNull('customer_order_id')
            ->where('status', '!=', ProducerQuota::LIQUIDADO)
            ->orderBy('year')->orderBy('week_number')
            ->get();

        $marcas = DB::table('brands')->pluck('name', 'code');
        $bodegas = DB::table('warehouses')->pluck('name', 'id');
        $resultado = [];
        foreach ($cupos as $cupo) {
            foreach ($cupo->lines->groupBy(fn ($l) => $l->variety . '|' . $l->pickup_warehouse_id) as $lineas) {
                $codigo = $lineas->first()->variety;
                $bodegaId = $lineas->first()->pickup_warehouse_id;
                $resultado[] = [
                    'producer_quota_id' => $cupo->id,
                    'week'              => $cupo->week_number,
                    'year'              => $cupo->year,
                    'status'            => $cupo->status,
                    'brand_code'        => $codigo,
                    'brand_name'        => $marcas[$codigo] ?? $codigo,
                    'cajas'             => (int) $lineas->sum('quantity_cajas'),
                    'warehouse_id'      => $bodegaId,                     // null = cualquier bodega
                    'warehouse_name'    => $bodegaId ? ($bodegas[$bodegaId] ?? null) : null,
                ];
            }
        }

        return response()->json($resultado);
    }

    /**
     * POST /material-dispatch
     * Crea (o reutiliza) el cupo de la semana, actualiza SOLO la línea de la marca
     * del retiro y crea el EGRESO con sus líneas, todo en una transacción.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id'         => 'required|exists:warehouses,id',
            'third_party_id'       => 'required|exists:third_parties,id',
            'brand_code'           => 'required|string',
            'cajas'                => 'required|integer|min:1',
            'vapor'                => 'nullable|string|max:255',
            'delivery_note'        => 'nullable|string|max:255',
            'received_by_name'     => 'required|string|max:150',
            'received_by_document' => 'required|string|max:20',
            'lines'                => 'required|array|min:1',
            'lines.*.supply_id'    => 'required|exists:supplies,id',
            'lines.*.quantity'     => 'required|numeric|min:0',
            'producer_quota_id'    => 'nullable|exists:producer_quotas,id', // cupo asignado por Comercial
        ]);

        $user        = $request->user();
        $warehouseId = (int) $validated['warehouse_id'];
        $terceroId   = (int) $validated['third_party_id'];
        $cajas       = (int) $validated['cajas'];
        $marca       = $this->marcaActiva($validated['brand_code']);

        // Fase 3 (28/10/2026): el cupo lo da COMERCIAL. Si se eligió uno, las cajas son las
        // asignadas y el cupo NO se modifica (antes el despacho creaba/reemplazaba las cajas).
        $cupoComercial = !empty($validated['producer_quota_id'])
            ? $this->cupoComercialValido((int) $validated['producer_quota_id'], $terceroId, $marca->code, $warehouseId)
            : null;
        if ($cupoComercial) {
            $cajas = $this->cajasAsignadas($cupoComercial, $marca->code, $warehouseId);
        } elseif ($this->tieneCupoComercialAbierto($terceroId)) {
            return response()->json([
                'message' => 'Este productor tiene cupo asignado por Comercial: selecciónalo en la lista de cupos.',
            ], 422);
        }

        // 1) BODEGUERO solo despacha desde su propia bodega (misma regla que Nuevo Movimiento)
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = Warehouse::where('responsible_user_id', $user->id)->first();
            if (!$bodega || $bodega->id !== $warehouseId) {
                return response()->json([
                    'message' => 'Solo puedes despachar desde tu propia bodega.',
                ], 403);
            }
        }

        // 2) Si el cupo de la semana ya está LIQUIDADO, no se despacha más contra él
        $cupoExistente = $cupoComercial ?? $this->cupoDeLaSemana($terceroId);
        if ($cupoExistente && $cupoExistente->status === ProducerQuota::LIQUIDADO) {
            return response()->json([
                'message' => 'El cupo de esta semana ya fue liquidado. No se puede despachar más material.',
            ], 422);
        }

        // 3) Recalcula en el SERVIDOR (no se confía en el recomendado que manda la pantalla)
        $materiales = $this->calcularMateriales(
            $marca,
            $cajas,
            $warehouseId,
            $this->yaDespachado($cupoExistente?->id, $marca->id, $this->bodegaParaDescontar($cupoComercial, $marca->code, $warehouseId))
        );

        // 4) Solo líneas con cantidad > 0. keyBy evita que el mismo insumo llegue dos veces.
        $lineas = collect($validated['lines'])
            ->filter(fn ($l) => $l['quantity'] > 0)
            ->keyBy('supply_id');

        if ($lineas->isEmpty()) {
            return response()->json([
                'message' => 'Ingresa al menos un material a retirar.',
            ], 422);
        }

        // 5) Solo insumos que estén en la receta de ESTA marca
        $fueraDeReceta = $lineas->keys()->reject(fn ($id) => $materiales->has($id));
        if ($fueraDeReceta->isNotEmpty()) {
            return response()->json([
                'message'         => "Hay insumos que no pertenecen a la receta de {$marca->name}.",
                'fuera_de_receta' => $fueraDeReceta->values(),
            ], 422);
        }

        // 6) Stock suficiente en la bodega
        $insuficientes = $lineas->filter(
            fn ($l, $id) => $l['quantity'] > $materiales[$id]['stock_disponible']
        )->map(fn ($l, $id) => [
            'supply_id'  => $id,
            'name'       => $materiales[$id]['name'],
            'solicitado' => $l['quantity'],
            'disponible' => $materiales[$id]['stock_disponible'],
        ])->values();

        if ($insuficientes->isNotEmpty()) {
            return response()->json([
                'message'       => 'Stock insuficiente en la bodega para uno o más insumos.',
                'insuficientes' => $insuficientes,
            ], 422);
        }

        // 7) Semana bananera = semana ISO de HOY (la pone el servidor, no la pantalla)
        //    Con cupo de Comercial, la semana es la del cupo (la del pedido para el que se compra).
        $hoy    = now();
        $semana = $cupoComercial ? (int) $cupoComercial->week_number : (int) $hoy->isoWeek();
        $anio   = $cupoComercial ? (int) $cupoComercial->year : (int) $hoy->isoWeekYear();

        $movement = DB::transaction(function () use ($validated, $user, $marca, $cajas, $materiales, $lineas, $hoy, $semana, $anio, $warehouseId, $terceroId, $cupoComercial) {

            // 28/10/2026: bloqueo de la bodega + revalidación del stock DENTRO de la transacción.
            // Evita que dos despachos simultáneos saquen el mismo material (stock negativo).
            InventoryMovement::bloquearBodega($warehouseId);
            foreach ($lineas as $supplyId => $l) {
                $disponible = InventoryMovement::stockDisponible($warehouseId, (int) $supplyId);
                if ($l['quantity'] > $disponible) {
                    throw new HttpResponseException(response()->json([
                        'message'       => 'Stock insuficiente: otro despacho acaba de usar este material. Vuelve a calcular.',
                        'insuficientes' => [[
                            'supply_id'  => (int) $supplyId,
                            'name'       => $materiales[$supplyId]['name'],
                            'solicitado' => $l['quantity'],
                            'disponible' => $disponible,
                        ]],
                    ], 422));
                }
            }

            if ($cupoComercial) {
                // 7a) Cupo de Comercial: solo cambia de estado; sus cajas y precios no se tocan
                $cupo = $cupoComercial;
                $cupo->update(['status' => ProducerQuota::DESPACHADO]);
            } else {
                // 7a) Cupo MANUAL (productor sin cupo de Comercial): uno por productor+semana
                //     (UNIQUE). Si ya existía, se reutiliza.
                $cupo = ProducerQuota::firstOrNew([
                    'third_party_id' => $terceroId,
                    'week_number'    => $semana,
                    'year'           => $anio,
                ]);

                if (!$cupo->exists) {
                    $cupo->created_by = $user?->id; // quien registró el cupo por primera vez
                    $cupo->total_cupo = $cajas;      // valor inicial; se recalcula en 7c
                }

                $cupo->status = ProducerQuota::DESPACHADO;
                $cupo->save();

                // 7b) SOLO la línea manual de esta marca (customer_id NULL): se crea o se actualiza.
                //     Las otras marcas y las líneas de Comercial NO se tocan.
                $cupo->lines()->updateOrCreate(
                    ['variety' => $marca->code, 'customer_id' => null],
                    ['quantity_cajas' => $cajas]
                );

                // 7c) Total del cupo = suma de todas sus marcas
                $cupo->update(['total_cupo' => $cupo->lines()->sum('quantity_cajas')]);
            }

            // 7d) El EGRESO: esto es lo que descuenta el inventario
            $movement = InventoryMovement::create([
                'warehouse_id'         => $warehouseId,
                'movement_reason_id'   => self::MOTIVO_ENTREGA_PRODUCTOR,
                'third_party_id'       => $terceroId,
                'type'                 => 'EGRESO',
                'date'                 => $hoy->toDateString(),
                'week'                 => $semana,
                'year'                 => $anio,
                'vapor'                => $validated['vapor'] ?? null,
                'delivery_note'        => $validated['delivery_note'] ?? null,
                'producer_quota_id'    => $cupo->id,
                'brand_id'             => $marca->id, // marca del retiro (requiere $fillable)
                'received_by_name'     => mb_strtoupper($validated['received_by_name']),
                'received_by_document' => $validated['received_by_document'],
                'created_by_user_id'   => $user?->id,
            ]);

            // 7e) Líneas: RECOMENDADO (fórmula) y A RETIRAR (real), con costo CONGELADO
            //     al momento del retiro (es lo que se descuenta en la liquidación).
            foreach ($lineas as $supplyId => $linea) {
                $costo = $materiales[$supplyId]['unit_cost'];

                $movement->lines()->create([
                    'supply_id'            => $supplyId,
                    'recommended_quantity' => $materiales[$supplyId]['valor_recomendado'], // sugerido
                    'quantity'             => $linea['quantity'],                          // real
                    'unit_cost'            => $costo,
                    'discount'             => 0,
                    'total'                => $linea['quantity'] * $costo,
                ]);
            }

            return $movement;
        });

        return response()->json(
            $movement->load(['warehouse', 'thirdParty', 'reason', 'createdBy', 'producerQuota.lines', 'lines.supply']),
            201
        );
    }

    /**
     * GET /material-dispatch — HISTORIAL de despachos (quién, a quién, cuándo, cuánto $)
     * Filtros opcionales: warehouse_id, third_party_id, week, year
     */
    public function index(Request $request)
    {
        $query = InventoryMovement::with(['warehouse', 'thirdParty', 'createdBy', 'producerQuota'])
            // Suma del valor de las líneas = lo que se le descontará al productor
            ->withSum('lines as valor_total', 'total')
            ->where('type', 'EGRESO')
            ->whereNotNull('producer_quota_id') // solo despachos por cupo, no entregas manuales
            ->where('status', true);

        $user = $request->user();

        // BODEGUERO solo ve el historial de su bodega
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = Warehouse::where('responsible_user_id', $user->id)->first();
            $query->where('warehouse_id', $bodega->id ?? 0);
        } elseif ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        foreach (['third_party_id', 'week', 'year'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->$filtro);
            }
        }

        return response()->json($query->orderByDesc('date')->orderByDesc('id')->get());
    }

    /**
     * GET /material-dispatch/{id} — detalle completo (base para la guía de remisión)
     */
    public function show(Request $request, $id)
    {
        $movement = InventoryMovement::with(['warehouse', 'thirdParty', 'reason', 'createdBy', 'producerQuota.lines', 'lines.supply'])
            ->whereNotNull('producer_quota_id')
            ->findOrFail($id);

        // 28/10/2026: un BODEGUERO solo ve despachos de su bodega (igual que en index)
        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = Warehouse::where('responsible_user_id', $user->id)->first();
            if (!$bodega || $movement->warehouse_id !== $bodega->id) {
                return response()->json(['message' => 'No tienes permiso para ver este despacho.'], 403);
            }
        }

        return response()->json($movement);
    }

    /**
     * PATCH /material-dispatch/{id}/deactivate — ANULA un despacho mal hecho.
     * Nunca DELETE: status = false hace que stockDisponible() lo ignore,
     * así el material "regresa" solo a la bodega.
     */
    public function deactivate(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, self::ROLES_ANULAN)) {
            return response()->json([
                'message' => 'No tienes permiso para anular despachos.',
            ], 403);
        }

        $movement = InventoryMovement::whereNotNull('producer_quota_id')->findOrFail($id);
        $cupo     = $movement->producerQuota;

        if ($cupo && $cupo->status === ProducerQuota::LIQUIDADO) {
            return response()->json([
                'message' => 'No se puede anular: el cupo ya fue liquidado.',
            ], 422);
        }

        DB::transaction(function () use ($movement, $cupo) {
            $movement->update(['status' => false]);

            // Si era el único despacho activo del cupo, el cupo vuelve a ASIGNADO
            if ($cupo && !$cupo->dispatches()->where('status', true)->exists()) {
                $cupo->update(['status' => ProducerQuota::ASIGNADO]);
            }
        });

        return response()->json(['message' => 'Despacho anulado. El material regresó al stock de la bodega.']);
    }

    /* =================================================================
     |  LÓGICA COMPARTIDA (calculate y store usan exactamente lo mismo)
     * ================================================================= */

    /**
     * Devuelve la marca ACTIVA por su código (id, code, name).
     * Lanza 422 automático (ValidationException) si no existe o está inactiva.
     */
    private function marcaActiva(string $codigo): object
    {
        $marca = DB::table('brands')
            ->where('code', mb_strtoupper(trim($codigo)))
            ->where('status', true)
            ->first(['id', 'code', 'name']);

        if (!$marca) {
            throw ValidationException::withMessages([
                'brand_code' => 'Marca no válida o inactiva.',
            ]);
        }

        return $marca;
    }

    /**
     * Fase 3 — Cupo de Comercial elegido en pantalla: debe ser de ESTE productor, no estar
     * liquidado y tener cajas asignadas de ESA marca. Si no cumple, 422.
     */
    private function cupoComercialValido(int $cupoId, int $terceroId, string $marcaCodigo, int $warehouseId): ProducerQuota
    {
        $cupo = ProducerQuota::find($cupoId);

        $error = match (true) {
            !$cupo || (int) $cupo->third_party_id !== $terceroId => 'El cupo elegido no pertenece a este productor.',
            $cupo->status === ProducerQuota::LIQUIDADO           => 'El cupo elegido ya fue liquidado. No se puede despachar más material.',
            $this->cajasAsignadas($cupo, $marcaCodigo) <= 0      => 'Comercial no asignó cajas de esta marca en el cupo elegido.',
            // 29/10/2026: Comercial indica en qué bodega retira cada compra
            $this->cajasAsignadas($cupo, $marcaCodigo, $warehouseId) <= 0 => 'Este cupo se retira en otra bodega: '
                . $this->bodegasDeRetiro($cupo, $marcaCodigo) . '.',
            default                                              => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['producer_quota_id' => $error]);
        }

        return $cupo;
    }

    // Cajas que Comercial asignó en el cupo para una marca (puede haber una línea por cliente).
    // Con $warehouseId: solo las compras que retiran en esa bodega (sin bodega = cualquiera).
    private function cajasAsignadas(ProducerQuota $cupo, string $marcaCodigo, ?int $warehouseId = null): int
    {
        return (int) $cupo->lines()->where('variety', $marcaCodigo)->whereNotNull('customer_id')
            ->when($warehouseId, fn ($q) => $q->where(fn ($w) => $w->where('pickup_warehouse_id', $warehouseId)->orWhereNull('pickup_warehouse_id')))
            ->sum('quantity_cajas');
    }

    // Nombres de las bodegas donde se retira esa marca del cupo (para el mensaje de error)
    private function bodegasDeRetiro(ProducerQuota $cupo, string $marcaCodigo): string
    {
        return Warehouse::whereIn('id', $cupo->lines()->where('variety', $marcaCodigo)->whereNotNull('pickup_warehouse_id')->pluck('pickup_warehouse_id'))
            ->pluck('name')->implode(', ');
    }

    // Lo ya retirado se cuenta por bodega cuando todas las compras de esa marca tienen bodega
    // de retiro (si alguna no tiene —compras anteriores al 29/10/2026—, se cuenta en todas).
    private function bodegaParaDescontar(?ProducerQuota $cupoComercial, string $marcaCodigo, int $warehouseId): ?int
    {
        if (!$cupoComercial) {
            return null;
        }
        $sinBodega = $cupoComercial->lines()->where('variety', $marcaCodigo)->whereNotNull('customer_id')
            ->whereNull('pickup_warehouse_id')->exists();

        return $sinBodega ? null : $warehouseId;
    }

    // ¿El productor tiene algún cupo de Comercial sin liquidar? Entonces debe despachar contra él.
    private function tieneCupoComercialAbierto(int $terceroId): bool
    {
        return ProducerQuota::where('third_party_id', $terceroId)
            ->whereNotNull('customer_order_id')
            ->where('status', '!=', ProducerQuota::LIQUIDADO)
            ->whereHas('lines', fn ($q) => $q->whereNotNull('customer_id'))
            ->exists();
    }

    /**
     * Cupo de ESTE productor en la semana ISO actual (null si aún no retira nada).
     */
    private function cupoDeLaSemana(int $terceroId): ?ProducerQuota
    {
        return ProducerQuota::where('third_party_id', $terceroId)
            ->where('week_number', (int) now()->isoWeek())
            ->where('year', (int) now()->isoWeekYear())
            ->first();
    }

    /**
     * Cantidad ya retirada por insumo contra un cupo, SOLO de esta marca:
     * [supply_id => cantidad]. Filtrar por marca evita que los químicos
     * retirados con otra marca se descuenten aquí. Los anulados no cuentan.
     */
    private function yaDespachado(?int $cupoId, int $brandId, ?int $warehouseId = null): array
    {
        if (!$cupoId) {
            return [];
        }

        return DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->where('m.producer_quota_id', $cupoId)
            ->where('m.brand_id', $brandId)
            ->when($warehouseId, fn ($q) => $q->where('m.warehouse_id', $warehouseId))
            ->where('m.type', 'EGRESO')
            ->where('m.status', true)
            ->groupBy('l.supply_id')
            ->selectRaw('l.supply_id, SUM(l.quantity) as cantidad')
            ->pluck('cantidad', 'l.supply_id')
            ->map(fn ($c) => (float) $c)
            ->all();
    }

    /**
     * Núcleo del cálculo para UNA marca. Colección con llave = supply_id:
     *   valor_formula     = cajas × ratio_per_box (redondeado según unidad)
     *   ya_despachado     = lo retirado antes esta semana de ESTA marca
     *   valor_recomendado = fórmula − ya despachado (nunca negativo) → el SUGERIDO
     *   valor_a_retirar   = arranca igual al recomendado → el REAL, editable en pantalla
     */
    private function calcularMateriales(object $marca, int $cajas, int $warehouseId, array $yaDespachado = []): Collection
    {
        return MaterialRecipe::with('supply')
            ->where('status', true)
            ->where('brand', $marca->code)
            ->get()
            // Un insumo desactivado no se despacha aunque su receta siga activa
            ->filter(fn ($r) => $r->supply && $r->supply->status)
            // Un insumo aparece una sola vez por receta de marca
            ->keyBy('supply_id')
            ->map(function ($receta) use ($cajas, $warehouseId, $yaDespachado) {
                $supply = $receta->supply;

                $formula     = $this->redondear($cajas * (float) $receta->ratio_per_box, $supply->unit);
                $yaRetirado  = $yaDespachado[$supply->id] ?? 0;
                $recomendado = max(0, $this->redondear($formula - $yaRetirado, $supply->unit));

                return [
                    'supply_id'         => $supply->id,
                    'code'              => $supply->code,
                    'name'              => $supply->name,
                    'unit'              => $supply->unit,
                    'valor_formula'     => $formula,
                    'ya_despachado'     => $yaRetirado,
                    'valor_recomendado' => $recomendado,
                    'valor_a_retirar'   => $recomendado,
                    'stock_disponible'  => InventoryMovement::stockDisponible($warehouseId, $supply->id),
                    'unit_cost'         => (float) $supply->cost,
                ];
            })
            ->filter(fn ($m) => $m['valor_formula'] > 0);
    }

    /**
     * LIBRAS se pesa → 2 decimales. Todo lo demás se entrega entero → hacia arriba.
     * round(…, 6) antes de ceil evita que 5.0000000001 (coma flotante) suba a 6.
     */
    private function redondear(float $valor, ?string $unidad): float
    {
        if ($unidad === 'LIBRAS') {
            return round($valor, 2);
        }

        return (float) ceil(round($valor, 6));
    }
}


