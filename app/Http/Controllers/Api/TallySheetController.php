<?php

// TARJAS — lo que llega a puerto (29/10/2026)
// Ubicación: app/Http/Controllers/Api/TallySheetController.php
//
// Cada tarja es un camión de una compra de la semana (productor + cliente + marca):
// cajas EMBARCADAS, CAÍDAS y RECHAZADAS. La liquidación paga embarcadas + caídas.
// 17/11/2026: + FALTANTES. EMBARCADAS es el total; RECIBIDAS = embarcadas − caídas − rechazadas − faltantes
// (lo que se paga). Caídas + rechazadas + faltantes no pueden pasar las embarcadas.
//
//   GET   /tallies/weeks                → semanas con compras (solo cajas, sin precios) (17/11/2026)
//   GET   /customer-orders/{id}/tallies → compras de la semana, barcos y tarjas activas
//   PUT   /customer-orders/{id}/tallies → guarda las tarjas nuevas o cambiadas de la semana
//   POST  /tallies, PUT /tallies/{id}   → un camión (celular: "Guardar camión")
//   PATCH /tallies/{id}/deactivate|reactivate → quitar con motivo / volver a poner
//   GET   /tallies/history?producer_quota_line_id=, GET /tallies/{id}/history → historial sin montos
// Las tarjas de un cupo ya LIQUIDADO no se cambian ni se quitan.
// 17/11/2026: la pantalla la usa el TARJADOR del puerto: nada de precios ni montos en estas respuestas.
// 17/11/2026 (fase 1a): las tarjas ya no se borran (baja lógica con motivo) y el guardado de la semana ya no
// quita lo que no viene: antes, si el celular agregaba un camión mientras la laptop tenía la semana abierta,
// al guardar la laptop se borraba. Cada tarja viaja con su updated_at: si otra persona la cambió, 409.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomerOrder;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\Shipment;
use App\Models\TallySheet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TallySheetController extends Controller
{
    private const CAMPOS = ['producer_quota_line_id', 'shipment_id', 'date', 'number', 'truck_plate',
        'boxes_shipped', 'boxes_fallen', 'boxes_rejected', 'boxes_missing', 'notes', 'container_number'];

    // 17/11/2026: reglas de una tarja (las usan la semana y el camión suelto)
    private const REGLAS = [
        'id'                     => 'nullable|integer',
        'producer_quota_line_id' => 'required|integer',
        'shipment_id'            => 'nullable|integer',
        'date'                   => 'required|date',
        'number'                 => 'nullable|string|max:30',
        'truck_plate'            => 'nullable|string|max:15',
        'boxes_shipped'          => 'required|integer|min:0',
        'boxes_fallen'           => 'required|integer|min:0',
        'boxes_rejected'         => 'required|integer|min:0',
        'boxes_missing'          => 'nullable|integer|min:0', // 17/11/2026: FALTANTES
        'notes'                  => 'nullable|string|max:255',
        'container_number'       => 'nullable|string|max:15',
        'updated_at'             => 'nullable|string',         // 17/11/2026: para saber si otra persona la cambió
    ];

    // 17/11/2026: mensajes en español para las reglas que no tienen uno propio (QA: salían en inglés)
    private const MENSAJES = [
        'required' => 'FALTA :attribute.',
        'integer'  => ':attribute DEBE SER UN NÚMERO ENTERO.',
        'min'      => ':attribute NO PUEDE SER NEGATIVO.',
        'max'      => ':attribute ES MUY LARGO (MÁXIMO :max CARACTERES).',
        'date'     => ':attribute NO ES UNA FECHA VÁLIDA.',
        'string'   => ':attribute DEBE SER TEXTO.',
        'array'    => ':attribute NO TIENE EL FORMATO CORRECTO.',
        'uuid'     => 'LA CLAVE DEL CELULAR NO ES VÁLIDA: VUELVE A ABRIR EL CAMIÓN.',
    ];
    private const NOMBRES = [
        'producer_quota_line_id' => 'LA COMPRA', 'shipment_id' => 'EL BARCO', 'date' => 'LA FECHA', 'number' => 'EL N° DE TARJA',
        'truck_plate' => 'LA PLACA', 'boxes_shipped' => 'LAS CAJAS EMBARCADAS', 'boxes_fallen' => 'LAS CAJAS CAÍDAS',
        'boxes_rejected' => 'LAS CAJAS RECHAZADAS', 'boxes_missing' => 'LAS CAJAS FALTANTES', 'notes' => 'LAS NOTAS',
        'container_number' => 'EL CONTENEDOR', 'reason' => 'EL MOTIVO', 'id' => 'LA TARJA', 'updated_at' => 'LA VERSIÓN DE LA TARJA',
    ];

    // Nombres de los campos para los mensajes (con prefijo en la semana: 'tallies.*.')
    private function nombres(string $prefijo = ''): array
    {
        return collect(self::NOMBRES)->mapWithKeys(fn ($v, $k) => [$prefijo . $k => $v])->all();
    }

    // 17/11/2026: campos que muestra el historial (lista blanca; nada de IP, ruta ni dinero)
    private const CAMPOS_HISTORIAL = [
        'date' => 'FECHA', 'number' => 'N° TARJA', 'truck_plate' => 'PLACA', 'container_number' => 'CONTENEDOR',
        'shipment_id' => 'BARCO', 'producer_quota_line_id' => 'COMPRA', 'boxes_shipped' => 'EMBARCADAS',
        'boxes_fallen' => 'CAÍDAS', 'boxes_rejected' => 'RECHAZADAS', 'boxes_missing' => 'FALTANTES',
        'notes' => 'NOTAS', 'status' => 'ESTADO', 'deactivation_reason' => 'MOTIVO',
    ];

    // 17/11/2026: listado de semanas para Tarjas (antes usaba GET /customer-orders, que es de lectura general)
    public function weeks()
    {
        return response()->json(CustomerOrder::where('status', true)
            ->select('id', 'week', 'year')
            ->withSum('lines as total_boxes', 'boxes')
            ->addSelect(['purchased_boxes' => DB::table('producer_quota_lines as ql')
                ->join('producer_quotas as q', 'q.id', '=', 'ql.producer_quota_id')
                ->whereColumn('q.customer_order_id', 'customer_orders.id')
                ->whereNotNull('ql.customer_id')
                ->selectRaw('COALESCE(SUM(ql.quantity_cajas), 0)')])
            ->orderByDesc('year')->orderByDesc('week')->get());
    }

    public function show($orderId)
    {
        return response()->json($this->cargar(CustomerOrder::findOrFail($orderId)));
    }

    // PUT /customer-orders/{id}/tallies → { tallies: [nuevas o cambiadas, con updated_at], removed: [{id, reason}] }
    // 17/11/2026: ya no borra las que no vienen; se quitan solo las de "removed", con su motivo.
    public function update(Request $request, $orderId)
    {
        $pedido = CustomerOrder::findOrFail($orderId);

        $reglas = ['tallies' => 'present|array', 'removed' => 'sometimes|array',
            'removed.*.id' => 'required|integer', 'removed.*.reason' => 'required|string|max:255'];
        foreach (self::REGLAS as $campo => $regla) {
            $reglas["tallies.*.$campo"] = $regla;
        }
        $data = $request->validate($reglas, [
            'tallies.*.producer_quota_line_id.required' => 'CADA TARJA DEBE TENER LA COMPRA (PRODUCTOR Y MARCA).',
            'tallies.*.date.required'                   => 'CADA TARJA DEBE TENER FECHA.',
            'removed.*.reason.required'                 => 'ESCRIBE EL MOTIVO PARA QUITAR LA TARJA.',
        ] + self::MENSAJES, $this->nombres('tallies.*.') + $this->nombres('removed.*.'));

        $compras = $this->comprasDelPedido($pedido->id)->keyBy('id');
        $barcos = Shipment::where('customer_order_id', $pedido->id)->where('status', true)->pluck('id')->all();
        $actuales = TallySheet::whereIn('producer_quota_line_id', $compras->keys())->get()->keyBy('id');

        $errores = [];
        $conflictos = [];
        foreach ($data['tallies'] as $i => $t) {
            $actual = !empty($t['id']) ? $actuales->get($t['id']) : null;
            if (!empty($t['id']) && !$actual) {
                $errores["tallies.$i"] = 'TARJA ' . ($i + 1) . ': NO EXISTE O NO ES DE ESTA SEMANA.';
                continue;
            }
            if ($actual && ($c = $this->conflicto($actual, $t))) {
                $conflictos["tallies.$i"] = $c;
                continue;
            }
            if ($e = $this->revisar($t, $compras->get($t['producer_quota_line_id']), $barcos, $actual, 'TARJA ' . ($i + 1))) {
                $errores["tallies.$i"] = $e;
            }
        }

        $quitar = [];
        foreach ($data['removed'] ?? [] as $i => $r) {
            $tarja = $actuales->get($r['id']);
            if (!$tarja) {
                $errores["removed.$i"] = "LA TARJA #{$r['id']} NO ES DE ESTA SEMANA.";
            } elseif ($tarja->status) { // si otra persona ya la quitó, no se repite
                if ($e = $this->bloqueoLiquidado($tarja, 'QUITAR')) {
                    $errores["removed.$i"] = $e;
                } else {
                    $quitar[] = [$tarja, $r['reason']];
                }
            }
        }

        if ($conflictos) {
            return response()->json(['message' => reset($conflictos), 'errors' => array_map(fn ($c) => [$c], $conflictos)], 409);
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        DB::transaction(function () use ($data, $actuales, $quitar, $request) {
            foreach ($data['tallies'] as $t) {
                $actual = !empty($t['id']) ? $actuales->get($t['id']) : null;
                if ($actual) {
                    $actual->update($this->valores($t));
                } else {
                    TallySheet::create($this->valores($t) + ['created_by' => $request->user()?->id]);
                }
            }
            foreach ($quitar as [$tarja, $motivo]) { // uno por uno: queda en la bitácora
                $tarja->update(['status' => false, 'deactivation_reason' => mb_strtoupper(trim($motivo))]);
            }
        });

        return response()->json($this->cargar($pedido));
    }

    // POST /tallies → un camión nuevo. Con client_key, un reenvío (sin señal) devuelve el mismo camión.
    public function storeOne(Request $request)
    {
        $t = $request->validate(self::REGLAS + ['client_key' => 'nullable|uuid'], [
            'producer_quota_line_id.required' => 'ELIGE EL PRODUCTOR Y LA MARCA.',
            'date.required'                   => 'FALTA LA FECHA.',
        ] + self::MENSAJES, $this->nombres());
        if (!empty($t['client_key']) && ($ya = TallySheet::where('client_key', $t['client_key'])->first())) {
            return response()->json($ya);
        }
        [$compra, $barcos] = $this->compraYBarcos((int) $t['producer_quota_line_id']);
        if ($e = $this->revisar($t, $compra, $barcos, null, 'EL CAMIÓN')) {
            throw ValidationException::withMessages(['tally' => $e]);
        }
        try {
            $tarja = TallySheet::create($this->valores($t) + ['client_key' => $t['client_key'] ?? null, 'created_by' => $request->user()?->id]);
        } catch (UniqueConstraintViolationException $e) {
            // 17/11/2026: dos reenvíos al mismo tiempo con la misma clave: gana el primero y se devuelve ese
            return response()->json(TallySheet::where('client_key', $t['client_key'])->firstOrFail());
        }

        return response()->json($tarja->fresh(), 201);
    }

    // PUT /tallies/{id} → cambia un camión (con updated_at: si otra persona lo cambió, 409)
    public function updateOne(Request $request, $id)
    {
        $tarja = TallySheet::findOrFail($id);
        $t = $request->validate(self::REGLAS, ['date.required' => 'FALTA LA FECHA.'] + self::MENSAJES, $this->nombres());
        if ($c = $this->conflicto($tarja, $t)) {
            return response()->json(['message' => $c], 409);
        }
        [$compra, $barcos] = $this->compraYBarcos((int) $t['producer_quota_line_id']);
        if ($compra && $tarja->purchase?->block?->customer_order_id !== $compra->block?->customer_order_id) {
            throw ValidationException::withMessages(['tally' => 'EL CAMIÓN: LA COMPRA NO ES DE ESTA SEMANA.']);
        }
        if ($e = $this->revisar($t, $compra, $barcos, $tarja, 'EL CAMIÓN')) {
            throw ValidationException::withMessages(['tally' => $e]);
        }
        $tarja->update($this->valores($t));

        return response()->json($tarja->fresh());
    }

    // PATCH /tallies/{id}/deactivate → quitar con motivo (no se borra: queda en el historial)
    public function deactivate(Request $request, $id)
    {
        $tarja = TallySheet::findOrFail($id);
        $data = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'ESCRIBE EL MOTIVO PARA QUITAR LA TARJA.'] + self::MENSAJES, $this->nombres());
        if ($e = $this->bloqueoLiquidado($tarja, 'QUITAR')) {
            throw ValidationException::withMessages(['reason' => $e]);
        }
        if ($tarja->status) {
            $tarja->update(['status' => false, 'deactivation_reason' => mb_strtoupper(trim($data['reason']))]);
        }

        return response()->json($tarja->fresh());
    }

    // PATCH /tallies/{id}/reactivate → vuelve a contar
    public function reactivate($id)
    {
        $tarja = TallySheet::findOrFail($id);
        if ($e = $this->bloqueoLiquidado($tarja, 'VOLVER A PONER')) {
            throw ValidationException::withMessages(['tally' => $e]);
        }
        if (!$tarja->status) {
            $tarja->update(['status' => true, 'deactivation_reason' => null]);
        }

        return response()->json($tarja->fresh());
    }

    // GET /tallies/history?producer_quota_line_id= → todas las tarjas de una compra (también las quitadas)
    public function history(Request $request)
    {
        $data = $request->validate(['producer_quota_line_id' => 'required|integer'], [
            'producer_quota_line_id.required' => 'ELIGE LA COMPRA.',
        ] + self::MENSAJES, $this->nombres());
        $compra = ProducerQuotaLine::findOrFail($data['producer_quota_line_id']);
        $ids = TallySheet::where('producer_quota_line_id', $compra->id)->pluck('id')->all();

        return response()->json($this->historial($ids, $compra, true));
    }

    // GET /tallies/{id}/history → una tarja
    public function historyOne($id)
    {
        $tarja = TallySheet::findOrFail($id);

        return response()->json($this->historial([$tarja->id], $tarja->purchase, false));
    }

    // ---------------------------------------------------------------- reglas

    // Mensaje si la tarja no se puede guardar; null si está bien. $n = "TARJA 3" o "EL CAMIÓN".
    private function revisar(array $t, ?ProducerQuotaLine $compra, array $barcos, ?TallySheet $actual, string $n): ?string
    {
        if (!$compra) {
            return "$n: LA COMPRA NO ES DE ESTA SEMANA.";
        }
        if (!empty($t['shipment_id']) && !in_array((int) $t['shipment_id'], $barcos)) {
            return "$n: EL BARCO NO ES DE ESTA SEMANA.";
        }
        // 17/11/2026: EMBARCADAS es el total; caídas, rechazadas y faltantes son parte de ese total
        $faltantes = (int) ($t['boxes_missing'] ?? 0);
        if ((int) $t['boxes_shipped'] === 0) {
            return "$n: ESCRIBE LAS CAJAS EMBARCADAS.";
        }
        if ($t['boxes_fallen'] + $t['boxes_rejected'] + $faltantes > $t['boxes_shipped']) {
            return "$n: LAS CAÍDAS, RECHAZADAS Y FALTANTES NO PUEDEN SUMAR MÁS QUE LAS EMBARCADAS.";
        }
        if ($actual && !$actual->status) {
            return "$n: ESTÁ QUITADA. VUELVE A PONERLA ANTES DE CAMBIARLA.";
        }
        // Cupo liquidado: no se agregan tarjas ni se cambian las que tiene
        $liquidada = $compra->quota->status === ProducerQuota::LIQUIDADO
            || ($actual && $actual->purchase->quota->status === ProducerQuota::LIQUIDADO);
        if ($liquidada && (!$actual || $this->cambio($actual, $t))) {
            return "$n: {$compra->quota->thirdParty?->name} YA FUE LIQUIDADO EN ESTA SEMANA; SUS TARJAS NO SE PUEDEN CAMBIAR.";
        }

        return null;
    }

    // 17/11/2026: ¿otra persona cambió o quitó la tarja después de que se cargó la pantalla?
    private function conflicto(TallySheet $actual, array $t): ?string
    {
        $enviada = !empty($t['updated_at']) ? Carbon::parse($t['updated_at']) : null;
        if ($enviada && $actual->updated_at && $enviada->equalTo($actual->updated_at) && $actual->status) {
            return null;
        }
        $tarja = $actual->number ?: "#{$actual->id}";
        if (!$enviada) {
            return "LA TARJA $tarja NO TRAE SU VERSIÓN: RECARGA LA PÁGINA PARA VER LO ÚLTIMO.";
        }
        $ultimo = AuditLog::where('table_name', 'tally_sheets')->where('record_id', $actual->id)->latest('id')->first();
        $quien = $ultimo ? "{$ultimo->user_name} A LAS " . $ultimo->created_at->timezone(config('app.timezone'))->format('H:i') : 'OTRA PERSONA';

        return $actual->status
            ? "LA TARJA $tarja LA CAMBIÓ $quien. RECARGA LA PÁGINA PARA VER LO ÚLTIMO."
            : "LA TARJA $tarja LA QUITÓ $quien. RECARGA LA PÁGINA PARA VER LO ÚLTIMO.";
    }

    private function bloqueoLiquidado(TallySheet $tarja, string $accion): ?string
    {
        $cupo = $tarja->purchase->quota;

        return $cupo->status === ProducerQuota::LIQUIDADO
            ? "NO SE PUEDE $accion LA TARJA " . ($tarja->number ?: "#{$tarja->id}") . " DE {$cupo->thirdParty?->name}: YA FUE LIQUIDADO."
            : null;
    }

    // ¿La tarja enviada es distinta a la guardada?
    private function cambio(TallySheet $actual, array $t): bool
    {
        foreach (['producer_quota_line_id', 'shipment_id', 'boxes_shipped', 'boxes_fallen', 'boxes_rejected', 'boxes_missing'] as $c) {
            if ((int) ($t[$c] ?? 0) !== (int) $actual->$c) {
                return true;
            }
        }
        return $actual->date->format('Y-m-d') !== substr($t['date'], 0, 10);
    }

    // Valores a guardar, en MAYÚSCULAS
    private function valores(array $t): array
    {
        $valores = collect($t)->only(self::CAMPOS)->all();
        $valores['shipment_id'] = $valores['shipment_id'] ?? null;
        $valores['boxes_missing'] = (int) ($valores['boxes_missing'] ?? 0);
        foreach (['number', 'truck_plate', 'container_number', 'notes'] as $campo) {
            $valores[$campo] = isset($valores[$campo]) && trim($valores[$campo]) !== '' ? mb_strtoupper(trim($valores[$campo])) : null;
        }

        return $valores;
    }

    // Compra de un camión suelto y los barcos de su semana (la semana sale del bloque de la compra)
    private function compraYBarcos(int $compraId): array
    {
        $compra = ProducerQuotaLine::with(['quota.thirdParty:id,name,type', 'block:id,shipment_id,customer_order_id'])
            ->whereNotNull('customer_id')->whereHas('block')->find($compraId);
        $barcos = $compra
            ? Shipment::where('customer_order_id', $compra->block->customer_order_id)->where('status', true)->pluck('id')->all()
            : [];

        return [$compra, $barcos];
    }

    // ---------------------------------------------------------------- historial

    // 17/11/2026: historial de tarjas desde audit_logs (solo la tabla tally_sheets, campos de la lista blanca)
    // más los eventos de la liquidación sin montos. Para compra = true también trae las tarjas borradas antes
    // del 17/11 (solo existen en la bitácora como BORRAR).
    private function historial(array $ids, ProducerQuotaLine $compra, bool $deLaCompra): array
    {
        $logs = AuditLog::where('table_name', 'tally_sheets')
            ->where(function ($q) use ($ids, $compra, $deLaCompra) {
                $q->whereIn('record_id', $ids ?: [0]);
                if ($deLaCompra) {
                    $q->orWhere(fn ($b) => $b->where('action', 'BORRAR')->whereRaw("changes->>'producer_quota_line_id' = ?", [(string) $compra->id]));
                }
            })
            ->orderBy('id')->get();

        // Nombres para traducir los ids de barco y compra
        $barcoIds = $logs->flatMap(fn ($l) => collect($l->changes ?? [])->only('shipment_id')->flatten())->filter()->unique();
        $barcos = Shipment::with('vessel:id,name')->whereIn('id', $barcoIds)->get(['id', 'vessel_id', 'vessel_name', 'code', 'voyage'])
            ->mapWithKeys(fn ($b) => [$b->id => trim(($b->vessel?->name ?: $b->vessel_name ?: $b->code) . ($b->voyage ? " · {$b->voyage}" : ''))]);
        $compraIds = $logs->flatMap(fn ($l) => collect($l->changes ?? [])->only('producer_quota_line_id')->flatten())->push($compra->id)->filter()->unique();
        $compras = ProducerQuotaLine::with(['quota.thirdParty:id,name', 'brand:id,name', 'customer.thirdParty:id,name'])
            ->whereIn('id', $compraIds)->get()->mapWithKeys(fn ($l) => [$l->id => $this->nombreCompra($l)]);

        $valor = function (string $campo, $v) use ($barcos, $compras) {
            if ($v === null || $v === '') {
                return '—';
            }
            return match ($campo) {
                'shipment_id'            => $barcos[$v] ?? "#$v",
                'producer_quota_line_id' => $compras[$v] ?? "#$v",
                'status'                 => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'ACTIVA' : 'QUITADA',
                'date'                   => Carbon::parse($v)->format('d/m/Y'),
                default                  => is_scalar($v) ? (string) $v : json_encode($v),
            };
        };

        $numeros = TallySheet::whereIn('id', $logs->pluck('record_id')->unique())->get(['id', 'number'])->keyBy('id');
        $eventos = $logs->map(function ($l) use ($valor, $numeros) {
            $cambios = $l->changes ?? [];
            $tipo = match ($l->action) {
                'CREAR'      => 'CREADA',
                'BORRAR'     => 'BORRADA',
                'DESACTIVAR' => 'QUITADA',
                'REACTIVAR'  => 'VUELTA A PONER',
                default      => array_key_exists('status', $cambios)
                    ? (filter_var($cambios['status'][1] ?? null, FILTER_VALIDATE_BOOLEAN) ? 'VUELTA A PONER' : 'QUITADA')
                    : 'EDITADA',
            };
            $detalle = [];
            foreach (self::CAMPOS_HISTORIAL as $campo => $nombre) {
                if (!array_key_exists($campo, $cambios) || $campo === 'status') {
                    continue;
                }
                $detalle[] = in_array($l->action, ['CREAR', 'BORRAR'], true)
                    ? ['campo' => $nombre, 'valor' => $valor($campo, $cambios[$campo])]
                    : ['campo' => $nombre, 'antes' => $valor($campo, $cambios[$campo][0] ?? null), 'despues' => $valor($campo, $cambios[$campo][1] ?? null)];
            }
            $numero = $numeros[$l->record_id]->number ?? ($l->action === 'BORRAR' ? ($cambios['number'] ?? null) : null);

            return [
                'fecha'   => $l->created_at->toIso8601String(),
                'usuario' => $l->user_name,
                'tipo'    => $tipo,
                'tarja'   => $numero ?: "#{$l->record_id}",
                'detalle' => $detalle,
            ];
        });

        // Liquidación de la compra (sin montos ni tipo de negociación)
        $liquidaciones = Settlement::with('creator:id,name')
            ->whereIn('id', SettlementLine::where('producer_quota_line_id', $compra->id)->pluck('settlement_id'))->get();
        foreach ($liquidaciones as $s) {
            $eventos->push(['fecha' => $s->created_at->toIso8601String(), 'usuario' => $s->creator?->name ?? 'SISTEMA',
                'tipo' => 'LIQUIDADA', 'tarja' => null, 'detalle' => [['campo' => 'LIQUIDACIÓN', 'valor' => $s->code]]]);
            if (!$s->status) {
                $anulo = AuditLog::where('table_name', 'settlements')->where('record_id', $s->id)
                    ->whereIn('action', ['EDITAR', 'DESACTIVAR'])->whereRaw("jsonb_exists(changes, 'status')")->latest('id')->first();
                $eventos->push(['fecha' => ($anulo?->created_at ?? $s->updated_at)->toIso8601String(), 'usuario' => $anulo?->user_name ?? '—',
                    'tipo' => 'LIQUIDACIÓN ANULADA', 'tarja' => null,
                    'detalle' => [['campo' => 'LIQUIDACIÓN', 'valor' => $s->code], ['campo' => 'MOTIVO', 'valor' => $s->cancel_reason ?: '—']]]);
            }
        }

        return [
            'compra'  => $compras[$compra->id] ?? "#{$compra->id}",
            'eventos' => $eventos->sortBy('fecha')->values(),
        ];
    }

    private function nombreCompra(ProducerQuotaLine $l): string
    {
        return "{$l->quota?->thirdParty?->name} — {$l->brand?->name}" . ($l->customer?->thirdParty?->name ? " ({$l->customer->thirdParty->name})" : '');
    }

    // ---------------------------------------------------------------- carga

    private function comprasDelPedido(int $pedidoId)
    {
        return ProducerQuotaLine::with(['quota.thirdParty:id,name,type', 'brand:id,name,code', 'customer.thirdParty:id,name',
            'producer:id,name', 'block:id,shipment_id,cargo_mode'])
            ->whereHas('block', fn ($q) => $q->where('customer_order_id', $pedidoId)) // por el bloque: el cupo puede ser de la semana siguiente
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->get();
    }

    private function cargar(CustomerOrder $pedido): array
    {
        $compras = $this->comprasDelPedido($pedido->id);

        return [
            'order'     => $pedido->only(['id', 'week', 'year', 'status']),
            'purchases' => $compras->map(fn ($l) => [
                'id'               => $l->id,
                'third_party'      => $l->quota->thirdParty,
                'customer'         => $l->customer?->thirdParty?->name,
                'brand'            => $l->brand,
                'boxes'            => (int) $l->quantity_cajas,
                // 17/11/2026: sin payment_scheme (80/20 o 100 %): el tarjador no ve nada de precios ni forma de pago
                'producer'         => $l->producer?->name,   // productor real si se compró a un comercial
                // 13/11/2026: compra global de comercializadora que aún no se divide por productor
                'farm_name'        => $l->pending_split ? 'POR DIVIDIR' : $l->farm_name,
                'shipment_id'      => $l->block?->shipment_id, // barco del bloque (se propone en la tarja)
                'cargo_mode'       => $l->block?->cargo_mode,
                'liquidada'        => $l->quota->status === ProducerQuota::LIQUIDADO,
            ])->values(),
            'shipments' => Shipment::with('vessel:id,name')
                ->where('customer_order_id', $pedido->id)->where('status', true)->orderBy('id')
                ->get(['id', 'code', 'vessel_id', 'vessel_name', 'voyage', 'departure_date']),
            // 17/11/2026: solo las activas (las quitadas se ven en el historial)
            'tallies'   => TallySheet::whereIn('producer_quota_line_id', $compras->pluck('id'))->where('status', true)
                ->orderBy('date')->orderBy('id')->get(),
        ];
    }
}
