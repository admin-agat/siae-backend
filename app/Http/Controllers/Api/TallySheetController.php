<?php

// TARJAS — lo que llega a puerto (29/10/2026)
// Ubicación: app/Http/Controllers/Api/TallySheetController.php
//
// Cada tarja es un camión de una compra de la semana (productor + cliente + marca):
// cajas EMBARCADAS, CAÍDAS y RECHAZADAS. La liquidación paga embarcadas + caídas.
//
//   GET /customer-orders/{id}/tallies → compras de la semana, barcos y tarjas
//   PUT /customer-orders/{id}/tallies → reemplaza las tarjas de la semana
// Las tarjas de un cupo ya LIQUIDADO no se cambian ni se quitan.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\ProducerQuota;
use App\Models\ProducerQuotaLine;
use App\Models\Shipment;
use App\Models\TallySheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TallySheetController extends Controller
{
    private const CAMPOS = ['producer_quota_line_id', 'shipment_id', 'date', 'number', 'truck_plate',
        'boxes_shipped', 'boxes_fallen', 'boxes_rejected', 'notes', 'container_number'];

    public function show($orderId)
    {
        return response()->json($this->cargar(CustomerOrder::findOrFail($orderId)));
    }

    public function update(Request $request, $orderId)
    {
        $pedido = CustomerOrder::findOrFail($orderId);

        $data = $request->validate([
            'tallies'                          => 'present|array',
            'tallies.*.id'                     => 'nullable|integer',
            'tallies.*.producer_quota_line_id' => 'required|integer',
            'tallies.*.shipment_id'            => 'nullable|integer',
            'tallies.*.date'                   => 'required|date',
            'tallies.*.number'                 => 'nullable|string|max:30',
            'tallies.*.truck_plate'            => 'nullable|string|max:15',
            'tallies.*.boxes_shipped'          => 'required|integer|min:0',
            'tallies.*.boxes_fallen'           => 'required|integer|min:0',
            'tallies.*.boxes_rejected'         => 'required|integer|min:0',
            'tallies.*.notes'                  => 'nullable|string|max:255',
            'tallies.*.container_number'       => 'nullable|string|max:15',
        ], [
            'tallies.*.producer_quota_line_id.required' => 'CADA TARJA DEBE TENER LA COMPRA (PRODUCTOR Y MARCA).',
            'tallies.*.date.required'                   => 'CADA TARJA DEBE TENER FECHA.',
        ]);

        $compras = $this->comprasDelPedido($pedido->id)->keyBy('id');
        $barcos = Shipment::where('customer_order_id', $pedido->id)->where('status', true)->pluck('id')->all();
        $actuales = TallySheet::whereIn('producer_quota_line_id', $compras->keys())->get()->keyBy('id');

        $errores = [];
        $idsRecibidos = [];
        foreach ($data['tallies'] as $i => $t) {
            $n = $i + 1;
            $compra = $compras->get($t['producer_quota_line_id']);
            if (!$compra) {
                $errores["tallies.$i.producer_quota_line_id"] = "TARJA $n: LA COMPRA NO ES DE ESTA SEMANA.";
                continue;
            }
            if (!empty($t['shipment_id']) && !in_array((int) $t['shipment_id'], $barcos)) {
                $errores["tallies.$i.shipment_id"] = "TARJA $n: EL BARCO NO ES DE ESTA SEMANA.";
            }
            if ($t['boxes_shipped'] + $t['boxes_fallen'] + $t['boxes_rejected'] === 0) {
                $errores["tallies.$i"] = "TARJA $n: NO TIENE CAJAS.";
            }

            $actual = !empty($t['id']) ? $actuales->get($t['id']) : null;
            if (!empty($t['id']) && !$actual) {
                $errores["tallies.$i"] = "TARJA $n: NO EXISTE O NO ES DE ESTA SEMANA.";
                continue;
            }
            if ($actual) {
                $idsRecibidos[] = $actual->id;
            }

            // Cupo liquidado: no se agregan tarjas ni se cambian las que tiene
            $liquidada = $compra->quota->status === ProducerQuota::LIQUIDADO
                || ($actual && $actual->purchase->quota->status === ProducerQuota::LIQUIDADO);
            if ($liquidada && (!$actual || $this->cambio($actual, $t))) {
                $errores["tallies.$i"] = "TARJA $n: {$compra->quota->thirdParty?->name} YA FUE LIQUIDADO EN ESTA SEMANA; SUS TARJAS NO SE PUEDEN CAMBIAR.";
            }
        }

        foreach ($actuales->except($idsRecibidos) as $quitada) {
            if ($quitada->purchase->quota->status === ProducerQuota::LIQUIDADO) {
                $errores["tallies.q{$quitada->id}"] = 'NO SE PUEDE QUITAR LA TARJA ' . ($quitada->number ?: "#{$quitada->id}")
                    . ' DE ' . $quitada->purchase->quota->thirdParty?->name . ': YA FUE LIQUIDADO.';
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        DB::transaction(function () use ($data, $actuales, $idsRecibidos, $request) {
            foreach ($data['tallies'] as $t) {
                $valores = collect($t)->only(self::CAMPOS)->all();
                $valores['shipment_id'] = $valores['shipment_id'] ?? null;
                $valores['number'] = isset($valores['number']) ? mb_strtoupper(trim($valores['number'])) : null;
                $valores['truck_plate'] = isset($valores['truck_plate']) ? mb_strtoupper(trim($valores['truck_plate'])) : null;
                $valores['container_number'] = isset($valores['container_number']) ? mb_strtoupper(trim($valores['container_number'])) : null;
                $valores['notes'] = isset($valores['notes']) ? mb_strtoupper(trim($valores['notes'])) : null;
                $actual = !empty($t['id']) ? $actuales->get($t['id']) : null;
                if ($actual) {
                    $actual->update($valores);
                } else {
                    TallySheet::create($valores + ['created_by' => $request->user()?->id]);
                }
            }
            TallySheet::whereIn('id', $actuales->except($idsRecibidos)->keys())->get()->each->delete(); // uno por uno: queda en la bitácora
        });

        return response()->json($this->cargar($pedido));
    }

    // ¿La tarja enviada es distinta a la guardada?
    private function cambio(TallySheet $actual, array $t): bool
    {
        foreach (['producer_quota_line_id', 'shipment_id', 'boxes_shipped', 'boxes_fallen', 'boxes_rejected'] as $c) {
            if ((int) ($t[$c] ?? 0) !== (int) $actual->$c) {
                return true;
            }
        }
        return $actual->date->format('Y-m-d') !== substr($t['date'], 0, 10);
    }

    private function comprasDelPedido(int $pedidoId)
    {
        return ProducerQuotaLine::with(['quota.thirdParty:id,name,type', 'brand:id,name,code', 'customer.thirdParty:id,name',
            'producer:id,name', 'block:id,shipment_id,cargo_mode'])
            ->whereHas('quota', fn ($q) => $q->where('customer_order_id', $pedidoId))
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
                'real_price'       => $l->real_price,
                'payment_scheme'   => $l->payment_scheme,
                'producer'         => $l->producer?->name,   // productor real si se compró a un comercial
                'farm_name'        => $l->farm_name,
                'shipment_id'      => $l->block?->shipment_id, // barco del bloque (se propone en la tarja)
                'cargo_mode'       => $l->block?->cargo_mode,
                'liquidada'        => $l->quota->status === ProducerQuota::LIQUIDADO,
            ])->values(),
            'shipments' => Shipment::with('vessel:id,name')
                ->where('customer_order_id', $pedido->id)->where('status', true)->orderBy('id')
                ->get(['id', 'code', 'vessel_id', 'vessel_name', 'voyage', 'departure_date']),
            'tallies'   => TallySheet::whereIn('producer_quota_line_id', $compras->pluck('id'))
                ->orderBy('date')->orderBy('id')->get(),
        ];
    }
}
