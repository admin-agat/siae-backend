<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Controller;
use App\Models\ShipmentLine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentLineController extends Controller
{
    public function index(Request $request)
    {
        $query = ShipmentLine::with(['customer.thirdParty:id,name', 'destination', 'sku', 'booking']);

        if ($request->has('shipment_id')) {
            $query->where('shipment_id', $request->shipment_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        $lines = $query->orderBy('id')->get();

        return response()->json($lines);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'shipment_id' => 'required|exists:shipments,id',
            'customer_id' => 'required|exists:customers,id',
            'week' => 'required|integer|min:1|max:53',
            'destination_port_id' => 'required|exists:destinations,id',
            'arrival_date' => 'nullable|date',
            'sku_id' => 'required|exists:skus,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'type' => ['required', Rule::in(\App\Models\ShipmentLine::TIPOS)],
            'incoterm' => 'nullable|string|max:10',
            'negotiation_type' => ['nullable', Rule::in(\App\Models\ShipmentLine::NEGOTIATION_TYPES)],
            'container_quantity' => 'nullable|numeric|min:0',
            'box_quantity' => 'required|numeric|min:0',
        ]);

        $validated['status'] = true;
        $validated['container_quantity'] = $validated['container_quantity'] ?? 0;

        $line = ShipmentLine::create($validated);
        $line->load(['customer.thirdParty:id,name', 'destination', 'sku', 'booking']);

        return response()->json($line, 201);
    }

    public function update(Request $request, $id)
    {
        $line = ShipmentLine::findOrFail($id);

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'week' => 'required|integer|min:1|max:53',
            'destination_port_id' => 'required|exists:destinations,id',
            'arrival_date' => 'nullable|date',
            'sku_id' => 'required|exists:skus,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'type' => ['required', Rule::in(\App\Models\ShipmentLine::TIPOS)],
            'incoterm' => 'nullable|string|max:10',
            'negotiation_type' => ['nullable', Rule::in(\App\Models\ShipmentLine::NEGOTIATION_TYPES)],
            'container_quantity' => 'nullable|numeric|min:0',
            'box_quantity' => 'required|numeric|min:0',
        ]);

        $validated['container_quantity'] = $validated['container_quantity'] ?? 0;

        $line->update($validated);
        $line->load(['customer.thirdParty:id,name', 'destination', 'sku', 'booking']);

        return response()->json($line);
    }

    public function destroy($id)
    {
        // Nota: aquí sí usamos destroy real (no deactivate), porque una línea de
        // embarque mal agregada es un error de captura, no un registro histórico
        // a conservar — confirmar con Peter si prefiere deactivate en su lugar.
        $line = ShipmentLine::findOrFail($id);
        $line->delete();

        return response()->json(['message' => 'Línea eliminada']);
    }
}