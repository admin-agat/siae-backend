<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ShippingLineController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingLine::query();

        if ($request->boolean('only_active', false)) {
            $query->where('status', true);
        }

        if ($search = $request->get('search')) {
            $query->where('name', 'ILIKE', "%{$search}%");
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:shipping_lines,name',
        ], [
            'name.required' => 'El nombre de la naviera es obligatorio.',
            'name.unique' => 'Ya existe una naviera con ese nombre.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $shippingLine = ShippingLine::create([
            'name' => strtoupper($request->name),
            'status' => true,
        ]);

        return response()->json($shippingLine, 201);
    }

    public function show($id)
    {
        $shippingLine = ShippingLine::find($id);

        if (!$shippingLine) {
            return response()->json(['message' => 'Naviera no encontrada.'], 404);
        }

        return response()->json($shippingLine);
    }

    public function update(Request $request, $id)
    {
        $shippingLine = ShippingLine::find($id);

        if (!$shippingLine) {
            return response()->json(['message' => 'Naviera no encontrada.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:shipping_lines,name,' . $id,
        ], [
            'name.required' => 'El nombre de la naviera es obligatorio.',
            'name.unique' => 'Ya existe una naviera con ese nombre.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $shippingLine->update(['name' => strtoupper($request->name)]);

        return response()->json($shippingLine);
    }

    public function deactivate($id)
    {
        $shippingLine = ShippingLine::find($id);

        if (!$shippingLine) {
            return response()->json(['message' => 'Naviera no encontrada.'], 404);
        }

        $shippingLine->update(['status' => false]);

        return response()->json($shippingLine);
    }

    public function reactivate($id)
    {
        $shippingLine = ShippingLine::find($id);

        if (!$shippingLine) {
            return response()->json(['message' => 'Naviera no encontrada.'], 404);
        }

        $shippingLine->update(['status' => true]);

        return response()->json($shippingLine);
    }
}