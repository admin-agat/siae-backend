<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Shipment::with(['shippingLine', 'departurePort']);

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        $shipments = $query->orderByDesc('id')->get();

        return response()->json($shipments);
    }

    public function show($id)
    {
        $shipment = Shipment::with([
            'shippingLine',
            'departurePort',
            'lines.customer.thirdParty:id,name',
            'lines.destination',
            'lines.sku',
            'lines.booking',
        ])->findOrFail($id);

        return response()->json($shipment);
    }

    /**
     * Vista previa del próximo código (EMB-00000018), sin reservar nada.
     * Igual que getNextPurchaseOrderCode.
     */
    public function nextCode()
    {
        return response()->json(['code' => $this->generarCodigo()]);
    }

    private function reglasLinea(): array
    {
        return [
            'lines' => 'required|array|min:1',
            'lines.*.customer_id' => 'required|exists:customers,id',
            'lines.*.week' => 'required|integer|min:1|max:53',
            'lines.*.destination_port_id' => 'required|exists:destinations,id',
            'lines.*.arrival_date' => 'nullable|date',
            'lines.*.sku_id' => 'required|exists:skus,id',
            'lines.*.booking_id' => 'nullable|exists:bookings,id',
            'lines.*.type' => ['required', Rule::in(ShipmentLine::TIPOS)],
            'lines.*.incoterm' => 'nullable|string|max:10',
            'lines.*.negotiation_type' => ['nullable', Rule::in(ShipmentLine::NEGOTIATION_TYPES)],
            'lines.*.container_quantity' => 'nullable|numeric|min:0',
            'lines.*.box_quantity' => 'required|numeric|min:0',
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'departure_date' => 'required|date',
            'vessel_name' => 'required|string|max:255',
            'shipping_line_id' => 'required|exists:shipping_lines,id',
            'departure_port_id' => 'nullable|exists:ports,id',
            'week_start' => 'required|integer|min:1|max:53',
            'week_end' => 'required|integer|min:1|max:53',
            'year' => 'required|integer',
            'comment' => 'nullable|string',
        ], $this->reglasLinea()));

        return DB::transaction(function () use ($validated, $request) {
            $cabecera = collect($validated)->except('lines')->toArray();
            $cabecera['code'] = $this->generarCodigo();
            $cabecera['registration_date'] = now()->toDateString();
            $cabecera['created_by'] = $request->user()?->id;
            $cabecera['status'] = true;

            $shipment = Shipment::create($cabecera);

            foreach ($validated['lines'] as $lineaData) {
                $lineaData['shipment_id'] = $shipment->id;
                $lineaData['container_quantity'] = $lineaData['container_quantity'] ?? 0;
                $lineaData['status'] = true;
                ShipmentLine::create($lineaData);
            }

            $shipment->load(['shippingLine', 'departurePort', 'lines.customer.thirdParty:id,name', 'lines.destination', 'lines.sku', 'lines.booking']);

            return response()->json($shipment, 201);
        });
    }

    /**
     * Actualiza cabecera + sincroniza líneas: reemplaza el set completo de líneas
     * (borra las que ya no vengan en el request, actualiza las que sí, crea las nuevas).
     * Es el mismo patrón "guardar todo junto" que usa el formulario en el frontend.
     */
    public function update(Request $request, $id)
    {
        $shipment = Shipment::findOrFail($id);

        $validated = $request->validate(array_merge([
            'departure_date' => 'required|date',
            'vessel_name' => 'required|string|max:255',
            'shipping_line_id' => 'required|exists:shipping_lines,id',
            'departure_port_id' => 'nullable|exists:ports,id',
            'week_start' => 'required|integer|min:1|max:53',
            'week_end' => 'required|integer|min:1|max:53',
            'year' => 'required|integer',
            'comment' => 'nullable|string',
            'lines.*.id' => 'nullable|exists:shipment_lines,id',
        ], $this->reglasLinea()));

        return DB::transaction(function () use ($shipment, $validated) {
            $cabecera = collect($validated)->except('lines')->toArray();
            $shipment->update($cabecera);

            $idsEnviados = collect($validated['lines'])->pluck('id')->filter()->all();

            // Borra las líneas que ya no vienen en el request
            $shipment->lines()->whereNotIn('id', $idsEnviados)->delete();

            foreach ($validated['lines'] as $lineaData) {
                $lineaData['shipment_id'] = $shipment->id;
                $lineaData['container_quantity'] = $lineaData['container_quantity'] ?? 0;

                if (!empty($lineaData['id'])) {
                    ShipmentLine::where('id', $lineaData['id'])->update(collect($lineaData)->except('id')->toArray());
                } else {
                    $lineaData['status'] = true;
                    ShipmentLine::create(collect($lineaData)->except('id')->toArray());
                }
            }

            $shipment->load(['shippingLine', 'departurePort', 'lines.customer.thirdParty:id,name', 'lines.destination', 'lines.sku', 'lines.booking']);

            return response()->json($shipment);
        });
    }

    public function deactivate($id)
    {
        $shipment = Shipment::findOrFail($id);
        $shipment->update(['status' => false]);

        return response()->json($shipment);
    }

    public function reactivate($id)
    {
        $shipment = Shipment::findOrFail($id);
        $shipment->update(['status' => true]);

        return response()->json($shipment);
    }

    /**
     * Genera el siguiente código secuencial: EMB-00000001, EMB-00000002, ...
     */
    private function generarCodigo(): string
    {
        $ultimo = Shipment::orderByDesc('id')->first();

        $siguiente = 1;
        if ($ultimo && preg_match('/(\d+)$/', $ultimo->code, $match)) {
            $siguiente = (int) $match[1] + 1;
        }

        return 'EMB-' . str_pad($siguiente, 8, '0', STR_PAD_LEFT);
    }
}