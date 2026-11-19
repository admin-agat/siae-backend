<?php

// TABLERO DE ANTICIPOS Y PAGOS (19/11/2026, fase 1b-1 de docs/modulos/pagos-liquidaciones.md 4.0 y 13.A)
// Ubicación: app/Http/Controllers/Api/SettlementPayableController.php
//
// El jefe de Liquidaciones aprueba o devuelve las órdenes de pago. En la 1b-1 solo hay ANTICIPO 80 % (los pide
// Comercial con PAGAR en Compra de fruta); las cuotas de LQ y los anticipos manuales llegan en la 1b-2.
//   - Totales y subtotales salen de las MISMAS filas filtradas (en el Excel sumaban filas ocultas).
//   - Nadie aprueba lo que él mismo pidió (salvo ADMIN). Se aprueba todo o nada: si una cambió, se dice cuál.
//   - La cuenta del vendedor (OK / PENDIENTE / FALTA) no la ve quien solo tiene tablero_pagos_lectura.
//
//   GET   /settlement-payables/board                 tablero con filtros
//   PATCH /settlement-payables/approve               aprobar en lote {items:[{tipo,id}]}
//   PATCH /settlement-payables/{tipo}/{id}/reject    devolver con motivo

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProducerAdvance;
use App\Models\ThirdPartyBankAccount;
use App\Models\UserNotification;
use App\Services\Anticipo80;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementPayableController extends Controller
{
    public const ESTADOS = ['POR_APROBAR', 'APROBADA', 'EN_BANCO', 'PAGADA', 'RECHAZADA', 'FALLIDA', 'TODAS'];

    public function board(Request $request)
    {
        $f = $request->validate([
            'anio'       => 'nullable|integer|min:2020|max:2100',
            'semana'     => 'nullable|integer|min:1|max:53',
            'negociacion' => 'nullable|in:SPOT,CONTRATO,MANUAL,TODAS',
            'dia'        => 'nullable|date',
            'estado'     => 'nullable|in:' . implode(',', self::ESTADOS),
            'orden'      => 'nullable|in:ANTICIPO_80,SALDO_SPOT,CONTRATO_100,MANUAL,TODAS',
            'vendedor'   => 'nullable|integer',
        ]);
        $usuario = $request->user();
        $verCuenta = $usuario->puede('aprobar_pagos_liquidacion', 'pagos');
        $puedeAprobar = $usuario->puede('aprobar_pagos_liquidacion');
        $negociacion = $f['negociacion'] ?? 'SPOT';
        $estado = $f['estado'] ?? 'POR_APROBAR';
        $orden = $f['orden'] ?? 'TODAS';

        // En la 1b-1 solo existen las órdenes ANTICIPO 80 % (SPOT)
        $hay80 = in_array($negociacion, ['SPOT', 'TODAS'], true) && in_array($orden, ['ANTICIPO_80', 'TODAS'], true);
        $ordenes = !$hay80 ? collect() : ProducerAdvance::with([
            'thirdParty:id,name', 'quotaLine', 'quota:id,week_number,year,customer_order_id', 'creator:id,name', 'approver:id,name',
        ])
            ->where('origin', 'SPOT_80')->where('status', true)
            ->when(isset($f['anio']) || isset($f['semana']), fn ($q) => $q->whereHas('quota', fn ($c) => $c
                ->when(isset($f['anio']), fn ($x) => $x->where('year', $f['anio']))
                ->when(isset($f['semana']), fn ($x) => $x->where('week_number', $f['semana']))))
            ->when(isset($f['dia']), fn ($q) => $q->whereHas('quotaLine', fn ($l) => $l->whereDate('cut_date', $f['dia'])))
            ->when(isset($f['vendedor']), fn ($q) => $q->where('third_party_id', $f['vendedor']))
            ->when($estado !== 'TODAS', fn ($q) => match ($estado) {
                'POR_APROBAR', 'RECHAZADA' => $q->where('approval_status', $estado),
                'APROBADA'                 => $q->where('approval_status', 'APROBADA')->whereNull('payment_status'),
                default                    => $q->where('payment_status', $estado),
            })
            ->orderBy('id')->get();

        $cuentas = $verCuenta
            ? ThirdPartyBankAccount::whereIn('third_party_id', $ordenes->pluck('third_party_id')->unique())->get()->keyBy('third_party_id')
            : collect();

        $filas = $ordenes->map(function (ProducerAdvance $o) use ($usuario, $puedeAprobar, $verCuenta, $cuentas) {
            $linea = $o->quotaLine;
            $ids = $linea ? Anticipo80::partes($linea)->pluck('id')->all() : [];
            $tarja = DB::table('tally_sheets')->whereIn('producer_quota_line_id', $ids)->where('status', true)
                ->selectRaw('COALESCE(SUM(boxes_shipped),0) as total, COALESCE(SUM(boxes_shipped - boxes_fallen - boxes_rejected - boxes_missing),0) as recibidas, COUNT(*) as camiones')
                ->first();
            $pagada = Anticipo80::enBanco($o);
            $avisos = [];
            if ($pagada && $linea && $linea->real_price !== null && $linea->cut_date
                && abs(Anticipo80::calculo($linea)['amount'] - (float) $o->amount) > 0.004) {
                $avisos[] = 'COMPRA CAMBIADA DESPUÉS DE PAGAR EL 80 %: LA DIFERENCIA SE AJUSTA EN LA LIQUIDACIÓN.';
            }
            if ((int) $tarja->camiones > 0 && (int) $tarja->recibidas < (int) $o->boxes) {
                $avisos[] = 'TARJA: RECIBIDAS (' . number_format((int) $tarja->recibidas, 0, ',', '.') . ') MENOR QUE LO COMPRADO ('
                    . number_format((int) $o->boxes, 0, ',', '.') . '): EL 80 % ' . ($pagada ? 'SE PAGÓ' : 'SE PAGARÍA') . ' DE MÁS.';
            }
            $cuenta = null;
            if ($verCuenta) {
                $c = $cuentas->get($o->third_party_id);
                $cuenta = !$c ? 'FALTA CUENTA' : ($c->verificada() ? 'OK' : 'PENDIENTE');
            }

            return [
                'tipo'          => 'anticipo',
                'id'            => $o->id,
                'code'          => $o->payment_order_code,
                'orden'         => 'ANTICIPO 80 %',
                'negociacion'   => 'SPOT',
                'dia_corte'     => $linea?->cut_date?->format('Y-m-d'),
                'semana_compra' => $o->quota?->week_number,
                'anio_compra'   => $o->quota?->year,
                'customer_order_id' => $o->quota?->customer_order_id,
                'vendedor'      => $o->thirdParty,
                'cajas'         => (int) $o->boxes,          // compradas (base del 80 %)
                'tarja_total'   => (int) $tarja->total,
                'recibidas'     => (int) $tarja->recibidas,
                'precio'        => (float) $o->unit_price,
                'monto'         => (float) $o->amount,
                'semana_pago'   => $o->scheduled_week,
                'anio_pago'     => $o->scheduled_year,
                'cuenta'        => $cuenta,
                'estado'        => Anticipo80::estado($o),
                'motivo'        => $o->rejection_reason,
                'pedido_por'    => $o->creator?->name,
                'aprobado_por'  => $o->approver?->name,
                'aprobado_en'   => $o->approved_at?->toIso8601String(),
                'avisos'        => $avisos,
                'puede_aprobar' => $puedeAprobar && $o->approval_status === 'POR_APROBAR'
                    && ($usuario->esAdmin() || (int) $o->created_by !== (int) $usuario->id),
                'puede_devolver' => $puedeAprobar && $this->devolvible($o),
            ];
        });

        // Grupos por DÍA DE CORTE (sin fecha al final); precio = ponderado por cajas
        $resumen = fn ($fs) => [
            'ordenes' => $fs->count(),
            'cajas'   => (int) $fs->sum('cajas'),
            'monto'   => round($fs->sum('monto'), 2),
            'precio'  => $fs->sum('cajas') > 0 ? round($fs->sum(fn ($x) => $x['cajas'] * $x['precio']) / $fs->sum('cajas'), 4) : 0,
        ];
        $grupos = $filas->groupBy(fn ($x) => $x['dia_corte'] ?? '')
            ->sortBy(fn ($fs, $dia) => $dia === '' ? '9999-99-99' : $dia)
            ->map(fn ($fs, $dia) => ['dia_corte' => $dia === '' ? null : $dia] + $resumen($fs))
            ->values();

        return response()->json([
            'filas'         => $filas->sortBy(fn ($x) => [$x['dia_corte'] ?? '9999-99-99', $x['vendedor']?->name ?? '', $x['id']])->values(),
            'grupos'        => $grupos,
            'totales'       => $resumen($filas),
            'ver_cuenta'    => $verCuenta,
            'puede_aprobar' => $puedeAprobar,
        ]);
    }

    public function approve(Request $request)
    {
        $data = $request->validate([
            'items'        => 'required|array|min:1',
            'items.*.tipo' => 'required|in:anticipo',
            'items.*.id'   => 'required|integer|distinct',
        ], ['items.required' => 'MARCA AL MENOS UNA ORDEN PARA APROBAR.']);
        $usuario = $request->user();

        $n = DB::transaction(function () use ($data, $usuario) {
            $ordenes = ProducerAdvance::whereIn('id', collect($data['items'])->pluck('id'))->where('origin', 'SPOT_80')
                ->lockForUpdate()->get()->keyBy('id');
            $errores = [];
            foreach ($data['items'] as $i) {
                $o = $ordenes->get($i['id']);
                if (!$o) {
                    $errores[] = "LA ORDEN #{$i['id']} NO EXISTE.";
                } elseif (!$o->status || $o->approval_status !== 'POR_APROBAR') {
                    $errores[] = "{$o->payment_order_code}: YA NO ESTÁ POR APROBAR (ESTÁ " . Anticipo80::estado($o) . ').';
                } elseif (!$usuario->esAdmin() && (int) $o->created_by === (int) $usuario->id) {
                    $errores[] = "{$o->payment_order_code}: NO PUEDES APROBAR UNA ORDEN QUE TÚ PEDISTE.";
                }
            }
            if ($errores) {
                abort(response()->json(['message' => implode(' ', $errores), 'errors' => ['items' => $errores]], 422));
            }
            foreach ($ordenes as $o) { // uno por uno: bitácora
                $o->update(['approval_status' => 'APROBADA', 'approved_by' => $usuario->id, 'approved_at' => now()]);
                Anticipo80::avisosLeidos($o);
            }

            return $ordenes->count();
        });

        return response()->json(['aprobadas' => $n]);
    }

    public function reject(Request $request, string $tipo, $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ], ['reason.required' => 'ESCRIBE EL MOTIVO DEL RECHAZO.']);

        $orden = DB::transaction(function () use ($id, $data, $request) {
            $o = ProducerAdvance::with(['thirdParty:id,name', 'quota:id,week_number,customer_order_id'])
                ->where('origin', 'SPOT_80')->lockForUpdate()->findOrFail($id);
            if (!$this->devolvible($o)) {
                abort(response()->json(['message' => Anticipo80::enBanco($o)
                    ? "EL 80 % ({$o->payment_order_code}) YA SALIÓ AL BANCO: NO SE PUEDE DEVOLVER."
                    : "{$o->payment_order_code}: NO SE PUEDE DEVOLVER (ESTÁ " . Anticipo80::estado($o) . ').'], 422));
            }
            $motivo = mb_strtoupper(trim($data['reason']));
            $o->update(['approval_status' => 'RECHAZADA', 'rejection_reason' => $motivo, 'approved_by' => null,
                'approved_at' => null, 'payment_status' => null]);
            Anticipo80::avisosLeidos($o);
            // Vuelve a Comercial: aviso en la campanita de quien lo pidió
            if ($o->created_by) {
                UserNotification::create([
                    'user_id'                => $o->created_by,
                    'type'                   => Anticipo80::TIPO_AVISO,
                    'title'                  => 'ANTICIPO 80 % DEVUELTO',
                    'body'                   => mb_substr(implode(' · ', array_filter([$o->payment_order_code, $o->thirdParty?->name, $motivo])), 0, 500),
                    'link'                   => $o->quota?->customer_order_id ? "/comercial/compras/{$o->quota->customer_order_id}" : null,
                    'data'                   => ['producer_advance_id' => $o->id, 'devuelto' => true],
                    'producer_quota_line_id' => $o->producer_quota_line_id,
                    'created_by'             => $request->user()?->id,
                ]);
            }

            return $o;
        });

        return response()->json(['id' => $orden->id, 'estado' => Anticipo80::estado($orden)]);
    }

    // Se devuelve mientras no salga al banco: POR APROBAR, APROBADA sin lote o FALLIDA
    private function devolvible(ProducerAdvance $o): bool
    {
        return $o->status && !Anticipo80::enBanco($o) && in_array($o->approval_status, ['POR_APROBAR', 'APROBADA'], true);
    }
}
