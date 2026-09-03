<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    /**
     * Busca la bodega donde el usuario autenticado es responsable.
     * Mismo helper que en InventoryMovementController, usado para que un
     * BODEGUERO nunca vea ni cree OC de una bodega que no es la suya.
     */
    private function bodegaAsignada($user): ?Warehouse
    {
        return Warehouse::where('responsible_user_id', $user->id)->first();
    }

    /**
     * Lista las órdenes de compra.
     * Filtros opcionales:
     * - ?status=PENDIENTE  (un solo estado)
     * - ?status=PENDIENTE,PARCIAL  (varios, separados por coma — así el
     *   selector de "Nuevo Movimiento" puede ofrecer OCs que todavía
     *   tienen algo pendiente de recibir, sea que no haya llegado nada
     *   o que haya llegado solo una parte)
     * - ?third_party_id=5  (solo las OCs de ese proveedor — se usa junto
     *   con el proveedor ya elegido en el formulario de Ingreso)
     * - ?warehouse_id=3  (solo las OCs de esa bodega — usado por el
     *   selector de OC en InventoryMovementFormPage, filtrado a la bodega
     *   ya elegida en la cabecera del movimiento)
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['thirdParty', 'warehouse', 'lines']);

        $user = $request->user();

        // Un BODEGUERO solo puede ver OC de SU bodega asignada, sin importar
        // qué warehouse_id mande el frontend (mismo criterio que en
        // InventoryMovementController).
        if ($user && $user->role === 'BODEGUERO') {
            $bodega = $this->bodegaAsignada($user);
            $query->where('warehouse_id', $bodega->id ?? 0);
        } elseif ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('status')) {
            $estados = explode(',', $request->query('status'));
            $query->whereIn('status', $estados);
        }

        if ($request->filled('third_party_id')) {
            $query->where('third_party_id', $request->query('third_party_id'));
        }

        return response()->json($query->orderBy('id', 'desc')->get());
    }

    /**
     * Vista previa del próximo código, calculado según el año de la fecha recibida
     * (no el año de hoy) — así una OC fechada en enero del año siguiente ya arranca en su propio correlativo.
     * Es solo una vista previa: el código real se asigna en store(), con lock, para evitar duplicados
     * si dos personas guardan una OC al mismo tiempo.
     */
    public function nextCode(Request $request)
    {
        $fecha = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::now();
        $codigo = $this->calcularSiguienteCodigo($fecha->year);

        return response()->json(['code' => $codigo]);
    }

    /**
     * Muestra una orden de compra específica con sus líneas.
     */
    public function show($id)
    {
        return PurchaseOrder::with(['thirdParty', 'warehouse', 'lines.supply', 'creator'])->findOrFail($id);
    }

    /**
     * Crea una nueva orden de compra con sus líneas.
     * El código (OC-2026-0001, etc.) se genera automáticamente, correlativo por año.
     */
    public function store(Request $request)
    {
        // Crear OC no está dentro del alcance de un BODEGUERO (solo ve
        // Stock General y Nuevo Movimiento) — se bloquea también en el
        // backend por si alguien le pega directo a la API.
        $user = $request->user();
        if ($user && $user->role === 'BODEGUERO') {
            return response()->json([
                'message' => 'No tienes permiso para crear órdenes de compra.',
            ], 403);
        }

        $validado = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'reference' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.supply_id' => 'required|exists:supplies,id',
            'lines.*.quantity_ordered' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'required|numeric|in:15,5,0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.retention_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $orden = DB::transaction(function () use ($validado, $request) {
            $anio = Carbon::parse($validado['date'])->year;
            $codigo = $this->calcularSiguienteCodigo($anio);
            $semana = Carbon::parse($validado['date'])->isoWeek();

            $orden = PurchaseOrder::create([
                'code' => $codigo,
                'third_party_id' => $validado['third_party_id'],
                'warehouse_id' => $validado['warehouse_id'],
                'date' => $validado['date'],
                'week' => $semana,
                'status' => 'PENDIENTE',
                'reference' => $validado['reference'] ?? null,
                // Igual que en InventoryMovementController: no editable por el usuario
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validado['lines'] as $linea) {
                PurchaseOrderLine::create([
                    'purchase_order_id' => $orden->id,
                    'supply_id' => $linea['supply_id'],
                    'quantity_ordered' => $linea['quantity_ordered'],
                    'unit_price' => $linea['unit_price'],
                    'tax_rate' => $linea['tax_rate'],
                    'discount_percent' => $linea['discount_percent'] ?? 0,
                    'retention_rate' => $linea['retention_rate'] ?? 0,
                ]);
            }

            return $orden;
        });

        return response()->json(
            $orden->load(['thirdParty', 'warehouse', 'lines.supply']),
            201
        );
    }

    /**
     * Genera el siguiente código de forma segura: bloquea (lockForUpdate) el último código del año
     * en vez de contar filas, para que dos guardados simultáneos nunca generen el mismo número.
     */
    private function calcularSiguienteCodigo($anio)
    {
        $ultima = PurchaseOrder::where('code', 'like', "OC-{$anio}-%")
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        if (!$ultima) {
            $siguiente = 1;
        } else {
            $partes = explode('-', $ultima->code);
            $siguiente = ((int) end($partes)) + 1;
        }

        return "OC-{$anio}-" . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }
}