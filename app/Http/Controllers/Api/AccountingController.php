<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\BankPaymentBatch;
use App\Models\ProducerAdvance;
use App\Models\PurchaseOrder;
use App\Models\ThirdParty;
use App\Models\ThirdPartyBankAccount;
use App\Services\PichinchaPagos;
use App\Support\CuentaBancaria;
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
        // 19/11/2026: los lotes de anticipos de productores se ven en Cuentas por pagar, no aquí
        return response()->json(BankPaymentBatch::with(['creator:id,name', 'purchaseOrders:id,code,bank_payment_batch_id,third_party_id,department,reference', 'purchaseOrders.thirdParty:id,name'])
            ->whereDoesntHave('items', fn ($q) => $q->where('source_type', 'ANTICIPO'))
            ->orderByDesc('id')->get());
    }

    // El TXT tal como se generó (se guarda: bajarlo otra vez da exactamente el mismo archivo)
    public function batchFile($id)
    {
        $lote = BankPaymentBatch::findOrFail($id);
        $this->soloLoteOC($lote);

        return $this->archivoLote($lote);
    }

    private function archivoLote(BankPaymentBatch $lote)
    {

        return response($lote->content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $lote->file_name . '"',
        ]);
    }

    public function batchPaid(Request $request, $id)
    {
        $lote = BankPaymentBatch::findOrFail($id);
        $this->soloLoteOC($lote);
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
        $this->soloLoteOC($lote);
        if ($lote->status !== 'GENERADO') {
            return response()->json(['message' => "El lote está {$lote->status}: no se puede anular."], 422);
        }
        DB::transaction(function () use ($lote, $validado) {
            $lote->update(['status' => 'ANULADO', 'cancellation_reason' => mb_strtoupper(trim($validado['cancellation_reason']))]);
            $lote->purchaseOrders()->get()->each->update(['payment_status' => null, 'bank_payment_batch_id' => null]);
        });

        return response()->json($lote->fresh());
    }

    // ---------------------------------------------------------------- 19/11/2026 (fase 1b-1): cuentas por pagar — anticipos de productores
    // Los ANTICIPOS 80 % aprobados por el jefe de Liquidaciones se pagan por el TXT del Pichincha: una línea por orden
    // (P20), con la cuenta del vendedor congelada y cifrada en bank_payment_batch_items. Lote solo de anticipos
    // (las OC siguen en su pantalla hasta la 1b-2). Preparar = pagos_banco; confirmar pagado o fallido = confirmar_pagos.

    public function payables(Request $request)
    {
        $f = $request->validate(['anio' => 'nullable|integer', 'semana' => 'nullable|integer|min:1|max:53']);
        $anticipos = $this->anticiposPorPagar()
            ->when(isset($f['anio']), fn ($q) => $q->where('scheduled_year', $f['anio']))
            ->when(isset($f['semana']), fn ($q) => $q->where('scheduled_week', $f['semana']))
            ->get();

        return response()->json($this->filasAnticipo($anticipos));
    }

    public function payablesPreview(Request $request)
    {
        $filas = $this->filasAnticipo($this->anticiposElegidos($request));

        return response()->json(['anticipos' => $filas, 'total' => round($filas->sum('monto'), 2)]);
    }

    public function payablesGenerate(Request $request)
    {
        $elegidos = $this->anticiposElegidos($request);

        $lote = DB::transaction(function () use ($elegidos, $request) {
            // vuelve a leer con bloqueo: dos personas generando a la vez no meten el mismo anticipo en dos lotes
            $anticipos = ProducerAdvance::with('quota:id,week_number')->whereIn('id', $elegidos->pluck('id'))->lockForUpdate()->orderBy('id')->get();
            $cuentas = ThirdPartyBankAccount::with('bank:id,name,code')->whereIn('third_party_id', $anticipos->pluck('third_party_id'))->get()->keyBy('third_party_id');
            $errores = $anticipos->map(function ($a) use ($cuentas) {
                $p = PichinchaPagos::problemasAnticipo($a, $cuentas->get($a->third_party_id));
                return $p ? "{$a->payment_order_code} (" . implode(' ', $p) . ')' : null;
            })->filter();
            abort_if($errores->isNotEmpty(), response()->json(['message' => 'HAY ANTICIPOS QUE NO SE PUEDEN PAGAR: ' . $errores->implode('; ')], 422));

            $hoy = now(config('app.zona_operacion'));
            $codigo = $this->siguienteLote($hoy->year);
            $lote = BankPaymentBatch::create([
                'code' => $codigo, 'date' => $hoy->toDateString(), 'payments_count' => $anticipos->count(),
                'total' => round($anticipos->sum(fn ($a) => (float) $a->amount), 2),
                'file_name' => 'PAGOS_PICHINCHA_' . $codigo . '.txt', 'content' => '', 'status' => 'GENERADO',
                'created_by' => $request->user()->id,
            ]);
            $pagables = [];
            foreach ($anticipos as $a) {
                $c = $cuentas->get($a->third_party_id);
                $referencia = mb_substr("ANT80 {$a->payment_order_code} WK{$a->scheduled_week}", 0, 40);
                $lote->items()->create([
                    'source_type' => 'ANTICIPO', 'producer_advance_id' => $a->id, 'payee_third_party_id' => $a->third_party_id,
                    'amount' => $a->amount, 'bank_id' => $c->bank_id, 'account_type' => $c->account_type,
                    'account_number' => $c->account_number, 'holder_id_type' => $c->holder_id_type, 'holder_id' => $c->holder_id,
                    'holder_name' => mb_substr((string) $c->holder_name, 0, 120), 'reference' => $referencia, 'status' => 'EN_BANCO',
                ]);
                $pagables[] = [
                    'valor' => (float) $a->amount, 'referencia' => $referencia, 'account_type' => $c->account_type,
                    'account_number' => $c->account_number, 'holder_id_type' => $c->holder_id_type, 'holder_id' => $c->holder_id,
                    'holder_name' => $c->holder_name, 'bank_code' => $c->bank->code,
                ];
                $a->update(['payment_status' => 'EN_BANCO', 'payment_method' => 'LOTE_BANCO']);
            }
            $lote->update(['content' => PichinchaPagos::archivo($pagables)]);

            return $lote;
        });

        return response()->json($lote->load('creator:id,name'), 201);
    }

    public function payableBatches()
    {
        return response()->json(BankPaymentBatch::with(['creator:id,name', 'items.producerAdvance:id,payment_order_code,scheduled_week,scheduled_year',
            'items.payee:id,name', 'items.bank:id,name'])
            ->whereHas('items', fn ($q) => $q->where('source_type', 'ANTICIPO'))
            ->orderByDesc('id')->get()
            ->map(fn ($l) => collect($l->toArray())->except('items')->all() + [
                // la cuenta va enmascarada: el número completo solo se usa para el TXT
                'items' => $l->items->map(fn ($i) => [
                    'id' => $i->id, 'code' => $i->producerAdvance?->payment_order_code, 'vendedor' => $i->payee?->name,
                    'banco' => $i->bank?->name, 'cuenta' => '****' . substr((string) $i->account_number, -4),
                    'amount' => $i->amount, 'reference' => $i->reference, 'status' => $i->status, 'failure_reason' => $i->failure_reason,
                ]),
            ]));
    }

    public function payableBatchFile($id)
    {
        $lote = BankPaymentBatch::findOrFail($id);
        abort_unless($this->esLoteAnticipos($lote), 404);

        return $this->archivoLote($lote);
    }

    // Pagado (todo) o pagado en parte: las líneas que el banco rechazó quedan FALLIDAS y su anticipo vuelve a "por pagar"
    public function payableBatchPaid(Request $request, $id)
    {
        $data = $request->validate([
            'paid_at'            => 'required|date',
            'bank_reference'     => 'nullable|string|max:60',
            'fallidas'           => 'present|array',
            'fallidas.*.item_id' => 'required|integer|distinct',
            'fallidas.*.motivo'  => 'required|string|max:255',
        ], ['paid_at.required' => 'ESCRIBE LA FECHA DEL PAGO.', 'fallidas.*.motivo.required' => 'ESCRIBE POR QUÉ EL BANCO RECHAZÓ LA LÍNEA.']);

        $lote = DB::transaction(function () use ($id, $data, $request) {
            $lote = BankPaymentBatch::lockForUpdate()->findOrFail($id);
            abort_unless($this->esLoteAnticipos($lote), 404);
            abort_if($lote->status !== 'GENERADO', response()->json(['message' => "EL LOTE ESTÁ {$lote->status}."], 422));
            $items = $lote->items()->lockForUpdate()->get()->keyBy('id');
            $fallidas = collect($data['fallidas'])->keyBy('item_id');
            abort_if($fallidas->keys()->diff($items->keys())->isNotEmpty(), response()->json(['message' => 'UNA DE LAS LÍNEAS FALLIDAS NO ES DE ESTE LOTE.'], 422));
            abort_if($fallidas->count() === $items->count(), response()->json(['message' => 'SI EL BANCO RECHAZÓ TODO, ANULA EL LOTE.'], 422));
            $referencia = isset($data['bank_reference']) ? mb_strtoupper(trim($data['bank_reference'])) : null;

            foreach ($items as $i) { // uno por uno: bitácora
                $falla = $fallidas->get($i->id);
                $i->update($falla
                    ? ['status' => 'FALLIDO', 'failure_reason' => mb_strtoupper(trim($falla['motivo']))]
                    : ['status' => 'PAGADO', 'paid_at' => $data['paid_at'], 'bank_reference' => $referencia]);
                $i->producerAdvance?->update($falla
                    ? ['payment_status' => 'FALLIDA']
                    : ['payment_status' => 'PAGADA', 'paid_at' => $data['paid_at'], 'payment_reference' => $referencia, 'paid_by' => $request->user()->id]);
            }
            $lote->update(['status' => $fallidas->isEmpty() ? 'PAGADO' : 'PAGADO_PARCIAL', 'paid_at' => now(), 'paid_by' => $request->user()->id]);

            return $lote;
        });

        return response()->json($lote->fresh());
    }

    // Anular: el banco lo rechazó todo o se generó por error. Los anticipos vuelven a "por pagar".
    public function payableBatchCancel(Request $request, $id)
    {
        $data = $request->validate(['cancellation_reason' => 'required|string|min:3|max:500'], [
            'cancellation_reason.required' => 'ESCRIBE EL MOTIVO.',
        ]);
        $lote = DB::transaction(function () use ($id, $data) {
            $lote = BankPaymentBatch::lockForUpdate()->findOrFail($id);
            abort_unless($this->esLoteAnticipos($lote), 404);
            abort_if($lote->status !== 'GENERADO', response()->json(['message' => "EL LOTE ESTÁ {$lote->status}: NO SE PUEDE ANULAR."], 422));
            $lote->update(['status' => 'ANULADO', 'cancellation_reason' => mb_strtoupper(trim($data['cancellation_reason']))]);
            foreach ($lote->items()->get() as $i) {
                $i->update(['status' => 'ANULADO']);
                $i->producerAdvance?->update(['payment_status' => null, 'payment_method' => null]);
            }

            return $lote;
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
        // 18/11/2026: + quién registró y quién verificó la cuenta (estado PENDIENTE / VERIFICADA)
        $terceros = ThirdParty::with(['bankAccount.bank:id,name,code', 'bankAccount.updater:id,name', 'bankAccount.verifier:id,name'])
            // 18/11/2026 (fase 2b): también productores y comercializadoras, cuyas cuentas ingresa Liquidaciones
            ->where(fn ($q) => $q->whereIn('type', ['PROVEEDOR', 'PRODUCTOR', 'COMERCIALIZADORA'])->orWhereIn('id', $conOC))
            ->where('status', true)->orderBy('name')
            ->get(['id', 'name', 'identification', 'type']);

        return response()->json($terceros);
    }

    public function saveBankAccount(Request $request, $thirdPartyId)
    {
        $tercero = ThirdParty::findOrFail($thirdPartyId);
        // 18/11/2026: las reglas viven en App\Support\CuentaBancaria (las comparte con Liquidaciones)
        $data = CuentaBancaria::validar($request);

        $cuenta = ThirdPartyBankAccount::updateOrCreate(['third_party_id' => $tercero->id], $data + ['updated_by' => $request->user()->id]);

        return response()->json($cuenta->load(['bank:id,name,code', 'updater:id,name', 'verifier:id,name']));
    }

    // 18/11/2026 (Peter): Contabilidad revisa la cuenta completa y la marca VERIFICADA (queda quién y cuándo).
    // Si después cambian el banco, el número o el titular, el modelo la devuelve a PENDIENTE.
    public function verifyBankAccount(Request $request, $thirdPartyId)
    {
        $cuenta = ThirdPartyBankAccount::where('third_party_id', $thirdPartyId)->firstOrFail();
        if ($cuenta->verificada()) {
            return response()->json(['message' => 'ESTA CUENTA YA ESTÁ VERIFICADA.'], 422);
        }
        $cuenta->update(['verified_at' => now(), 'verified_by' => $request->user()->id]);

        return response()->json($cuenta->fresh()->load(['bank:id,name,code', 'updater:id,name', 'verifier:id,name']));
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

    // 19/11/2026: anticipos aprobados que todavía no salen al banco (FALLIDA vuelve a por pagar)
    private function anticiposPorPagar()
    {
        return ProducerAdvance::with(['thirdParty:id,name', 'quota:id,week_number,year'])
            ->where('status', true)->where('approval_status', 'APROBADA')
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', 'FALLIDA'))
            ->orderBy('scheduled_year')->orderBy('scheduled_week')->orderBy('id');
    }

    private function anticiposElegidos(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|distinct|exists:producer_advances,id'], [
            'ids.required' => 'ELIGE AL MENOS UN ANTICIPO.',
        ])['ids'];

        return ProducerAdvance::with(['thirdParty:id,name', 'quota:id,week_number,year'])->whereIn('id', $ids)->orderBy('id')->get();
    }

    // Filas de la bandeja: cuenta SIEMPRE enmascarada (****1234) y estado PENDIENTE / VERIFICADA
    private function filasAnticipo($anticipos)
    {
        $cuentas = ThirdPartyBankAccount::with('bank:id,name,code')->whereIn('third_party_id', $anticipos->pluck('third_party_id')->unique())
            ->get()->keyBy('third_party_id');

        return $anticipos->map(function (ProducerAdvance $a) use ($cuentas) {
            $c = $cuentas->get($a->third_party_id);

            return [
                'id'           => $a->id,
                'code'         => $a->payment_order_code,
                'origin'       => $a->origin,
                'orden'        => $a->origin === 'SPOT_80' ? 'ANTICIPO 80 %' : $a->type,
                'semana_pago'  => $a->scheduled_week,
                'anio_pago'    => $a->scheduled_year,
                'semana_compra' => $a->quota?->week_number,
                'vendedor'     => $a->thirdParty,
                'banco'        => $c?->bank?->name,
                'tipo_cuenta'  => $c?->account_type,
                'cuenta'       => $c ? '****' . substr((string) $c->account_number, -4) : null,
                'estado_cuenta' => !$c ? 'SIN CUENTA' : ($c->verificada() ? 'VERIFICADA' : 'PENDIENTE'),
                'monto'        => (float) $a->amount,
                'fallida'      => $a->payment_status === 'FALLIDA',
                'problemas'    => PichinchaPagos::problemasAnticipo($a, $c),
            ];
        })->values();
    }

    private function esLoteAnticipos(BankPaymentBatch $lote): bool
    {
        return $lote->items()->where('source_type', 'ANTICIPO')->exists();
    }

    // 19/11/2026: la pantalla de OC no toca los lotes de anticipos (sus pagos los confirma confirmar_pagos)
    private function soloLoteOC(BankPaymentBatch $lote): void
    {
        abort_if($this->esLoteAnticipos($lote), response()->json(['message' => 'ESTE LOTE ES DE ANTICIPOS DE PRODUCTORES: SE MANEJA EN CUENTAS POR PAGAR.'], 422));
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
