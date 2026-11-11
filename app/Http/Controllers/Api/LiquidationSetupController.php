<?php

// DATOS BASE DE LA LIQUIDACIÓN (06/11/2026) — parte 1 de "liquidación como el Excel"
// Ubicación: app/Http/Controllers/Api/LiquidationSetupController.php
//
// Parámetros de la semana (hoja PARAMETROS) — pantalla parametros_liquidacion
//   GET  /settlement-parameters                 semanas registradas (la más reciente primero)
//   GET  /settlement-parameters/propuesta       valores para una semana nueva (copia la última)
//   POST /settlement-parameters                 crear semana
//   PUT  /settlement-parameters/{id}            editar
// Tabla de retención (hoja CALCULADORA BANANERA) — pantalla parametros_liquidacion
//   GET  /retention-brackets                    tramos
//   PUT  /retention-brackets                    reemplaza la tabla completa
//   GET  /retention-brackets/simular            cajas + precio + régimen → tasa y retención
// Contratos y régimen de retención (hoja Base Productores) — pantalla contratos_productores
//   GET  /producer-contracts                    productores y comercializadoras con sus contratos
//   PUT  /producer-contracts/{thirdPartyId}     régimen, nombre para el banco y contratos
//   GET  /producer-contracts/activos            contratos activos (Compra de fruta los usa para elegir)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RetentionBracket;
use App\Models\SettlementWeekParameter;
use App\Models\ThirdParty;
use App\Services\RetencionBanano;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LiquidationSetupController extends Controller
{
    // Tipos de tercero a los que se les compra fruta (los que se liquidan)
    private const TIPOS_VENDEDOR = ['PRODUCTOR', 'COMERCIALIZADORA'];

    /* ---------------------------- Parámetros de la semana ---------------------------- */

    public function parameters()
    {
        return response()->json(SettlementWeekParameter::orderByDesc('year')->orderByDesc('week')->get());
    }

    public function proposal()
    {
        $ultima = SettlementWeekParameter::orderByDesc('year')->orderByDesc('week')->first();
        $hoy = now(config('app.zona_operacion'));

        return response()->json([
            'year'              => $hoy->isoWeekYear(),
            'week'              => $hoy->isoWeek(),
            'minimum_price'     => $ultima?->minimum_price ?? 7.50,
            'box_type'          => $ultima?->box_type ?? '22XU',
            'bpa_rate'          => $ultima?->bpa_rate ?? 0.01,
            'pto_bolivar_bonus' => $ultima?->pto_bolivar_bonus ?? 0.30,
        ]);
    }

    public function storeParameters(Request $request)
    {
        $data = $this->validarParametros($request);
        if (SettlementWeekParameter::where('year', $data['year'])->where('week', $data['week'])->exists()) {
            throw ValidationException::withMessages(['week' => "YA HAY PARÁMETROS PARA LA SEMANA {$data['week']} DE {$data['year']}. EDÍTALOS."]);
        }
        $fila = SettlementWeekParameter::create($data + ['created_by' => $request->user()?->id, 'updated_by' => $request->user()?->id]);

        return response()->json($fila, 201);
    }

    public function updateParameters(Request $request, $id)
    {
        $fila = SettlementWeekParameter::findOrFail($id);
        $data = $this->validarParametros($request);
        if (SettlementWeekParameter::where('year', $data['year'])->where('week', $data['week'])->where('id', '!=', $fila->id)->exists()) {
            throw ValidationException::withMessages(['week' => "YA HAY PARÁMETROS PARA LA SEMANA {$data['week']} DE {$data['year']}."]);
        }
        $fila->update($data + ['updated_by' => $request->user()?->id]);

        return response()->json($fila->fresh());
    }

    private function validarParametros(Request $request): array
    {
        $data = $request->validate([
            'year'              => 'required|integer|min:2024|max:2100',
            'week'              => 'required|integer|min:1|max:53',
            'minimum_price'     => 'required|numeric|min:0.01|max:99',
            'box_type'          => 'required|string|max:20',
            'bpa_rate'          => 'required|numeric|min:0|max:0.1',
            'pto_bolivar_bonus' => 'required|numeric|min:0|max:5',
            'notes'             => 'nullable|string|max:255',
        ], [
            'minimum_price.required' => 'ESCRIBE EL PRECIO MÍNIMO OFICIAL POR CAJA.',
            'minimum_price.min'      => 'EL PRECIO MÍNIMO DEBE SER MAYOR A CERO.',
            'bpa_rate.max'           => 'LA TASA BPA SE ESCRIBE EN PORCENTAJE (EJ. 1 %), NO MÁS DE 10 %.',
            'box_type.required'      => 'ESCRIBE EL TIPO DE CAJA (EJ. 22XU).',
        ]);
        $data['box_type'] = mb_strtoupper(trim($data['box_type']));
        $data['notes'] = isset($data['notes']) && trim($data['notes']) !== '' ? mb_strtoupper(trim($data['notes'])) : null;

        return $data;
    }

    /* ------------------------------- Tabla de retención ------------------------------- */

    public function brackets()
    {
        return response()->json(RetentionBracket::orderBy('from_boxes')->get());
    }

    public function saveBrackets(Request $request)
    {
        $data = $request->validate([
            'brackets'              => 'required|array|min:1|max:10',
            'brackets.*.from_boxes' => 'required|integer|min:0',
            'brackets.*.to_boxes'   => 'nullable|integer|min:1',
            'brackets.*.rate'       => 'required|numeric|min:0|max:0.1',
        ]);
        // Los tramos deben ir seguidos, sin huecos: el primero desde 0, cada uno arranca donde
        // termina el anterior y solo el último queda "en adelante".
        $tramos = collect($data['brackets'])->sortBy('from_boxes')->values();
        $esperado = 0;
        foreach ($tramos as $i => $t) {
            $ultimo = $i === $tramos->count() - 1;
            if ((int) $t['from_boxes'] !== $esperado) {
                throw ValidationException::withMessages(['brackets' => 'LOS TRAMOS DEBEN IR SEGUIDOS: EL TRAMO ' . ($i + 1) . " DEBE EMPEZAR EN $esperado CAJAS."]);
            }
            if (!$ultimo && empty($t['to_boxes'])) {
                throw ValidationException::withMessages(['brackets' => 'SOLO EL ÚLTIMO TRAMO PUEDE QUEDAR "EN ADELANTE" (SIN "HASTA").']);
            }
            if ($ultimo && !empty($t['to_boxes'])) {
                throw ValidationException::withMessages(['brackets' => 'EL ÚLTIMO TRAMO DEBE QUEDAR "EN ADELANTE" (SIN "HASTA").']);
            }
            if (!$ultimo && (int) $t['to_boxes'] <= (int) $t['from_boxes']) {
                throw ValidationException::withMessages(['brackets' => 'EN EL TRAMO ' . ($i + 1) . ' "HASTA" DEBE SER MAYOR QUE "DESDE".']);
            }
            $esperado = (int) ($t['to_boxes'] ?? 0);
        }

        DB::transaction(function () use ($tramos) {
            // Uno por uno para que quede en la bitácora
            RetentionBracket::orderBy('id')->get()->each->delete();
            foreach ($tramos as $t) {
                RetentionBracket::create([
                    'from_boxes' => $t['from_boxes'],
                    'to_boxes'   => $t['to_boxes'] ?? null,
                    'rate'       => $t['rate'],
                ]);
            }
        });

        return $this->brackets();
    }

    public function simulate(Request $request)
    {
        $data = $request->validate([
            'boxes'    => 'required|integer|min:1',
            'price'    => 'required|numeric|min:0',
            'regime'   => ['required', Rule::in(ThirdParty::REGIMENES)],
            'bpa_rate' => 'nullable|numeric|min:0|max:0.1',
        ]);
        $calc = new RetencionBanano();
        $tasa = $calc->tasa((int) $data['boxes'], $data['regime'], (float) ($data['bpa_rate'] ?? 0.01));
        $ingreso = round($data['boxes'] * $data['price'], 2);

        return response()->json([
            'ingreso'   => $ingreso,
            'tasa'      => $tasa,
            'retencion' => $calc->retencion($ingreso, $tasa),
        ]);
    }

    /* --------------------------- Contratos y régimen (Base Productores) --------------------------- */

    public function producers(Request $request)
    {
        $terceros = ThirdParty::whereIn('type', self::TIPOS_VENDEDOR)
            ->where(fn ($q) => $q->where('status', true)->orWhereHas('contracts'))
            ->with(['contracts' => fn ($q) => $q->orderByDesc('status')->orderBy('number')])
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'identification', 'status', 'retention_regime', 'payment_short_name', 'magap_registered_boxes']);

        // Cajas compradas por contrato en la semana actual (para ver el cupo en la pantalla)
        $hoy = now(config('app.zona_operacion'));
        $usado = $this->cajasPorContrato($hoy->isoWeekYear(), $hoy->isoWeek());

        return response()->json([
            'semana'     => ['year' => $hoy->isoWeekYear(), 'week' => $hoy->isoWeek()],
            'terceros'   => $terceros->map(function ($t) use ($usado) {
                $t->contracts->each(fn ($c) => $c->setAttribute('cajas_semana', (int) ($usado[$c->id] ?? 0)));

                return $t;
            }),
        ]);
    }

    public function activeContracts()
    {
        return response()->json(
            Contract::where('status', true)->orderBy('third_party_id')->orderBy('number')
                ->get(['id', 'third_party_id', 'number', 'price', 'weekly_boxes', 'discount_condition', 'start_date', 'end_date'])
        );
    }

    public function saveProducer(Request $request, $thirdPartyId)
    {
        $tercero = ThirdParty::whereIn('type', self::TIPOS_VENDEDOR)->findOrFail($thirdPartyId);
        $data = $request->validate([
            'retention_regime'                => ['required', Rule::in(ThirdParty::REGIMENES)],
            'payment_short_name'              => 'nullable|string|max:40',
            'magap_registered_boxes'          => 'nullable|integer|min:0|max:1000000', // 11/11/2026
            'contracts'                       => 'present|array|max:10',
            'contracts.*.id'                  => 'nullable|integer',
            'contracts.*.number'              => 'required|integer|min:1|max:20',
            'contracts.*.weekly_boxes'        => 'required|integer|min:0|max:1000000',
            'contracts.*.price'               => 'required|numeric|min:0.01|max:99',
            'contracts.*.discount_condition'  => ['nullable', Rule::in(Contract::CONDICIONES)],
            'contracts.*.charge_transfer'     => 'required|boolean',
            'contracts.*.charge_evaluators'   => 'required|boolean',
            'contracts.*.charge_materials'    => 'required|boolean',
            'contracts.*.charge_transport'    => 'required|boolean',
            'contracts.*.start_date'          => 'nullable|date',
            'contracts.*.end_date'            => 'nullable|date',
            'contracts.*.magap_contract_number' => 'nullable|string|max:40',
            'contracts.*.notes'               => 'nullable|string|max:255',
            'contracts.*.banana_code'         => 'nullable|string|max:60',  // 11/11/2026: código único bananero
            'contracts.*.farm_name'           => 'nullable|string|max:120', // 11/11/2026: finca del contrato
            'contracts.*.status'              => 'required|boolean',
        ], [
            'retention_regime.required'       => 'ELIGE EL RÉGIMEN DE RETENCIÓN.',
            'contracts.*.number.required'     => 'CADA CONTRATO DEBE TENER SU NÚMERO (1, 2…).',
            'contracts.*.price.required'      => 'CADA CONTRATO DEBE TENER SU PRECIO.',
            'contracts.*.price.min'           => 'EL PRECIO DEL CONTRATO DEBE SER MAYOR A CERO.',
            'contracts.*.weekly_boxes.required' => 'CADA CONTRATO DEBE TENER SU CUPO SEMANAL DE CAJAS.',
        ]);

        $actuales = $tercero->contracts()->get()->keyBy('id');
        $errores = [];
        $numerosActivos = [];
        foreach ($data['contracts'] as $i => $c) {
            $n = $i + 1;
            if (!empty($c['id']) && !$actuales->has($c['id'])) {
                $errores["contracts.$i"] = "CONTRATO $n: NO ES DE {$tercero->name}.";
            }
            if ($c['status']) {
                if (in_array((int) $c['number'], $numerosActivos, true)) {
                    $errores["contracts.$i.number"] = "HAY DOS CONTRATOS ACTIVOS CON EL NÚMERO {$c['number']}. DESACTIVA EL ANTERIOR O CAMBIA EL NÚMERO.";
                }
                $numerosActivos[] = (int) $c['number'];
            }
            if (!empty($c['start_date']) && !empty($c['end_date']) && $c['end_date'] < $c['start_date']) {
                $errores["contracts.$i.end_date"] = "CONTRATO {$c['number']}: LA FECHA \"HASTA\" NO PUEDE SER ANTERIOR A \"DESDE\".";
            }
        }
        // Un contrato que ya tiene compras no se borra (se desactiva) ni cambia de número
        $recibidos = collect($data['contracts'])->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();
        foreach ($actuales as $actual) {
            $usado = DB::table('producer_quota_lines')->where('contract_id', $actual->id)->exists();
            if (!$usado) {
                continue;
            }
            if (!in_array($actual->id, $recibidos, true)) {
                $errores['contracts'] = "EL CONTRATO {$actual->number} YA TIENE COMPRAS: NO SE PUEDE QUITAR, SOLO DESACTIVAR.";
            } else {
                $nuevo = collect($data['contracts'])->firstWhere('id', $actual->id);
                if ((int) $nuevo['number'] !== $actual->number) {
                    $errores['contracts'] = "EL CONTRATO {$actual->number} YA TIENE COMPRAS: NO SE PUEDE CAMBIAR SU NÚMERO.";
                }
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $mayus = fn ($v) => isset($v) && trim($v) !== '' ? mb_strtoupper(trim($v)) : null;
        DB::transaction(function () use ($tercero, $data, $actuales, $recibidos, $mayus, $request) {
            $tercero->update([
                'retention_regime'   => $data['retention_regime'],
                'payment_short_name' => $mayus($data['payment_short_name'] ?? null),
                'magap_registered_boxes' => $data['magap_registered_boxes'] ?? null,
            ]);
            // Primero se quitan/desactivan los que cambian, para no chocar con el índice de número activo
            foreach ($actuales->except($recibidos) as $quitado) {
                $quitado->delete();
            }
            foreach ($data['contracts'] as $c) {
                if (!empty($c['id']) && !$c['status'] && $actuales[$c['id']]->status) {
                    $actuales[$c['id']]->update(['status' => false, 'updated_by' => $request->user()?->id]);
                }
            }
            foreach ($data['contracts'] as $c) {
                $valores = [
                    'number'                => $c['number'],
                    'weekly_boxes'          => $c['weekly_boxes'],
                    'contracted_quantity'   => $c['weekly_boxes'],
                    'price'                 => $c['price'],
                    'discount_condition'    => $c['discount_condition'] ?? null,
                    'charge_transfer'       => $c['charge_transfer'],
                    'charge_evaluators'     => $c['charge_evaluators'],
                    'charge_materials'      => $c['charge_materials'],
                    'charge_transport'      => $c['charge_transport'],
                    'start_date'            => $c['start_date'] ?? null,
                    // 11/11/2026: un contrato nuevo sin "hasta" vale hasta el 31/12 de su año
                    'end_date'              => $c['end_date'] ?? (empty($c['id']) ? Contract::finDeAnio($c['start_date'] ?? null) : null),
                    'magap_contract_number' => $mayus($c['magap_contract_number'] ?? null),
                    'notes'                 => $mayus($c['notes'] ?? null),
                    'banana_code'           => $mayus($c['banana_code'] ?? null),
                    'farm_name'             => $mayus($c['farm_name'] ?? null),
                    'status'                => $c['status'],
                    'updated_by'            => $request->user()?->id,
                ];
                if (!empty($c['id'])) {
                    $actuales[$c['id']]->update($valores);
                } else {
                    $tercero->contracts()->create($valores);
                }
            }
        });

        return response()->json($tercero->fresh()->load(['contracts' => fn ($q) => $q->orderByDesc('status')->orderBy('number')]));
    }

    /** Cajas compradas por contrato en una semana (suma de las compras de esa semana). */
    private function cajasPorContrato(int $anio, int $semana)
    {
        return DB::table('producer_quota_lines as l')
            ->join('producer_quotas as q', 'q.id', '=', 'l.producer_quota_id')
            ->where('q.year', $anio)->where('q.week_number', $semana)
            ->whereNotNull('l.contract_id')
            ->groupBy('l.contract_id')
            ->selectRaw('l.contract_id, SUM(l.quantity_cajas) AS cajas')
            ->pluck('cajas', 'l.contract_id');
    }
}
