<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Órdenes de compra de un departamento (05/11/2026) — pantalla oc_departamento.
// Cada departamento (Compras, Sistemas, Asistente de Gerencia, Logística...) llena sus OC con líneas de
// texto libre (sin insumo ni bodega: no entran a inventario). Le llegan a Contabilidad, que es la única
// que aprueba (AccountingController). Mismo correlativo OC-2026-0001 que las OC de bodega.
// El usuario ve las OC de SU departamento (users.department); sin departamento, solo las que él creó.
// Se edita mientras está POR APROBAR o RECHAZADA (al corregirla vuelve a POR APROBAR).
class DepartmentOrderController extends Controller
{
    private const RELACIONES = ['thirdParty:id,name,identification', 'lines', 'creator:id,name', 'approver:id,name', 'bankPaymentBatch:id,code,status'];

    private function visibles(Request $request)
    {
        $user = $request->user();
        $query = PurchaseOrder::whereNull('warehouse_id');
        if ($user->esAdmin()) {
            return $query;
        }

        return $user->department ? $query->where('department', $user->department) : $query->where('created_by', $user->id);
    }

    public function index(Request $request)
    {
        return response()->json($this->visibles($request)->with(self::RELACIONES)->orderByDesc('id')->get());
    }

    public function show(Request $request, $id)
    {
        return response()->json($this->visibles($request)->with(self::RELACIONES)->findOrFail($id));
    }

    public function store(Request $request)
    {
        $validado = $this->validar($request);
        $user = $request->user();

        $orden = DB::transaction(function () use ($validado, $user) {
            $fecha = Carbon::parse($validado['date']);
            $orden = PurchaseOrder::create([
                'code' => PurchaseOrderController::calcularSiguienteCodigo($fecha->year),
                'third_party_id' => $validado['third_party_id'],
                'warehouse_id' => null,
                'department' => $user->department ?: 'SIN DEPARTAMENTO',
                'date' => $validado['date'],
                'week' => $fecha->isoWeek(),
                'status' => 'PENDIENTE',
                'approval_status' => 'POR_APROBAR',
                'reference' => mb_strtoupper(trim($validado['reference'])),
                'created_by' => $user->id,
            ]);
            $this->guardarLineas($orden, $validado['lines']);

            return $orden;
        });

        return response()->json($orden->load(self::RELACIONES), 201);
    }

    public function update(Request $request, $id)
    {
        $orden = $this->visibles($request)->findOrFail($id);
        if ($error = $this->noEditable($orden)) {
            return response()->json(['message' => $error], 422);
        }
        $validado = $this->validar($request);

        DB::transaction(function () use ($orden, $validado) {
            $orden->update([
                'third_party_id' => $validado['third_party_id'],
                'date' => $validado['date'],
                'week' => Carbon::parse($validado['date'])->isoWeek(),
                'reference' => mb_strtoupper(trim($validado['reference'])),
                // corregida: vuelve a Contabilidad
                'approval_status' => 'POR_APROBAR',
                'rejection_reason' => null,
            ]);
            $orden->lines()->delete();
            $this->guardarLineas($orden, $validado['lines']);
        });

        return response()->json($orden->fresh()->load(self::RELACIONES));
    }

    // Cancelar: solo si todavía no se mandó al banco. No se borra (queda con su motivo).
    public function cancel(Request $request, $id)
    {
        $validado = $request->validate(['cancellation_reason' => 'required|string|min:3|max:500']);
        $orden = $this->visibles($request)->findOrFail($id);
        if ($orden->status === 'CANCELADA') {
            return response()->json(['message' => 'Esta orden ya está cancelada.'], 422);
        }
        if ($orden->payment_status) {
            return response()->json(['message' => 'Esta orden ya se envió al banco: no se puede cancelar.'], 422);
        }
        $orden->update(['status' => 'CANCELADA', 'cancellation_reason' => mb_strtoupper(trim($validado['cancellation_reason']))]);

        return response()->json($orden->fresh()->load(self::RELACIONES));
    }

    private function noEditable(PurchaseOrder $orden): ?string
    {
        return match (true) {
            $orden->status === 'CANCELADA' => 'Esta orden está cancelada.',
            (bool) $orden->payment_status => 'Esta orden ya se envió al banco.',
            $orden->approval_status === 'APROBADA' => 'Contabilidad ya aprobó esta orden: no se puede cambiar.',
            default => null,
        };
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'third_party_id' => ['required', Rule::exists('third_parties', 'id')->where('status', true)],
            'date' => 'required|date',
            'reference' => 'required|string|min:3|max:500', // concepto: para qué es la compra
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity_ordered' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'required|numeric|in:15,5,0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.retention_rate' => 'nullable|numeric|min:0|max:100',
        ], [
            'third_party_id.required' => 'Elige el proveedor.',
            'reference.required' => 'Escribe el concepto de la compra.',
            'lines.required' => 'Agrega al menos una línea.',
            'lines.*.description.required' => 'Cada línea necesita su descripción.',
        ]);
    }

    private function guardarLineas(PurchaseOrder $orden, array $lineas): void
    {
        foreach ($lineas as $linea) {
            PurchaseOrderLine::create([
                'purchase_order_id' => $orden->id,
                'supply_id' => null,
                'description' => mb_strtoupper(trim($linea['description'])),
                'quantity_ordered' => $linea['quantity_ordered'],
                'unit_price' => $linea['unit_price'],
                'tax_rate' => $linea['tax_rate'],
                'discount_percent' => $linea['discount_percent'] ?? 0,
                'retention_rate' => $linea['retention_rate'] ?? 0,
            ]);
        }
    }
}
