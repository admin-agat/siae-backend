<?php

// LIQUIDACIÓN AL PRODUCTOR (29/10/2026)
// Ubicación: app/Http/Controllers/Api/SettlementController.php
//
// Se liquida un CUPO (productor + semana) con lo que dicen las TARJAS:
//   Fruta      = (embarcadas + caídas) × precio real de cada compra  (rechazadas NO se pagan)
//   Materiales = (retirado − devuelto a bodega) × costo promedio del retiro,
//                solo categorías marcadas "se cobra al productor" (cartón, plásticos...)
//   Anticipos  = lo que se elige descontar de sus anticipos/préstamos con saldo
//   Transporte = transporte de contenedor negociado en cada compra (29/10/2026), si la fruta llegó
//   Neto       = fruta − materiales − transporte − anticipos
//   Nota de crédito (30/10/2026): el vendedor factura al precio OFICIAL (MAG) y devuelve la
//   diferencia con el real:  factura = pagadas × oficial;  NC = factura − fruta.
//   El N° de factura y de NC se registran cuando llegan; no bloquean el pago.
// Pagos: las compras "100" se pagan completas en el 1er pago; las "80_20" 80 % en el 1er
// pago y 20 % en el 2do. Los descuentos salen del 1er pago (si no alcanza, del 2do).
// Liquidar deja el cupo LIQUIDADO: no más despachos, devoluciones ni cambios de tarjas.
//
//   GET   /settlements/quotas                 cupos de Comercial y su estado de liquidación
//   GET   /settlements/preview/{quotaId}      cálculo sin guardar + anticipos con saldo
//   POST  /settlements                        liquidar
//   GET   /settlements                        historial (filtros: third_party_id, pendientes)
//   GET   /settlements/{id}                   detalle
//   PATCH /settlements/{id}/payments/{n}/pay    registrar pago (fecha + comprobante)
//   PATCH /settlements/{id}/payments/{n}/unpay  deshacer el registro de un pago
//   PATCH /settlements/{id}/annul             anular (solo sin pagos registrados)
//   PATCH /settlements/{id}/documents         N° de factura y de nota de crédito del vendedor

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProducerAdvance;
use App\Models\ProducerQuota;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    // ---------------------------------------------------------------- listados

    public function quotas(Request $request)
    {
        $cupos = ProducerQuota::with([
            'thirdParty:id,name,type',
            'lines' => fn ($q) => $q->whereNotNull('customer_id')->withSum('tallies as t_embarcadas', 'boxes_shipped')
                ->withSum('tallies as t_caidas', 'boxes_fallen')->withSum('tallies as t_rechazadas', 'boxes_rejected'),
            'settlement.payments',
        ])
            ->whereNotNull('customer_order_id')
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->year))
            ->when($request->filled('week'), fn ($q) => $q->where('week_number', $request->week))
            ->orderByDesc('year')->orderByDesc('week_number')->orderBy('id')
            ->get();

        return response()->json($cupos->map(function ($c) {
            $embarcadas = (int) $c->lines->sum('t_embarcadas');
            $caidas = (int) $c->lines->sum('t_caidas');
            $rechazadas = (int) $c->lines->sum('t_rechazadas');
            $liq = $c->settlement;

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
                'a_pagar'     => $embarcadas + $caidas,
                'settlement'  => $liq ? [
                    'id'         => $liq->id,
                    'code'       => $liq->code,
                    'net_amount' => $liq->net_amount,
                    'pagado'     => $liq->payments->every(fn ($p) => $p->paid_at !== null),
                ] : null,
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
        $cupo = ProducerQuota::with('thirdParty:id,name,type')->findOrFail($quotaId);
        $calculo = $this->calcular($cupo);

        $anticipos = ProducerAdvance::where('third_party_id', $cupo->third_party_id)->where('status', true)
            ->orderBy('date')->orderBy('id')->get()
            ->map(fn ($a) => [
                'id'      => $a->id,
                'date'    => $a->date?->format('Y-m-d'),
                'type'    => $a->type,
                'concept' => $a->concept,
                'amount'  => $a->amount,
                'saldo'   => $a->saldo(),
            ])
            ->filter(fn ($a) => $a['saldo'] > 0)->values();

        return response()->json([
            'quota'     => [
                'id'          => $cupo->id,
                'third_party' => $cupo->thirdParty,
                'week'        => $cupo->week_number,
                'year'        => $cupo->year,
                'status'      => $cupo->status,
            ],
            'settlement_id' => $cupo->settlement?->id,
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
            $calculo = $this->calcular($cupo);
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
                $monto = round((float) $a['amount'], 2);
                if ($monto > $anticipo->saldo()) {
                    $this->error('EL ANTICIPO DEL ' . $anticipo->date->format('d/m/Y') . ' TIENE SALDO DE '
                        . number_format($anticipo->saldo(), 2, ',', '.') . '; NO SE PUEDE DESCONTAR ' . number_format($monto, 2, ',', '.') . '.');
                }
                $anticiposTotal += $monto;
                $aplicar[] = ['producer_advance_id' => $anticipo->id, 'amount' => $monto];
            }
            $anticiposTotal = round($anticiposTotal, 2);

            // Descuentos: materiales + transporte negociado + anticipos
            $fijos = round($calculo['materials_amount'] + $calculo['transport_amount'], 2);
            $pagos = $this->pagos($calculo['lines'], $fijos, $anticiposTotal);
            $neto = round($calculo['fruit_amount'] - $fijos - $anticiposTotal, 2);
            if ($neto < 0) {
                $this->error('LOS DESCUENTOS (' . number_format($fijos + $anticiposTotal, 2, ',', '.')
                    . ') SUPERAN EL VALOR DE LA FRUTA (' . number_format($calculo['fruit_amount'], 2, ',', '.') . '). DESCUENTA MENOS ANTICIPO.');
            }

            $liq = Settlement::create([
                'code'              => $this->siguienteCodigo(),
                'producer_quota_id' => $cupo->id,
                'third_party_id'    => $cupo->third_party_id,
                'week'              => $cupo->week_number,
                'year'              => $cupo->year,
                'date'              => $data['date'],
                'boxes_shipped'     => $calculo['boxes_shipped'],
                'boxes_fallen'      => $calculo['boxes_fallen'],
                'boxes_rejected'    => $calculo['boxes_rejected'],
                'boxes_paid'        => $calculo['boxes_paid'],
                'fruit_amount'      => $calculo['fruit_amount'],
                'materials_amount'  => $calculo['materials_amount'],
                'transport_amount'  => $calculo['transport_amount'],
                'invoice_amount'    => $calculo['invoice_amount'],
                'credit_note_amount' => $calculo['credit_note_amount'],
                'advances_amount'   => $anticiposTotal,
                'net_amount'        => $neto,
                'notes'             => isset($data['notes']) ? mb_strtoupper(trim($data['notes'])) : null,
                'status'            => true,
                'created_by'        => $request->user()?->id,
            ]);

            foreach ($calculo['lines'] as $l) {
                $liq->lines()->create(collect($l)->only([
                    'producer_quota_line_id', 'negotiation_type', 'payment_scheme', 'boxes_purchased',
                    'boxes_shipped', 'boxes_fallen', 'boxes_rejected', 'boxes_paid', 'real_price', 'amount',
                    'transport_deduction', 'official_price', 'invoice_amount', 'credit_note_amount',
                ])->all());
            }
            foreach ($calculo['materials'] as $m) {
                $liq->materials()->create(collect($m)->only([
                    'supply_id', 'dispatched_qty', 'returned_qty', 'charged_qty', 'unit_cost', 'amount',
                ])->all());
            }
            foreach ($aplicar as $a) {
                $liq->advances()->create($a);
            }
            foreach ($pagos as $p) {
                $liq->payments()->create($p);
            }

            $cupo->update(['status' => ProducerQuota::LIQUIDADO]);

            return $liq;
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
            if ($liq->payments()->whereNotNull('paid_at')->exists()) {
                $this->error('LA LIQUIDACIÓN TIENE PAGOS REGISTRADOS: DESHAZ LOS PAGOS ANTES DE ANULARLA.');
            }
            // Al quedar inactiva, sus anticipos vuelven a tener saldo (ProducerAdvance::saldo)
            $liq->update(['status' => false, 'cancel_reason' => mb_strtoupper(trim($data['reason']))]);

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
    private function calcular(ProducerQuota $cupo): array
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
            ->withCount('tallies')
            ->orderBy('id')->get();

        if ($compras->isEmpty()) {
            $problemas[] = 'ESTE CUPO NO TIENE COMPRAS DE COMERCIAL.';
        } elseif ($compras->sum('tallies_count') === 0) {
            $problemas[] = 'NO HAY TARJAS REGISTRADAS PARA ESTE PRODUCTOR EN ESTA SEMANA.';
        }

        $lineas = [];
        foreach ($compras as $c) {
            $embarcadas = (int) $c->t_embarcadas;
            $caidas = (int) $c->t_caidas;
            $pagadas = $embarcadas + $caidas;
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
                'boxes_paid'             => $pagadas,
                'real_price'             => $precio,
                'amount'                 => $fruta,
                'official_price'         => $oficial,
                'invoice_amount'         => $factura,
                'credit_note_amount'     => $oficial === null ? 0 : round($factura - $fruta, 2),
                // Transporte de contenedor negociado al comprar: se descuenta si la fruta llegó
                'transport_deduction'    => $pagadas > 0 ? round((float) $c->transport_deduction, 2) : 0,
                'producer'               => $c->producer?->name,
                'farm_name'              => $c->farm_name,
            ];
        }

        $materiales = $this->materiales($cupo->id);
        $lineasCol = collect($lineas);

        return [
            'problemas'        => $problemas,
            'lines'            => $lineas,
            'materials'        => $materiales,
            'boxes_shipped'    => $lineasCol->sum('boxes_shipped'),
            'boxes_fallen'     => $lineasCol->sum('boxes_fallen'),
            'boxes_rejected'   => $lineasCol->sum('boxes_rejected'),
            'boxes_paid'       => $lineasCol->sum('boxes_paid'),
            'fruit_amount'     => round($lineasCol->sum('amount'), 2),
            'materials_amount' => round(collect($materiales)->sum('amount'), 2),
            'transport_amount' => round($lineasCol->sum('transport_deduction'), 2),
            'invoice_amount'   => round($lineasCol->sum('invoice_amount'), 2),
            'credit_note_amount' => round($lineasCol->sum('credit_note_amount'), 2),
        ];
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
     * Pagos: 1er pago = compras "100" completas + 80 % de las "80_20" − descuentos;
     * 2do pago = 20 % de las "80_20". Si los descuentos no caben en el 1er pago, se
     * toma el resto del 2do. Solo se crean los pagos con monto mayor a cero.
     */
    private function pagos(array $lineas, float $descuentosFijos, float $anticipos): array
    {
        $col = collect($lineas);
        $completo = round($col->where('payment_scheme', '100')->sum('amount'), 2);
        $ochentaVeinte = round($col->where('payment_scheme', '80_20')->sum('amount'), 2);

        // $descuentosFijos = materiales + transporte negociado
        $segundo = round($ochentaVeinte * 0.20, 2);
        $primero = round($completo + $ochentaVeinte - $segundo - $descuentosFijos - $anticipos, 2);
        if ($primero < 0) {
            $segundo = round($segundo + $primero, 2);
            $primero = 0;
        }

        if ($ochentaVeinte == 0) {
            $nombre1 = 'PAGO ÚNICO (100 %)';
        } elseif ($completo == 0) {
            $nombre1 = 'PRIMER PAGO (80 %)';
        } else {
            $nombre1 = 'PRIMER PAGO (100 % + 80 %)';
        }

        return collect([
            ['installment' => 1, 'description' => $nombre1, 'amount' => $primero],
            ['installment' => 2, 'description' => 'SEGUNDO PAGO (20 %)', 'amount' => $segundo],
        ])->filter(fn ($p) => $p['amount'] > 0)->values()->all();
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
        ]);

        return $liq->toArray();
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
