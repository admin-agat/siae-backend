<?php

namespace App\Services;

use App\Models\ProducerAdvance;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// 19/11/2026 (fase 1b-1, docs/modulos/pagos-liquidaciones.md 13.A): anticipo 80 % de una compra SPOT 80/20.
//   - Lo pide Comercial con PAGAR (compra enviada a bodega). Una orden activa por compra (índice único).
//   - Monto = CAJAS COMPRADAS × precio pactado × 0,80, congelado en la orden. NO lee tarjas.
//   - Las filas que salen de dividir la compra (split_from_line_id) siguen en la orden de la original: el 80 %
//     cubre la compra completa, cada fila con su precio (la que ya tenía su propia orden no se suma).
//   - Cambios en Compra de fruta: POR APROBAR / DEVUELTA se recalcula; APROBADA se recalcula y vuelve a
//     POR APROBAR con aviso; EN BANCO / PAGADA no cambia (la diferencia se ajusta en la LQ).
class Anticipo80
{
    public const TIPO_AVISO = 'ANTICIPO_80';

    /** La orden 80 % activa de una compra (la pide con bloqueo si $bloquear). */
    public static function activa(int $lineaId, bool $bloquear = false): ?ProducerAdvance
    {
        return ProducerAdvance::where('origin', 'SPOT_80')->where('status', true)->where('producer_quota_line_id', $lineaId)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    /** Estado para la pantalla: POR APROBAR, DEVUELTA, APROBADA, EN BANCO, PAGADA, FALLIDA o ANULADA. */
    public static function estado(ProducerAdvance $a): string
    {
        return match (true) {
            !$a->status                          => 'ANULADA',
            $a->payment_status === 'PAGADA'      => 'PAGADA',
            $a->payment_status === 'EN_BANCO'    => 'EN BANCO',
            $a->payment_status === 'FALLIDA'     => 'FALLIDA',
            $a->approval_status === 'RECHAZADA'  => 'DEVUELTA',
            $a->approval_status === 'APROBADA'   => 'APROBADA',
            default                              => 'POR APROBAR',
        };
    }

    /** Ya salió al banco o se pagó: la orden no se toca (FALLIDA vuelve a por pagar, como una aprobada). */
    public static function enBanco(ProducerAdvance $a): bool
    {
        return in_array($a->payment_status, ['EN_BANCO', 'PAGADA'], true);
    }

    /** La compra (o la compra de la que salió al dividir) ya tiene 80 %: la orden de su origen. */
    public static function deOrigen(ProducerQuotaLine $linea): ?ProducerAdvance
    {
        $origen = $linea->split_from_line_id;
        $vistos = [];
        while ($origen && !isset($vistos[$origen])) {
            $vistos[$origen] = true;
            if ($orden = self::activa((int) $origen)) {
                return $orden;
            }
            $origen = ProducerQuotaLine::whereKey($origen)->value('split_from_line_id');
        }

        return null;
    }

    /** Botón PAGAR: crea la orden POR APROBAR (o vuelve a pedir una DEVUELTA). Llamar dentro de una transacción. */
    public static function crear(ProducerQuotaLine $linea, User $por): ProducerAdvance
    {
        $linea = ProducerQuotaLine::with('quota.thirdParty:id,name')->whereKey($linea->id)->lockForUpdate()->firstOrFail();
        $cupo = $linea->quota;
        $vendedor = $cupo?->thirdParty?->name ?? 'EL VENDEDOR';
        $orden = self::activa($linea->id, true);

        $error = match (true) {
            $linea->negotiation_type !== 'SPOT' || $linea->payment_scheme !== '80_20'
                => 'SOLO LAS COMPRAS SPOT CON PAGO 80/20 TIENEN ANTICIPO: LAS DE CONTRATO Y LAS SPOT AL 100 % SE PAGAN EN LA LIQUIDACIÓN.',
            !$linea->sent_to_warehouse_at
                => 'PRIMERO ENVÍA LA COMPRA A BODEGA: EL 80 % SE PIDE CUANDO EL VENDEDOR YA PUEDE RETIRAR EL MATERIAL.',
            !((float) $linea->real_price > 0) => 'FALTA EL PRECIO REAL DE LA COMPRA: SIN ÉL NO SE CALCULA EL 80 %.',
            !$linea->cut_date                 => 'FALTA EL DÍA DE CORTE DE LA COMPRA: EL 80 % SE PAGA EN ESA SEMANA.',
            (int) $linea->quantity_cajas <= 0 => 'LA COMPRA NO TIENE CAJAS.',
            self::liquidada($linea)           => "EL CUPO DE $vendedor WK{$cupo?->week_number} YA FUE LIQUIDADO: TODO SE PAGA EN LA LIQUIDACIÓN.",
            $orden && $orden->approval_status !== 'RECHAZADA'
                => "LA COMPRA YA TIENE ANTICIPO 80 % ({$orden->payment_order_code}): ESTÁ " . self::estado($orden) . '.',
            !$orden && ($deOrigen = self::deOrigen($linea)) !== null
                => "ESTA COMPRA SALIÓ DE DIVIDIR {$deOrigen->payment_order_code}: SU 80 % YA ESTÁ EN ESA ORDEN.",
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['anticipo80' => $error]);
        }

        $valores = self::calculo($linea) + [
            'third_party_id'    => $cupo->third_party_id,
            'producer_quota_id' => $cupo->id,
            'approval_status'   => 'POR_APROBAR',
            'approved_by'       => null,
            'approved_at'       => null,
            'rejection_reason'  => null,
        ];
        if ($orden) {
            // Devuelta por Liquidaciones: se vuelve a pedir la misma orden, recalculada
            $orden->update($valores + ['created_by' => $por->id]);
        } else {
            $orden = ProducerAdvance::create($valores + [
                'producer_quota_line_id' => $linea->id,
                'origin'             => 'SPOT_80',
                'type'               => 'ANTICIPO',
                'date'               => now(config('app.zona_operacion'))->toDateString(),
                'payment_order_code' => ProducerAdvance::siguienteCodigoOrden(),
                'concept'            => mb_substr("ANTICIPO 80 % WK{$cupo->week_number} {$linea->variety}", 0, 255),
                'status'             => true,
                'created_by'         => $por->id,
            ]);
        }
        self::avisarAprobadores($orden, $vendedor, 'ANTICIPO 80 % POR APROBAR');

        return $orden;
    }

    /** Comercial retira su pedido (solo POR APROBAR o DEVUELTA). */
    public static function retirar(ProducerQuotaLine $linea, string $motivo): ProducerAdvance
    {
        $orden = self::activa($linea->id, true);
        if (!$orden) {
            throw ValidationException::withMessages(['anticipo80' => 'LA COMPRA NO TIENE ANTICIPO 80 % PEDIDO.']);
        }
        if (!in_array($orden->approval_status, ['POR_APROBAR', 'RECHAZADA'], true) || $orden->payment_status) {
            throw ValidationException::withMessages(['anticipo80' => 'EL 80 % YA FUE APROBADO: PIDE A LIQUIDACIONES QUE LO DEVUELVA.']);
        }
        self::anular($orden, $motivo);

        return $orden;
    }

    public static function anular(ProducerAdvance $orden, string $motivo): void
    {
        $orden->update(['approval_status' => 'ANULADA', 'status' => false, 'rejection_reason' => mb_strtoupper(mb_substr(trim($motivo), 0, 500))]);
        self::avisosLeidos($orden);
    }

    /** La orden ya se resolvió (aprobada, devuelta o anulada): la campanita de los aprobadores deja de avisarla. */
    public static function avisosLeidos(ProducerAdvance $orden): void
    {
        UserNotification::where('type', self::TIPO_AVISO)->whereNull('read_at')->where('data->producer_advance_id', $orden->id)
            ->get()->each(fn ($a) => $a->update(['read_at' => now()])); // uno por uno: bitácora
    }

    /**
     * Antes de guardar la semana o quitar el envío: el error que impide el cambio (null = se puede).
     * $nueva = lo que llega para la compra (null = se quita la compra o el envío).
     */
    public static function errorCambio(ProducerQuotaLine $actual, ?array $nueva, ?int $semanaNueva = null): ?string
    {
        $orden = self::activa($actual->id);
        if (!$orden || in_array($orden->approval_status, ['POR_APROBAR', 'RECHAZADA'], true)) {
            return null; // sin orden, o todavía en manos de Comercial: se recalcula o se anula sola
        }
        $pagada = self::enBanco($orden);
        $codigo = $orden->payment_order_code;
        if ($nueva === null) {
            return $pagada
                ? 'EL 80 % DE ESTA COMPRA YA SE PAGÓ: LA COMPRA SE QUEDA PARA RECUPERARLO EN LA LIQUIDACIÓN.'
                : "LA COMPRA TIENE ANTICIPO 80 % ($codigo): NO SE PUEDE QUITAR. PIDE A LIQUIDACIONES QUE LO DEVUELVA.";
        }
        $negociacion = $nueva['negotiation_type'] !== $actual->negotiation_type
            || (string) $nueva['payment_scheme'] !== (string) $actual->payment_scheme
            || (int) $nueva['third_party_id'] !== (int) $actual->quota?->third_party_id
            || ($semanaNueva !== null && $semanaNueva !== (int) $actual->quota?->week_number);
        $corte = ($nueva['cut_date'] ?? null) !== $actual->cut_date?->format('Y-m-d');
        if (!$negociacion && !$corte) {
            return null;
        }
        $que = $negociacion ? 'LA NEGOCIACIÓN, EL VENDEDOR NI LA FORMA DE PAGO' : 'LA FECHA DE CORTE';

        return $pagada
            ? "EL 80 % DE ESTA COMPRA YA SE PAGÓ ($codigo): NO SE PUEDE CAMBIAR $que."
            : "LA COMPRA TIENE ANTICIPO 80 % APROBADO ($codigo): NO SE PUEDE CAMBIAR $que. PIDE A LIQUIDACIONES QUE LO DEVUELVA.";
    }

    /**
     * Después de guardar, dividir o quitar el envío: pone cada orden activa al día con su compra.
     * POR APROBAR / DEVUELTA → recalcula o se anula (ya no es SPOT 80/20, sin precio, sin cajas o sin envío);
     * APROBADA sin lote → recalcula y vuelve a POR APROBAR con aviso; EN BANCO / PAGADA → no cambia.
     */
    public static function sincronizar(iterable $lineaIds): void
    {
        $ordenes = ProducerAdvance::where('origin', 'SPOT_80')->where('status', true)
            ->whereIn('producer_quota_line_id', collect($lineaIds)->all())->lockForUpdate()->get();
        foreach ($ordenes as $orden) {
            if (self::enBanco($orden)) {
                continue;
            }
            $linea = ProducerQuotaLine::with('quota.thirdParty:id,name')->find($orden->producer_quota_line_id);
            $motivo = match (true) {
                !$linea                                 => 'SE QUITÓ LA COMPRA',
                !$linea->sent_to_warehouse_at           => 'SE QUITÓ EL ENVÍO A BODEGA',
                $linea->negotiation_type !== 'SPOT' || $linea->payment_scheme !== '80_20' => 'LA COMPRA YA NO ES SPOT 80/20',
                !((float) $linea->real_price > 0)       => 'LA COMPRA NO TIENE PRECIO REAL',
                !$linea->cut_date                       => 'LA COMPRA NO TIENE DÍA DE CORTE',
                (int) $linea->quantity_cajas <= 0       => 'LA COMPRA NO TIENE CAJAS',
                default                                 => null,
            };
            if ($motivo) {
                // errorCambio() lo impide con la orden APROBADA; si igual llega (dividir), la aprobada no se anula sola
                if (in_array($orden->approval_status, ['POR_APROBAR', 'RECHAZADA'], true)) {
                    self::anular($orden, $motivo);
                }
                continue;
            }
            $calculo = self::calculo($linea) + ['third_party_id' => $linea->quota->third_party_id, 'producer_quota_id' => $linea->producer_quota_id];
            // la base devuelve decimales como texto ("5600.00"): se comparan como número
            $cambio = collect($calculo)->contains(fn ($v, $k) => abs((float) $v - (float) $orden->getRawOriginal($k)) > 0.00001);
            if (!$cambio) {
                continue;
            }
            if ($orden->approval_status === 'APROBADA') {
                $orden->update($calculo + ['approval_status' => 'POR_APROBAR', 'approved_by' => null, 'approved_at' => null]);
                self::avisarAprobadores($orden, $linea->quota?->thirdParty?->name, 'ANTICIPO 80 % CAMBIÓ: VUELVE A POR APROBAR');
            } else {
                $orden->update($calculo);
            }
        }
    }

    /** Las órdenes activas que tocan estas compras (la suya o la de la compra de la que salieron al dividir). */
    public static function ordenesDe(iterable $lineas): array
    {
        $ids = [];
        foreach ($lineas as $l) {
            $ids[] = $l->id;
            if ($l->split_from_line_id && ($o = self::deOrigen($l))) {
                $ids[] = $o->producer_quota_line_id;
            }
        }

        return array_values(array_unique($ids));
    }

    // ---------------------------------------------------------------- ayudas

    /** Cajas, precio, monto y semana de pago de la compra + sus filas divididas que no tienen orden propia. */
    public static function calculo(ProducerQuotaLine $linea): array
    {
        $partes = self::partes($linea);
        $cajas = (int) $partes->sum('quantity_cajas');
        $valor = $partes->sum(fn ($p) => (int) $p->quantity_cajas * (float) $p->real_price);

        return [
            'boxes'          => $cajas,
            'unit_price'     => round($valor / max($cajas, 1), 4), // precio ponderado si las filas tienen precios distintos
            'amount'         => round($valor * 0.80, 2),
            'scheduled_week' => (int) $linea->cut_date->isoWeek(),
            'scheduled_year' => (int) $linea->cut_date->isoWeekYear(),
        ];
    }

    /** 19/11/2026: las filas que cubre la orden de esta compra: ella + las que salieron al dividirla sin orden propia. */
    public static function partes(ProducerQuotaLine $linea): \Illuminate\Support\Collection
    {
        $partes = collect([$linea]);
        $pendientes = [$linea->id];
        $vistos = [$linea->id => true];
        while ($pendientes) {
            $hijas = ProducerQuotaLine::whereIn('split_from_line_id', $pendientes)->get();
            $pendientes = [];
            foreach ($hijas as $h) {
                if (isset($vistos[$h->id])) {
                    continue;
                }
                $vistos[$h->id] = true;
                $pendientes[] = $h->id;
                if ($h->negotiation_type === 'SPOT' && $h->payment_scheme === '80_20' && (float) $h->real_price > 0 && !self::activa($h->id)) {
                    $partes->push($h);
                }
            }
        }

        return $partes;
    }

    /** Cupo LIQUIDADO, o la compra ya entró en una LQ (aunque esté anulada): todo se paga en la liquidación. */
    private static function liquidada(ProducerQuotaLine $linea): bool
    {
        return $linea->quota?->status === ProducerQuota::LIQUIDADO
            || DB::table('settlement_lines')->where('producer_quota_line_id', $linea->id)->exists();
    }

    // Campanita de quien aprueba (excepción aprobar_pagos_liquidacion); si no hay jefe, los ADMIN
    private static function avisarAprobadores(ProducerAdvance $orden, ?string $vendedor, string $titulo): void
    {
        $activos = User::where('status', true)->get();
        $destinatarios = $activos->filter(fn ($u) => !$u->esAdmin() && $u->puede('aprobar_pagos_liquidacion'));
        if ($destinatarios->isEmpty()) {
            $destinatarios = $activos->filter(fn ($u) => $u->esAdmin());
        }
        $cuerpo = implode(' · ', array_filter([
            $orden->payment_order_code, $vendedor, number_format((int) $orden->boxes, 0, ',', '.') . ' CAJAS',
            "SEMANA DE PAGO WK{$orden->scheduled_week}",
        ]));
        foreach ($destinatarios as $u) {
            UserNotification::create([ // uno por uno: queda en la bitácora
                'user_id'                => $u->id,
                'type'                   => self::TIPO_AVISO,
                'title'                  => $titulo,
                'body'                   => mb_substr($cuerpo, 0, 500),
                'link'                   => '/liquidacion/tablero-pagos',
                'data'                   => ['producer_advance_id' => $orden->id],
                'producer_quota_line_id' => $orden->producer_quota_line_id,
                'created_by'             => auth()->id(),
            ]);
        }
    }
}
