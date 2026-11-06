<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\BankPaymentBatch;
use App\Models\PurchaseOrder;
use App\Models\ThirdParty;
use App\Models\ThirdPartyBankAccount;
use App\Services\PichinchaPagos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Contabilidad (05/11/2026): aprobación y pago de las OC de todos los departamentos.
//   - Bandeja: OC de departamento y de bodega. Solo Contabilidad aprueba o rechaza (con motivo).
//   - Carga masiva: con las OC aprobadas y sin pagar arma un lote = un TXT para Banco Pichincha
//     (PichinchaPagos). El lote queda GENERADO → PAGADO cuando el banco confirma, o ANULADO (las
//     OC vuelven a "por pagar"). Una OC en un lote no se puede volver a pagar ni cambiar.
//   - Cuentas bancarias de proveedores y códigos de banco (pantalla cuentas_bancarias).
class AccountingController extends Controller
{
    private const RELACIONES = [
        'thirdParty:id,name,identification', 'thirdParty.bankAccount.bank:id,name,code', 'warehouse:id,name',
        'lines.supply:id,name', 'creator:id,name', 'approver:id,name', 'bankPaymentBatch:id,code,status',
    ];

    // ---------------------------------------------------------------- bandeja de OC

    public function orders()
    {
        $ordenes = PurchaseOrder::with(self::RELACIONES)->orderByDesc('id')->get();

        return response()->json($ordenes->map(fn ($o) => $this->conPago($o)));
    }

    public function show($id)
    {
        return response()->json($this->conPago(PurchaseOrder::with(self::RELACIONES)->findOrFail($id)));
    }

    public function approve(Request $request, $id)
    {
        $orden = PurchaseOrder::findOrFail($id);
        if ($orden->status === 'CANCELADA') {
            return response()->json(['message' => 'La orden está cancelada.'], 422);
        }
        if ($orden->approval_status !== 'POR_APROBAR') {
            return response()->json(['message' => 'Solo se aprueba una orden que está POR APROBAR.'], 422);
        }
        $orden->update(['approval_status' => 'APROBADA', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejection_reason' => null]);

        return $this->show($id);
    }

    // Rechazar: vuelve al departamento con el motivo. También una aprobada que todavía no se mandó al banco.
    public function reject(Request $request, $id)
    {
        $validado = $request->validate(['rejection_reason' => 'required|string|min:3|max:500'], [
            'rejection_reason.required' => 'Escribe el motivo del rechazo.',
        ]);
        $orden = PurchaseOrder::findOrFail($id);
        if ($orden->status === 'CANCELADA' || $orden->payment_status || !in_array($orden->approval_status, ['POR_APROBAR', 'APROBADA'], true)) {
            return response()->json(['message' => 'Esta orden ya no se puede rechazar.'], 422);
        }
        $orden->update([
            'approval_status' => 'RECHAZADA', 'rejection_reason' => mb_strtoupper(trim($validado['rejection_reason'])),
            'approved_by' => $request->user()->id, 'approved_at' => now(),
        ]);

        return $this->show($id);
    }

    // ---------------------------------------------------------------- lotes al banco

    // Revisa las OC elegidas antes de generar: cuáles se pueden pagar y por qué no las otras.
    public function preview(Request $request)
    {
        $ordenes = $this->ordenesElegidas($request);

        return response()->json([
            'ordenes' => $ordenes->map(fn ($o) => ['id' => $o->id, 'code' => $o->code, 'proveedor' => $o->thirdParty?->name,
                'valor' => PichinchaPagos::valor($o), 'problemas' => PichinchaPagos::problemas($o)])->values(),
            'total' => round($ordenes->sum(fn ($o) => PichinchaPagos::valor($o)), 2),
        ]);
    }

    public function generate(Request $request)
    {
        $ordenes = $this->ordenesElegidas($request);
        $conProblemas = $ordenes->filter(fn ($o) => PichinchaPagos::problemas($o));
        if ($conProblemas->isNotEmpty()) {
            return response()->json(['message' => 'Hay órdenes que no se pueden pagar: ' . $conProblemas->map(
                fn ($o) => $o->code . ' (' . implode(' ', PichinchaPagos::problemas($o)) . ')')->implode('; ')], 422);
        }

        $lote = DB::transaction(function () use ($ordenes, $request) {
            // vuelve a leer con bloqueo: dos personas generando a la vez no meten la misma OC en dos lotes
            $libres = PurchaseOrder::whereIn('id', $ordenes->pluck('id'))->whereNull('payment_status')->lockForUpdate()->pluck('id')->count();
            abort_if($libres !== $ordenes->count(), 422, 'Alguna orden ya entró en otro lote. Actualiza la pantalla.');

            $hoy = now(config('app.zona_operacion'));
            $codigo = $this->siguienteLote($hoy->year);
            $lote = BankPaymentBatch::create([
                'code' => $codigo,
                'date' => $hoy->toDateString(),
                'payments_count' => $ordenes->count(),
                'total' => round($ordenes->sum(fn ($o) => PichinchaPagos::valor($o)), 2),
                'file_name' => 'PAGOS_PICHINCHA_' . $codigo . '.txt',
                'content' => PichinchaPagos::archivo($ordenes),
                'status' => 'GENERADO',
                'created_by' => $request->user()->id,
            ]);
            foreach ($ordenes as $o) {
                $o->update(['payment_status' => 'EN_BANCO', 'bank_payment_batch_id' => $lote->id]);
            }

            return $lote;
        });

        return response()->json($lote->load('creator:id,name'), 201);
    }

    public function batches()
    {
        return response()->json(BankPaymentBatch::with(['creator:id,name', 'purchaseOrders:id,code,bank_payment_batch_id,third_party_id,department,reference', 'purchaseOrders.thirdParty:id,name'])
            ->orderByDesc('id')->get());
    }

    // El TXT tal como se generó (se guarda: bajarlo otra vez da exactamente el mismo archivo)
    public function batchFile($id)
    {
        $lote = BankPaymentBatch::findOrFail($id);

        return response($lote->content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $lote->file_name . '"',
        ]);
    }

    public function batchPaid(Request $request, $id)
    {
        $lote = BankPaymentBatch::findOrFail($id);
        if ($lote->status !== 'GENERADO') {
            return response()->json(['message' => "El lote está {$lote->status}."], 422);
        }
        DB::transaction(function () use ($lote, $request) {
            $lote->update(['status' => 'PAGADO', 'paid_at' => now(), 'paid_by' => $request->user()->id]);
            $lote->purchaseOrders()->get()->each->update(['payment_status' => 'PAGADA']);
        });

        return response()->json($lote->fresh());
    }

    // Anular: el banco lo rechazó o se generó por error. Las OC vuelven a "por pagar".
    public function batchCancel(Request $request, $id)
    {
        $validado = $request->validate(['cancellation_reason' => 'required|string|min:3|max:500'], [
            'cancellation_reason.required' => 'Escribe el motivo.',
        ]);
        $lote = BankPaymentBatch::findOrFail($id);
        if ($lote->status !== 'GENERADO') {
            return response()->json(['message' => "El lote está {$lote->status}: no se puede anular."], 422);
        }
        DB::transaction(function () use ($lote, $validado) {
            $lote->update(['status' => 'ANULADO', 'cancellation_reason' => mb_strtoupper(trim($validado['cancellation_reason']))]);
            $lote->purchaseOrders()->get()->each->update(['payment_status' => null, 'bank_payment_batch_id' => null]);
        });

        return response()->json($lote->fresh());
    }

    // ---------------------------------------------------------------- bancos y cuentas

    public function banks()
    {
        return response()->json(Bank::orderBy('name')->get());
    }

    public function storeBank(Request $request)
    {
        $data = $this->validarBanco($request);

        return response()->json(Bank::create($data + ['status' => true]), 201);
    }

    public function updateBank(Request $request, $id)
    {
        $banco = Bank::findOrFail($id);
        $banco->update($this->validarBanco($request, $banco) + ['status' => $request->boolean('status', true)]);

        return response()->json($banco);
    }

    // Proveedores (y cualquier tercero que tenga una OC) con su cuenta bancaria
    public function bankAccounts()
    {
        $conOC = PurchaseOrder::distinct()->pluck('third_party_id');
        $terceros = ThirdParty::with('bankAccount.bank:id,name,code')
            ->where(fn ($q) => $q->where('type', 'PROVEEDOR')->orWhereIn('id', $conOC))
            ->where('status', true)->orderBy('name')
            ->get(['id', 'name', 'identification', 'type']);

        return response()->json($terceros);
    }

    public function saveBankAccount(Request $request, $thirdPartyId)
    {
        $tercero = ThirdParty::findOrFail($thirdPartyId);
        $data = $request->validate([
            'bank_id' => ['required', Rule::exists('banks', 'id')->where('status', true)],
            'account_type' => 'required|in:AHORROS,CORRIENTE',
            'account_number' => ['required', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'holder_id_type' => 'required|in:C,R,P',
            'holder_id' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/'],
            'holder_name' => 'required|string|max:120',
            'email' => 'nullable|email|max:120',
        ], [
            'account_number.regex' => 'El número de cuenta lleva solo dígitos.',
            'holder_id.regex' => 'La identificación lleva solo números (o letras si es pasaporte).',
        ]);
        if ($data['holder_id_type'] === 'C' && strlen($data['holder_id']) !== 10) {
            return response()->json(['message' => 'La cédula tiene 10 dígitos.'], 422);
        }
        if ($data['holder_id_type'] === 'R' && strlen($data['holder_id']) !== 13) {
            return response()->json(['message' => 'El RUC tiene 13 dígitos.'], 422);
        }
        $data['holder_name'] = mb_strtoupper(trim($data['holder_name']));

        $cuenta = ThirdPartyBankAccount::updateOrCreate(['third_party_id' => $tercero->id], $data + ['updated_by' => $request->user()->id]);

        return response()->json($cuenta->load('bank:id,name,code'));
    }

    // ---------------------------------------------------------------- ayudas

    // Valor a pagar y si se puede pagar ya por el banco
    private function conPago(PurchaseOrder $o): array
    {
        $cuenta = $o->thirdParty?->bankAccount;

        return collect($o->toArray())->except(['third_party'])->all() + [
            'third_party' => $o->thirdParty ? ['id' => $o->thirdParty->id, 'name' => $o->thirdParty->name, 'identification' => $o->thirdParty->identification] : null,
            'valor_pagar' => PichinchaPagos::valor($o),
            'tiene_cuenta' => (bool) $cuenta,
            'cuenta_resumen' => $cuenta ? trim("{$cuenta->bank?->name} " . ($cuenta->account_type === 'CORRIENTE' ? 'CTE' : 'AHO') . ' ****' . substr($cuenta->account_number, -4)) : null,
            'problemas_pago' => $o->approval_status === 'APROBADA' && !$o->payment_status && $o->status !== 'CANCELADA' ? PichinchaPagos::problemas($o) : [],
        ];
    }

    private function ordenesElegidas(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|distinct|exists:purchase_orders,id'], [
            'ids.required' => 'Elige al menos una orden.',
        ])['ids'];

        return PurchaseOrder::with(['thirdParty.bankAccount.bank', 'lines'])->whereIn('id', $ids)->orderBy('id')->get();
    }

    private function siguienteLote(int $anio): string
    {
        $ultimo = BankPaymentBatch::where('code', 'like', "PAG-{$anio}-%")->lockForUpdate()->orderByDesc('id')->first();
        $n = $ultimo ? ((int) substr($ultimo->code, -4)) + 1 : 1;

        return "PAG-{$anio}-" . str_pad($n, 4, '0', STR_PAD_LEFT);
    }

    private function validarBanco(Request $request, ?Bank $banco = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('banks', 'name')->ignore($banco?->id)],
            'code' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]+$/'],
        ], ['code.regex' => 'El código del banco lleva solo dígitos.', 'name.unique' => 'Ese banco ya existe.']);
        $data['name'] = mb_strtoupper(trim($data['name']));

        return $data;
    }
}
