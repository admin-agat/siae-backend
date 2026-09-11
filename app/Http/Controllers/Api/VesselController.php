<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vessel;
use Illuminate\Http\Request;

class VesselController extends Controller
{
    // Lista barcos, opcionalmente filtrados por naviera (?shipping_line_id=)
    public function index(Request $request)
    {
        $query = Vessel::query();

        if ($request->filled('shipping_line_id')) {
            $query->where('shipping_line_id', $request->shipping_line_id);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function show(Vessel $vessel)
    {
        return response()->json($vessel);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shipping_line_id' => 'required|exists:shipping_lines,id',
        ]);

        $vessel = Vessel::create($data);

        return response()->json($vessel, 201);
    }

    public function update(Request $request, Vessel $vessel)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'shipping_line_id' => 'required|exists:shipping_lines,id',
        ]);

        $vessel->update($data);

        return response()->json($vessel);
    }

    public function deactivate(Vessel $vessel)
    {
        $vessel->update(['status' => false]);
        return response()->json($vessel);
    }

    public function reactivate(Vessel $vessel)
    {
        $vessel->update(['status' => true]);
        return response()->json($vessel);
    }
}