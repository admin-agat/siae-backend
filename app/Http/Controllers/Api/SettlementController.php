<?php

// LIQUIDACIÓN AL PRODUCTOR (29/10/2026)
// Ubicación: app/Http/Controllers/Api/SettlementController.php
//
// Se liquida un CUPO (productor + semana) con lo que dicen las TARJAS.
// 17/11/2026 (FASE 0, docs/modulos/pagos-liquidaciones.md §3.3): el cupo se liquida en VARIAS LQ, como el Excel:
//   una por cada CONTRATO y una de SPOT, creadas juntas (y anuladas juntas). Cada LQ lleva su retención IR.
//   RECIBIDAS = EMBARCADAS − CAÍDAS − RECHAZADAS − FALTANTES (regla de Peter con el personal de tarjas):
//   la fruta, la factura/NC, la retención, el transporte reconocido y el bono van sobre RECIBIDAS.
//   Retención IR banano (RetencionBanano): tramo según las recibidas de ESA LQ; base = recibidas × precio
//   liquidado (el mayor entre el real y el oficial); régimen del vendedor; tasa BPA de los parámetros de la semana.
//   Materiales y anticipos se descuentan en la LQ de mayor valor de fruta (como el Excel).
//   No se liquida: compra CONTRATO sin contrato (R1), compra sin precio real (R2), semana sin parámetros (R3).
//   Lo de abajo es la regla anterior; donde dice "pagadas" hoy son las RECIBIDAS.
//   Fruta      = (embarcadas + caídas) × precio real de cada compra  (rechazadas NO se pagan)
//   Materiales = (retirado − devuelto a bodega) × costo promedio del retiro,
//                solo categorías marcadas "se cobra al productor" (cartón, plásticos...)
//   Anticipos  = lo que se elige descontar de sus anticipos/préstamos con saldo
//   Transporte = transporte de contenedor negociado en cada compra (29/10/2026), si la fruta llegó
//   Transporte reconocido (11/11/2026) = pagadas × $/caja cuando el productor puso el transporte
//   Neto       = fruta − materiales − transporte + transporte reconocido − anticipos
//   Nota de crédito (30/10/2026): el vendedor factura al precio OFICIAL (MAG) y devuelve la
//   diferencia con el real:  factura = pagadas × oficial;  NC = factura − fruta.
//   El N° de factura y de NC se registran cuando llegan; no bloquean el pago.
//   13/11/2026: Bono / extra = pagadas × $/caja (CINTA, EMPAQUE ESPECIAL…). Es un PAGO APARTE: se suma
//   al neto en el 1er pago como el transporte reconocido, no toca la NC ni la base de retención.
//   La NC nunca es negativa: NC = máx(0, factura − fruta) (decisión de Peter).
// Pagos: las compras "100" se pagan completas en el 1er pago; las "80_20" 80 % en el 1er
// pago y 20 % en el 2do. Los descuentos salen del 1er pago (si no alcanza, del 2do).
// 19/11/2026 (fase 1b-1, docs/modulos/pagos-liquidaciones.md 7.4 y 13.A): reemplaza lo de arriba.
//   - El 80 % SPOT lo pide Comercial con PAGAR antes de liquidar (Anticipo80). La LQ SPOT lo descuenta completo si
//     ya salió al banco (EN BANCO o PAGADA) y anula el que no salió (su fruta se paga en la cuota).
//   - Si el 80 % supera el neto, la cuota queda en 0 y el exceso es SALDO A FAVOR DE AGAT (anticipo origin SALDO).
//   - Una cuota por LQ: CONTRATO "PAGO ÚNICO (100 %)" y SPOT "SALDO SPOT", con ORD-…, POR APROBAR y semana de pago
//     N+1 (CONTRATO: + payment_week_offset). Mientras llega la 1b-2, se registran en "Pagos e historial" como hoy.
// Liquidar deja el cupo LIQUIDADO: no más despachos, devoluciones ni cambios de tarjas.
//
//   GET   /settlements/quotas                 cupos de Comercial y su estado de liquidación
//   GET   /settlements/preview/{quotaId}      cálculo sin guardar + anticipos con saldo
//   POST  /settlements                        liquidar
//   GET   /settlements                        historial (filtros: third_party_id, pendientes)
//   GET   /settlements/{id}                   detalle
//   PATCH /settlements/{id}/payments/{n}/pay    registrar pago (fecha + comprobante)
//                                               18/11/2026: bloqueado si el vendedor no tiene cuenta bancaria o cédula/RUC
//   PATCH /settlements/{id}/payments/{n}/unpay  deshacer el registro de un pago
//   PATCH /settlements/{id}/annul             anular (solo sin pagos registrados)
//   PATCH /settlements/{id}/documents         N° de factura y de nota de crédito del vendedor

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ProducerAdvance;
use App\Models\ProducerQuota;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use App\Models\SettlementWeekParameter;
use App\Models\ThirdPartyBankAccount;
use App\Services\Anticipo80;
use App\Services\RetencionBanano;
use App\Support\CuentaBancaria;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    // ---------------------------------------------------------------- listados

    public function quotas(Request $request)
    {
        // 16/11/2026 (rendimiento R6): ?recientes=1 → últimas 8 semanas + todo lo que aún no tiene liquidación
        // (antes siempre venía toda la historia). La pantalla pide todo con "Ver semanas anteriores".
        $desde = now()->subWeeks(8);
        $cupos = ProducerQuota::with([
            'thirdParty:id,name,type',
            'lines' => fn ($q) => $q->whereNotNull('customer_id')->withSum('tallies as t_embarcadas', 'boxes_shipped')
                ->withSum('tallies as t_caidas', 'boxes_fallen')->withSum('tallies as t_rechazadas', 'boxes_rejected')
                ->withSum('tallies as t_faltantes', 'boxes_missing'), // 17/11/2026
            'settlements.payments',
        ])
            ->whereNotNull('customer_order_id')
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->year))
            ->when($request->filled('week'), fn ($q) => $q->where('week_number', $request->week))
            ->when($request->boolean('recientes'), fn ($q) => $q->where(fn ($x) => $x
                ->whereDoesntHave('settlements')
                ->orWhere('year', '>', $desde->isoWeekYear)
                ->orWhere(fn ($y) => $y->where('year', $desde->isoWeekYear)->where('week_number', '>=', $desde->isoWeek))))
            ->orderByDesc('year')->orderByDesc('week_number')->orderBy('id')
            ->get();

        return response()->json($cupos->map(function ($c) {
            $embarcadas = (int) $c->lines->sum('t_embarcadas');
            $caidas = (int) $c->lines->sum('t_caidas');
            $rechazadas = (int) $c->lines->sum('t_rechazadas');
            $faltantes = (int) $c->lines->sum('t_faltantes');

            return [
                'id'          => $c->id,
                'third_party' => $c->thirdParty,
                'week'        => $c->week_number,
                'year'        => $c->year,
                'status'      => $c->status,
                'comprado'    => (int) $c->lines->sum('quantity_cajas'),
                'embarcadas'  => $embarcadas,
                'caidas'      => $caidas,
                'rechazadas'  => $rechazadas,
                'faltantes'   => $faltantes,
                // 17/11/2026: lo que se paga = RECIBIDAS (antes embarcadas + caídas)
                'recibidas'   => $embarcadas - $caidas - $rechazadas - $faltantes,
                // 17/11/2026: varias LQ por cupo (una por contrato y una de SPOT)
                'settlements' => $c->settlements->sortBy('id')->values()->map(fn ($liq) => [
                    'id'          => $liq->id,
                    'code'        => $liq->code,
                    'group_label' => $liq->group_label,
                    'net_amount'  => $liq->net_amount,
                    'pagado'      => $liq->payments->every(fn ($p) => $p->paid_at !== null),
                ]),
            ];
        }));
    }

    public function index(Request $request)
    {
        $query = Settlement::with(['thirdParty:id,name,type', 'payments'])
            ->orderByDesc('year')->orderByDesc('week')->orderByDesc('id');

        if ($request->filled('third_party_id')) {
            $query->where('third_party_id', $request->third_party_id);
        }
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
        }
        // ?nc_pendiente=1 → activas con nota de crédito por recibir (sin N° registrado)
        if ($request->boolean('nc_pendiente')) {
            $query->where('status', true)->where('credit_note_amount', '>', 0)->whereNull('credit_note_number');
        }
        // ?pendientes=1 → activas con algún pago sin registrar
        if ($request->boolean('pendientes')) {
            $query->where('status', true)->whereHas('payments', fn ($q) => $q->whereNull('paid_at'));
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        return response()->json($this->detalle(Settlement::findOrFail($id)));
    }

    // ---------------------------------------------------------------- cálculo

    public function preview($quotaId)
    {
        $cupo = ProducerQuota::with('thirdParty:id,name,type,retention_regime,identification')->findOrFail($quotaId);
        $calculo = $this->calcular($cupo);

        // 19/11/2026 (fase 1b-1): el 80 % no se elige: la LQ SPOT lo descuenta sola. 'origin' SALDO = saldo a favor
        // de AGAT de una LQ anterior, que la pantalla marca de entrada.
        $anticipos = ProducerAdvance::where('third_party_id', $cupo->third_party_id)->where('status', true)
            ->where('origin', '<>', 'SPOT_80')
            ->orderBy('date')->orderBy('id')->get()
            ->map(fn ($a) => [
                'id'      => $a->id,
                'date'    => $a->date?->format('Y-m-d'),
                'type'    => $a->type,
                'origin'  => $a->origin,
                'concept' => $a->concept,
                'amount'  => $a->amount,
                'saldo'   => $a->saldo(),
            ])
            ->filter(fn ($a) => $a['saldo'] > 0)->values();

        // 13/11/2026: compra global POR DIVIDIR: se puede liquidar (se le paga a la comercializadora), solo se avisa
        $porDividir = (int) $cupo->lines()->where('pending_split', true)->sum('quantity_cajas');
        $avisos = $porDividir > 0
            ? ['HAY ' . number_format($porDividir, 0, ',', '.') . " CAJAS POR DIVIDIR DE {$cupo->thirdParty?->name}: FALTA EL CUADRO DE PRODUCTORES."]
            : [];
        // 18/11/2026 (fase 2b, Peter): sin cédula/RUC se puede liquidar con aviso; el pago queda bloqueado
        if (trim((string) $cupo->thirdParty?->identification) === '') {
            $avisos[] = 'EL VENDEDOR NO TIENE CÉDULA NI RUC: COMPLÉTALA EN SU FICHA ANTES DE PAGAR.';
        }
        $avisos = array_merge($avisos, $calculo['avisos_80']); // 19/11/2026: tarja menor que lo comprado

        return response()->json([
            'avisos'        => $avisos,
            // 18/11/2026 (fase 2b): cuenta enmascarada; sin cuenta, la pantalla muestra el aviso con el botón "+"
            'cuenta_vendedor' => CuentaBancaria::enmascarada(ThirdPartyBankAccount::where('third_party_id', $cupo->third_party_id)->first()),
            'quota'     => [
                'id'          => $cupo->id,
                'third_party' => $cupo->thirdParty,
                'week'        => $cupo->week_number,
                'year'        => $cupo->year,
                'status'      => $cupo->status,
            ],
            // 17/11/2026: LQ activas del cupo (una por contrato y una de SPOT)
            'settlements'   => $cupo->settlements()->orderBy('id')->get(['id', 'code', 'group_label']),
            'advances'      => $anticipos,
        ] + $calculo);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'producer_quota_id'  => 'required|exists:producer_quotas,id',
            'date'               => 'required|date',
            'notes'              => 'nullable|string|max:500',
            'advances'           => 'present|array',
            'advances.*.id'      => 'required|integer|distinct',
            'advances.*.amount'  => 'required|numeric|min:0.01',
        ]);

        $liquidacion = DB::transaction(function () use ($data, $request) {
            // Bloquea el cupo: dos personas no liquidan el mismo cupo, y el despacho no le
            // entrega material mientras se liquida
            $cupo = ProducerQuota::lockForUpdate()->findOrFail($data['producer_quota_id']);
            // 19/11/2026: bloquea también los 80 % del cupo, para que Contabilidad no los meta en un lote mientras se liquida
            $calculo = $this->calcular($cupo, true);
            if ($calculo['problemas']) {
                $this->error(implode(' ', $calculo['problemas']));
            }

            // Anticipos a descontar: del mismo productor, activos y sin pasarse del saldo
            $anticiposTotal = 0;
            $aplicar = [];
            foreach ($data['advances'] as $a) {
                $anticipo = ProducerAdvance::lockForUpdate()->find($a['id']);
                if (!$anticipo || $anticipo->third_party_id !== $cupo->third_party_id || !$anticipo->status) {
                    $this->error('UNO DE LOS ANTICIPOS NO ES DE ESTE PRODUCTOR O ESTÁ INACTIVO.');
                }
                if ($anticipo->origin === 'SPOT_80') { // 19/11/2026: lo descuenta sola la LQ SPOT
                    $this->error("EL ANTICIPO 80 % {$anticipo->payment_order_code} NO SE ELIGE: LA LIQUIDACIÓN SPOT LO DESCUENTA SOLA.");
                }
                $monto = round((float) $a['amount'], 2);
                if ($monto > $anticipo->saldo()) {
                    $this->error('EL ANTICIPO DEL ' . $anticipo->date->format('d/m/Y') . ' TIENE SALDO DE '
                        . number_format($anticipo->saldo(), 2, ',', '.') . '; NO SE PUEDE DESCONTAR ' . number_format($monto, 2, ',', '.') . '.');
                }
                $anticiposTotal += $monto;
                $aplicar[] = ['producer_advance_id' => $anticipo->id, 'amount' => $monto];
            }
            $anticiposTotal = round($anticiposTotal, 2);

            // 17/11/2026 (fase 0): una LQ por grupo (cada contrato y SPOT). Materiales y anticipos van en la
            // LQ de mayor valor de fruta (la que 'lleva_descuentos'); la retención, en cada una.
            $notas = isset($data['notes']) ? mb_strtoupper(trim($data['notes'])) : null;
            $principal = null;
            foreach ($calculo['groups'] as $g) {
                $materiales = $g['lleva_descuentos'] ? $calculo['materials_amount'] : 0;
                $anticipos = $g['lleva_descuentos'] ? $anticiposTotal : 0;
                // Descuentos: materiales + transporte negociado − transporte reconocido (11/11/2026) − bono (13/11/2026)
                // + retención IR (17/11/2026) + anticipos
                $fijos = round($materiales + $g['transport_amount'] - $g['transport_recognized_amount'] - $g['bonus_amount'] + $g['retention_amount'], 2);
                $neto = round($g['fruit_amount'] - $fijos - $anticipos, 2);
                if ($neto < 0) {
                    $this->error("{$g['group_label']}: LOS DESCUENTOS (" . number_format($fijos + $anticipos, 2, ',', '.')
                        . ') SUPERAN EL VALOR DE LA FRUTA (' . number_format($g['fruit_amount'], 2, ',', '.') . '). DESCUENTA MENOS ANTICIPO.');
                }
                // 19/11/2026 (fase 1b-1): la LQ SPOT descuenta el 80 % ya pagado; lo que pase del neto es SALDO A FAVOR DE AGAT
                $ochenta = $g['advance_80_amount'];
                $saldoAFavor = max(0, round($ochenta - $neto, 2));
                $neto = max(0, round($neto - $ochenta, 2));

                $liq = Settlement::create([
                    'code'              => $this->siguienteCodigo(),
                    'producer_quota_id' => $cupo->id,
                    'negotiation_type'  => $g['negotiation_type'],
                    'contract_id'       => $g['contract_id'],
                    'group_label'       => $g['group_label'],
                    'third_party_id'    => $cupo->third_party_id,
                    'week'              => $cupo->week_number,
                    'year'              => $cupo->year,
                    'date'              => $data['date'],
                    'boxes_shipped'     => $g['boxes_shipped'],
                    'boxes_fallen'      => $g['boxes_fallen'],
                    'boxes_rejected'    => $g['boxes_rejected'],
                    'boxes_missing'     => $g['boxes_missing'],
                    'boxes_paid'        => $g['boxes_paid'],
                    'fruit_amount'      => $g['fruit_amount'],
                    'materials_amount'  => $materiales,
                    'transport_amount'  => $g['transport_amount'],
                    'transport_recognized_amount' => $g['transport_recognized_amount'],
                    'bonus_amount'      => $g['bonus_amount'], // 13/11/2026
                    'invoice_amount'    => $g['invoice_amount'],
                    'credit_note_amount' => $g['credit_note_amount'],
                    'retention_regime'  => $g['retention_regime'],
                    'retention_base'    => $g['retention_base'],
                    'retention_rate'    => $g['retention_rate'],
                    'retention_amount'  => $g['retention_amount'],
                    'advances_amount'   => $anticipos,
                    'advance_80_amount' => $ochenta, // 19/11/2026
                    'net_amount'        => $neto,
                    'notes'             => $notas,
                    'status'            => true,
                    'created_by'        => $request->user()?->id,
                ]);

                foreach ($g['lines'] as $l) {
                    $liq->lines()->create(collect($l)->only([
                        'producer_quota_line_id', 'negotiation_type', 'payment_scheme', 'boxes_purchased',
                        'boxes_shipped', 'boxes_fallen', 'boxes_rejected', 'boxes_missing', 'boxes_paid', 'real_price', 'amount',
                        'transport_deduction', 'transport_recognized', 'official_price', 'invoice_amount', 'credit_note_amount',
                        'bonus_rate', 'bonus_reason', 'bonus_amount', // 13/11/2026
                    ])->all());
                }
                if ($g['lleva_descuentos']) {
                    foreach ($calculo['materials'] as $m) {
                        $liq->materials()->create(collect($m)->only([
                            'supply_id', 'dispatched_qty', 'returned_qty', 'charged_qty', 'unit_cost', 'amount',
                        ])->all());
                    }
                    foreach ($aplicar as $a) {
                        $liq->advances()->create($a);
                    }
                }
                // 19/11/2026 (fase 1b-1): el 80 % que salió al banco se descuenta completo (queda en settlement_advances)
                if ($g['negotiation_type'] === 'SPOT') {
                    foreach ($calculo['anticipos_80'] as $o) {
                        if ($o['descuenta']) {
                            $liq->advances()->create(['producer_advance_id' => $o['id'], 'amount' => $o['saldo']]);
                        }
                    }
                }
                if ($saldoAFavor > 0) {
                    // ANTERIOR: no es un pago, no entra a las bandejas de aprobación ni al banco; se descuenta en la siguiente LQ
                    ProducerAdvance::create([
                        'third_party_id'       => $cupo->third_party_id,
                        'date'                 => $data['date'],
                        'type'                 => 'ANTICIPO',
                        'amount'               => $saldoAFavor,
                        'concept'              => "SALDO A FAVOR DE AGAT {$liq->code} WK{$cupo->week_number}: EL 80 % PASÓ EL NETO",
                        'status'               => true,
                        'created_by'           => $request->user()?->id,
                        'origin'               => 'SALDO',
                        'source_settlement_id' => $liq->id,
                        'producer_quota_id'    => $cupo->id,
                        'approval_status'      => 'ANTERIOR',
                    ]);
                }
                if ($cuota = $this->cuota($g, $cupo, $neto)) {
                    $liq->payments()->create($cuota);
                }
                if ($g['lleva_descuentos']) {
                    $principal = $liq;
                }
            }

            // 19/11/2026: el 80 % que no salió al banco se anula; su fruta va completa en la cuota
            foreach ($calculo['anticipos_80'] as $o) {
                if (!$o['descuenta']) {
                    Anticipo80::anular(ProducerAdvance::findOrFail($o['id']), "SE LIQUIDÓ EL CUPO ({$principal?->code}): TODO SE PAGA EN LA LIQUIDACIÓN");
                }
            }

            $cupo->update(['status' => ProducerQuota::LIQUIDADO]);

            // Se abre la LQ que lleva los descuentos (las demás se ven desde su detalle)
            return $principal;
        });

        return response()->json($this->detalle($liquidacion), 201);
    }

    // ---------------------------------------------------------------- pagos y anulación

    public function pay(Request $request, $id, $installment)
    {
        $data = $request->validate([
            'paid_at'   => 'required|date',
            'reference' => 'nullable|string|max:60',
        ], ['paid_at.required' => 'ESCRIBE LA FECHA DEL PAGO.']);

        $liq = Settlement::findOrFail($id);
        if (!$liq->status) {
            return response()->json(['message' => 'LA LIQUIDACIÓN ESTÁ ANULADA.'], 422);
        }
        $pago = $liq->payments()->where('installment', $installment)->firstOrFail();
        if ($pago->paid_at) {
            return response()->json(['message' => 'ESTE PAGO YA ESTÁ REGISTRADO.'], 422);
        }
        // 18/11/2026 (fase 2b, Peter): no se paga a un vendedor sin cuenta bancaria ni sin cédula/RUC.
        // Liquidar sí se puede (con aviso); el pago queda bloqueado hasta que Liquidaciones complete los datos.
        // Peter: se sube apagado (config/liquidacion.php); mientras tanto la pantalla solo avisa.
        if (config('liquidacion.bloquear_pago_sin_datos')) {
            $vendedor = $liq->thirdParty()->first(['id', 'identification']);
            $cuenta = ThirdPartyBankAccount::where('third_party_id', $liq->third_party_id)->first();
            if (!$cuenta) {
                return response()->json(['message' => 'EL VENDEDOR NO TIENE CUENTA BANCARIA: LIQUIDACIONES DEBE REGISTRARLA ANTES DE PAGAR.'], 422);
            }
            // 18/11/2026 (Peter): con el bloqueo encendido, la cuenta además debe estar VERIFICADA por Contabilidad
            if (!$cuenta->verificada()) {
                return response()->json(['message' => 'LA CUENTA BANCARIA DEL VENDEDOR ESTÁ PENDIENTE: CONTABILIDAD DEBE VERIFICARLA ANTES DE PAGAR.'], 422);
            }
            if (trim((string) $vendedor?->identification) === '') {
                return response()->json(['message' => 'EL VENDEDOR NO TIENE CÉDULA NI RUC: COMPLÉTALA EN SU FICHA ANTES DE PAGAR.'], 422);
            }
        }
        $pago->update([
            'paid_at'   => $data['paid_at'],
            'reference' => isset($data['reference']) ? mb_strtoupper(trim($data['reference'])) : null,
            'paid_by'   => $request->user()?->id,
        ]);

        return response()->json($this->detalle($liq->fresh()));
    }

    /**
     * N° de factura y de nota de crédito que entregó el vendedor (se pueden corregir o borrar).
     */
    public function documents(Request $request, $id)
    {
        $data = $request->validate([
            'invoice_number'     => 'nullable|string|max:30',
            'credit_note_number' => 'nullable|string|max:30',
            'credit_note_date'   => 'nullable|date|required_with:credit_note_number',
        ], ['credit_note_date.required_with' => 'ESCRIBE LA FECHA DE LA NOTA DE CRÉDITO.']);

        $liq = Settlement::findOrFail($id);
        if (!$liq->status) {
            return response()->json(['message' => 'LA LIQUIDACIÓN ESTÁ ANULADA.'], 422);
        }
        $limpio = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;
        $nc = $limpio($data['credit_note_number'] ?? null);
        $liq->update([
            'invoice_number'     => $limpio($data['invoice_number'] ?? null),
            'credit_note_number' => $nc,
            'credit_note_date'   => $nc ? $data['credit_note_date'] : null,
            'documents_by'       => $request->user()?->id,
        ]);

        return response()->json($this->detalle($liq->fresh()));
    }

    public function unpay($id, $installment)
    {
        $liq = Settlement::findOrFail($id);
        $pago = $liq->payments()->where('installment', $installment)->firstOrFail();
        $pago->update(['paid_at' => null, 'reference' => null, 'paid_by' => null]);

        return response()->json($this->detalle($liq->fresh()));
    }

    public function annul(Request $request, $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ], ['reason.required' => 'ESCRIBE EL MOTIVO DE LA ANULACIÓN.']);

        DB::transaction(function () use ($id, $data) {
            $liq = Settlement::lockForUpdate()->findOrFail($id);
            if (!$liq->status) {
                $this->error('LA LIQUIDACIÓN YA ESTÁ ANULADA.');
            }
            // 17/11/2026 (fase 0): las LQ de un cupo (contratos y SPOT) se crearon juntas y se anulan juntas
            $hermanas = Settlement::lockForUpdate()->where('producer_quota_id', $liq->producer_quota_id)
                ->where('status', true)->orderBy('id')->get();
            foreach ($hermanas as $h) {
                if ($h->payments()->whereNotNull('paid_at')->exists()) {
                    $this->error("LA LIQUIDACIÓN {$h->code} TIENE PAGOS REGISTRADOS: DESHAZ LOS PAGOS ANTES DE ANULAR.");
                }
                // 19/11/2026 (fase 1b-1): la cuota aprobada o en el banco primero se devuelve (o se anula su lote)
                if ($h->payments()->where(fn ($q) => $q->where('approval_status', 'APROBADA')->orWhereNotNull('payment_status'))->exists()) {
                    $this->error("LA CUOTA DE {$h->code} ESTÁ APROBADA O EN EL BANCO: DEVUÉLVELA O ANULA SU LOTE ANTES DE ANULAR.");
                }
                foreach (ProducerAdvance::lockForUpdate()->where('source_settlement_id', $h->id)->where('status', true)->get() as $saldo) {
                    if ($saldo->descontado() > 0) {
                        $this->error("EL SALDO A FAVOR DE AGAT DE {$h->code} YA SE DESCONTÓ EN OTRA LIQUIDACIÓN: ANULA ESA PRIMERO.");
                    }
                }
            }
            // Al quedar inactivas, sus anticipos (y los 80 % descontados) vuelven a tener saldo (ProducerAdvance::saldo)
            foreach ($hermanas as $h) {
                $h->update(['status' => false, 'cancel_reason' => mb_strtoupper(trim($data['reason']))]);
                // 19/11/2026: sus cuotas y su SALDO a favor se anulan (uno por uno: bitácora)
                $h->payments()->whereIn('approval_status', ['POR_APROBAR', 'RECHAZADA'])->get()
                    ->each(fn ($p) => $p->update(['approval_status' => 'ANULADA']));
                ProducerAdvance::where('source_settlement_id', $h->id)->where('status', true)->get()
                    ->each(fn ($a) => $a->update(['status' => false]));
            }

            $cupo = $liq->quota;
            $cupo->update(['status' => $cupo->dispatches()->where('status', true)->exists()
                ? ProducerQuota::DESPACHADO : ProducerQuota::ASIGNADO]);
        });

        return response()->json($this->detalle(Settlement::findOrFail($id)));
    }

    // ---------------------------------------------------------------- internos

    /**
     * Calcula fruta y materiales de un cupo. 'problemas' trae lo que impide liquidar.
     */
    private function calcular(ProducerQuota $cupo, bool $bloquear = false): array
    {
        $problemas = [];
        if ($cupo->status === ProducerQuota::LIQUIDADO) {
            $problemas[] = 'ESTE CUPO YA FUE LIQUIDADO.';
        }

        $compras = $cupo->lines()->with(['brand:id,name,code', 'customer.thirdParty:id,name', 'producer:id,name'])
            ->whereNotNull('customer_id')
            ->withSum('tallies as t_embarcadas', 'boxes_shipped')
            ->withSum('tallies as t_caidas', 'boxes_fallen')
            ->withSum('tallies as t_rechazadas', 'boxes_rejected')
            ->withSum('tallies as t_faltantes', 'boxes_missing') // 17/11/2026
            ->withCount('tallies')
            ->with('contract:id,number')
            ->orderBy('id')->get();

        if ($compras->isEmpty()) {
            $problemas[] = 'ESTE CUPO NO TIENE COMPRAS DE COMERCIAL.';
        } elseif ($compras->sum('tallies_count') === 0) {
            $problemas[] = 'NO HAY TARJAS REGISTRADAS PARA ESTE PRODUCTOR EN ESTA SEMANA.';
        }

        // 17/11/2026 (R3): sin los parámetros de la semana no hay tasa BPA ni precio mínimo para la retención
        $parametros = SettlementWeekParameter::where('year', $cupo->year)->where('week', $cupo->week_number)->first();
        if (!$parametros) {
            $problemas[] = "REGISTRA LOS PARÁMETROS DE LA WK{$cupo->week_number} EN LIQUIDACIÓN → PARÁMETROS DE LA SEMANA.";
        }

        $lineas = [];
        foreach ($compras as $c) {
            // 17/11/2026: las compras sin tarja no entran en la liquidación (no hay cajas que pagar)
            if ((int) $c->tallies_count === 0) {
                continue;
            }
            $embarcadas = (int) $c->t_embarcadas;
            $caidas = (int) $c->t_caidas;
            $faltantes = (int) $c->t_faltantes;
            // 17/11/2026: RECIBIDAS = embarcadas − caídas − rechazadas − faltantes (antes: embarcadas + caídas)
            $pagadas = $embarcadas - $caidas - (int) $c->t_rechazadas - $faltantes;
            // 17/11/2026 (R1): la LQ de contrato necesita saber de qué contrato es
            if ($c->negotiation_type === 'CONTRATO' && $c->contract_id === null) {
                $problemas[] = "ASIGNA EL CONTRATO DE LA COMPRA {$c->brand?->name} ({$c->customer?->thirdParty?->name}) EN COMPRA DE FRUTA, O PÁSALA A SPOT.";
            }
            if ($pagadas > 0 && $c->real_price === null) {
                $problemas[] = "FALTA EL PRECIO REAL DE LA COMPRA {$c->brand?->name} ({$c->customer?->thirdParty?->name}).";
            }
            if ($pagadas > 0 && $c->official_price === null) {
                $problemas[] = "FALTA EL PRECIO OFICIAL DE LA COMPRA {$c->brand?->name} ({$c->customer?->thirdParty?->name}): SIN ÉL NO SE SABE LA NOTA DE CRÉDITO.";
            }
            $precio = (float) ($c->real_price ?? 0);
            $oficial = $c->official_price === null ? null : (float) $c->official_price;
            $fruta = round($pagadas * $precio, 2);
            $factura = $oficial === null ? 0 : round($pagadas * $oficial, 2);
            $lineas[] = [
                'producer_quota_line_id' => $c->id,
                'customer'               => $c->customer?->thirdParty?->name,
                'brand'                  => $c->brand?->name,
                'negotiation_type'       => $c->negotiation_type,
                'payment_scheme'         => $c->payment_scheme,
                'boxes_purchased'        => (int) $c->quantity_cajas,
                'boxes_shipped'          => $embarcadas,
                'boxes_fallen'           => $caidas,
                'boxes_rejected'         => (int) $c->t_rechazadas,
                'boxes_missing'          => $faltantes,
                'boxes_paid'             => $pagadas, // RECIBIDAS
                'contract_id'            => $c->contract_id,
                'contract_number'        => $c->contract?->number,
                'real_price'             => $precio,
                'amount'                 => $fruta,
                'official_price'         => $oficial,
                'invoice_amount'         => $factura,
                // 13/11/2026: si el real pasa el oficial, la NC queda en 0 (nunca negativa)
                'credit_note_amount'     => $oficial === null ? 0 : max(0, round($factura - $fruta, 2)),
                // Transporte de contenedor negociado al comprar: se descuenta si la fruta llegó
                'transport_deduction'    => $pagadas > 0 ? round((float) $c->transport_deduction, 2) : 0,
                // 11/11/2026: transporte que se le reconoce, solo por las cajas que llegaron (tarja)
                'transport_recognized'   => round($pagadas * (float) ($c->transport_recognized_rate ?? 0), 2),
                'transport_recognized_rate' => $c->transport_recognized_rate === null ? null : (float) $c->transport_recognized_rate,
                // 13/11/2026: bono / extra, solo por las cajas que llegaron (tarja); pago aparte de la fruta
                'bonus_rate'             => $c->bonus_rate === null ? null : (float) $c->bonus_rate,
                'bonus_reason'           => $c->bonus_reason,
                'bonus_detail'           => $c->bonus_detail,
                'bonus_amount'           => round($pagadas * (float) ($c->bonus_rate ?? 0), 2),
                'producer'               => $c->producer?->name,
                'farm_name'              => $c->farm_name,
            ];
        }

        $materiales = $this->materiales($cupo->id);
        $lineasCol = collect($lineas);
        $grupos = $this->grupos($lineas, $cupo, $parametros);

        // 19/11/2026 (fase 1b-1): los 80 % activos del cupo. EN BANCO o PAGADA → los descuenta la LQ SPOT; el resto se anula.
        $ochenta = [];
        $avisos80 = [];
        $ordenes = ProducerAdvance::with('quotaLine')->where('origin', 'SPOT_80')->where('status', true)
            ->where('producer_quota_id', $cupo->id)->when($bloquear, fn ($q) => $q->lockForUpdate())->orderBy('id')->get();
        foreach ($ordenes as $o) {
            $descuenta = Anticipo80::enBanco($o);
            $ochenta[] = [
                'id'        => $o->id,
                'code'      => $o->payment_order_code,
                'estado'    => Anticipo80::estado($o),
                'boxes'     => (int) $o->boxes,
                'amount'    => (float) $o->amount,
                'descuenta' => $descuenta,
                'saldo'     => $descuenta ? $o->saldo() : 0.0,
            ];
            if ($descuenta && $o->quotaLine) {
                $ids = Anticipo80::partes($o->quotaLine)->pluck('id')->all();
                $recibidas = (int) $lineasCol->whereIn('producer_quota_line_id', $ids)->sum('boxes_paid');
                if ($recibidas < (int) $o->boxes) {
                    $avisos80[] = "{$o->payment_order_code}: RECIBIDAS (" . number_format($recibidas, 0, ',', '.') . ') MENOR QUE LO COMPRADO ('
                        . number_format((int) $o->boxes, 0, ',', '.') . '): EL 80 % SE PAGÓ DE MÁS.';
                }
            }
        }
        $total80 = round(collect($ochenta)->sum('saldo'), 2);
        $spot = collect($grupos)->search(fn ($g) => $g['negotiation_type'] === 'SPOT');
        if ($spot !== false) {
            $grupos[$spot]['advance_80_amount'] = $total80;
        } elseif ($total80 > 0) {
            $problemas[] = 'EL CUPO TIENE ANTICIPO 80 % PAGADO (' . collect($ochenta)->where('descuenta', true)->pluck('code')->implode(', ')
                . ') Y NINGUNA COMPRA SPOT CON TARJA: REGISTRA LA TARJA ANTES DE LIQUIDAR.';
        }

        return [
            'problemas'        => array_values(array_unique($problemas)),
            'anticipos_80'     => $ochenta,  // 19/11/2026
            'avisos_80'        => $avisos80,
            'advance_80_amount' => $total80,
            'lines'            => $lineas,
            'groups'           => $grupos, // 17/11/2026: una LQ por contrato y una de SPOT
            'materials'        => $materiales,
            'boxes_shipped'    => $lineasCol->sum('boxes_shipped'),
            'boxes_fallen'     => $lineasCol->sum('boxes_fallen'),
            'boxes_rejected'   => $lineasCol->sum('boxes_rejected'),
            'boxes_missing'    => $lineasCol->sum('boxes_missing'),
            'boxes_paid'       => $lineasCol->sum('boxes_paid'),
            'retention_amount' => round(collect($grupos)->sum('retention_amount'), 2),
            'fruit_amount'     => round($lineasCol->sum('amount'), 2),
            'materials_amount' => round(collect($materiales)->sum('amount'), 2),
            'transport_amount' => round($lineasCol->sum('transport_deduction'), 2),
            'transport_recognized_amount' => round($lineasCol->sum('transport_recognized'), 2),
            'bonus_amount'     => round($lineasCol->sum('bonus_amount'), 2), // 13/11/2026
            'invoice_amount'   => round($lineasCol->sum('invoice_amount'), 2),
            'credit_note_amount' => round($lineasCol->sum('credit_note_amount'), 2),
        ];
    }

    /**
     * 17/11/2026 (fase 0): reparte las compras en LQ como el Excel: una por cada CONTRATO (ordenadas por
     * número) y una de SPOT. Cada una con sus cajas, montos y retención IR. 'lleva_descuentos' marca la de
     * mayor valor de fruta, que es la que descuenta materiales y anticipos.
     */
    private function grupos(array $lineas, ProducerQuota $cupo, ?SettlementWeekParameter $parametros): array
    {
        $regimen = $cupo->thirdParty?->retention_regime ?: 'NORMAL';
        $tasaBpa = (float) ($parametros?->bpa_rate ?? 0);
        $minimo = $parametros?->minimum_price === null ? null : (float) $parametros->minimum_price;
        $calc = new RetencionBanano();

        $grupos = collect($lineas)
            ->groupBy(fn ($l) => $l['negotiation_type'] === 'CONTRATO' ? 'C' . ($l['contract_id'] ?? 0) : 'SPOT')
            ->map(function ($ls, $clave) use ($regimen, $tasaBpa, $minimo, $calc) {
                $esContrato = $clave !== 'SPOT';
                $recibidas = (int) $ls->sum('boxes_paid');
                // Base = recibidas × precio liquidado (el mayor entre el real y el oficial; sin oficial, el mínimo de la semana)
                $base = round($ls->sum(fn ($l) => $l['boxes_paid'] * max($l['real_price'], $l['official_price'] ?? $minimo ?? 0)), 2);
                $tasa = $calc->tasa($recibidas, $regimen, $tasaBpa);

                return [
                    'key'              => $clave,
                    'negotiation_type' => $esContrato ? 'CONTRATO' : 'SPOT',
                    'contract_id'      => $esContrato ? $ls->first()['contract_id'] : null,
                    'contract_number'  => $esContrato ? $ls->first()['contract_number'] : null,
                    'group_label'      => $esContrato ? 'CONTRATO ' . ($ls->first()['contract_number'] ?? '?') : 'SPOT',
                    'lines'            => $ls->values()->all(),
                    'boxes_shipped'    => (int) $ls->sum('boxes_shipped'),
                    'boxes_fallen'     => (int) $ls->sum('boxes_fallen'),
                    'boxes_rejected'   => (int) $ls->sum('boxes_rejected'),
                    'boxes_missing'    => (int) $ls->sum('boxes_missing'),
                    'boxes_paid'       => $recibidas,
                    'fruit_amount'     => round($ls->sum('amount'), 2),
                    'transport_amount' => round($ls->sum('transport_deduction'), 2),
                    'transport_recognized_amount' => round($ls->sum('transport_recognized'), 2),
                    'bonus_amount'     => round($ls->sum('bonus_amount'), 2),
                    'invoice_amount'   => round($ls->sum('invoice_amount'), 2),
                    'credit_note_amount' => round($ls->sum('credit_note_amount'), 2),
                    'retention_regime' => $regimen,
                    'retention_base'   => $base,
                    'retention_rate'   => $tasa,
                    'retention_amount' => $calc->retencion($base, $tasa),
                    'advance_80_amount' => 0, // 19/11/2026: lo pone calcular() en la LQ SPOT
                ];
            })
            ->sortBy(fn ($g) => $g['negotiation_type'] === 'SPOT' ? PHP_INT_MAX : (int) ($g['contract_number'] ?? 0))
            ->values()->all();

        // Mayor valor de fruta (a igual valor, la primera)
        $mayor = collect($grupos)->sortByDesc('fruit_amount')->keys()->first();
        foreach ($grupos as $i => &$g) {
            $g['lleva_descuentos'] = $i === $mayor;
        }

        return $grupos;
    }

    /**
     * Materiales cobrables del cupo: retirado (despachos activos) − devuelto (devoluciones
     * activas ligadas al cupo), valorizado al costo promedio con que se retiró.
     */
    private function materiales(int $cupoId): array
    {
        $filas = DB::table('inventory_movement_lines as l')
            ->join('inventory_movements as m', 'm.id', '=', 'l.inventory_movement_id')
            ->join('supplies as s', 's.id', '=', 'l.supply_id')
            ->join('supply_categories as c', 'c.id', '=', 's.supply_category_id')
            ->where('m.producer_quota_id', $cupoId)
            ->where('m.status', true)
            ->whereIn('m.type', ['EGRESO', 'DEVOLUCION'])
            ->where('c.chargeable_to_producer', true)
            ->groupBy('l.supply_id', 's.name', 's.unit')
            ->selectRaw("l.supply_id, s.name, s.unit,
                SUM(CASE WHEN m.type = 'EGRESO' THEN l.quantity ELSE 0 END) as retirado,
                SUM(CASE WHEN m.type = 'EGRESO' THEN l.total ELSE 0 END) as valor_retirado,
                SUM(CASE WHEN m.type = 'DEVOLUCION' THEN l.quantity ELSE 0 END) as devuelto")
            ->orderBy('s.name')
            ->get();

        return $filas->filter(fn ($f) => (float) $f->retirado > 0)->map(function ($f) {
            $retirado = (float) $f->retirado;
            $devuelto = min((float) $f->devuelto, $retirado);
            $costo = (float) $f->valor_retirado / $retirado;
            $cobrado = round($retirado - $devuelto, 2);
            return [
                'supply_id'      => $f->supply_id,
                'name'           => $f->name,
                'unit'           => $f->unit,
                'dispatched_qty' => round($retirado, 2),
                'returned_qty'   => round($devuelto, 2),
                'charged_qty'    => $cobrado,
                'unit_cost'      => round($costo, 4),
                'amount'         => round($cobrado * $costo, 2),
            ];
        })->values()->all();
    }

    /**
     * 19/11/2026 (fase 1b-1, 7.4): una sola cuota por LQ (antes 1er pago 100 %/80 % y 2do pago 20 %).
     * CONTRATO = PAGO ÚNICO (100 %), semana N+1 + payment_week_offset; SPOT = SALDO SPOT (neto − 80 % ya pagado), N+1.
     * Nace POR APROBAR con su ORD-…; con neto 0 no hay cuota.
     */
    private function cuota(array $g, ProducerQuota $cupo, float $neto): ?array
    {
        if ($neto <= 0) {
            return null;
        }
        $esContrato = $g['negotiation_type'] === 'CONTRATO';
        $mas = 1 + ($esContrato ? (int) Contract::whereKey($g['contract_id'])->value('payment_week_offset') : 0);
        $semana = now()->setISODate($cupo->year, $cupo->week_number)->addWeeks($mas);

        return [
            'installment'        => 1,
            'description'        => $esContrato ? 'PAGO ÚNICO (100 %)' : 'SALDO SPOT',
            'amount'             => $neto,
            'payment_order_code' => ProducerAdvance::siguienteCodigoOrden(),
            'approval_status'    => 'POR_APROBAR',
            'scheduled_week'     => (int) $semana->isoWeek(),
            'scheduled_year'     => (int) $semana->isoWeekYear(),
        ];
    }

    private function detalle(Settlement $liq): array
    {
        $liq->load([
            'thirdParty:id,name,type,identification',
            'lines.purchase.brand:id,name,code',
            'lines.purchase.producer:id,name',
            'lines.purchase.customer.thirdParty:id,name',
            'materials.supply:id,name,unit',
            'advances.advance',
            'payments.payer:id,name',
            'creator:id,name',
            'documentsUser:id,name',
            'contract:id,number,price',
        ]);

        // 17/11/2026: las otras LQ del mismo cupo (contratos y SPOT se liquidan juntos)
        return $liq->toArray() + [
            'hermanas' => Settlement::where('producer_quota_id', $liq->producer_quota_id)->where('id', '!=', $liq->id)
                ->where('status', $liq->status)->orderBy('id')->get(['id', 'code', 'group_label', 'net_amount']),
            // 18/11/2026 (fase 2b): aviso SIN CUENTA BANCARIA con el botón "+" (enmascarada, nunca el número completo)
            'cuenta_vendedor' => CuentaBancaria::enmascarada(ThirdPartyBankAccount::where('third_party_id', $liq->third_party_id)->first()),
        ];
    }

    // LIQ-00000001, correlativo (se bloquea la tabla para no repetir el código)
    private function siguienteCodigo(): string
    {
        $ultimo = Settlement::lockForUpdate()->orderByDesc('id')->value('code');
        $siguiente = $ultimo ? ((int) substr($ultimo, 4)) + 1 : 1;

        return 'LIQ-' . str_pad($siguiente, 8, '0', STR_PAD_LEFT);
    }

    private function error(string $mensaje): void
    {
        throw new HttpResponseException(response()->json(['message' => $mensaje], 422));
    }
}
