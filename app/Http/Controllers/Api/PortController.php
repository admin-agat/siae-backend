<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Port;
use Illuminate\Http\Request;

class PortController extends Controller
{
    public function index(Request $request)
    {
        $query = Port::query();

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        $ports = $query->orderBy('name')->get();

        return response()->json($ports);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
        ]);

        $validated['status'] = true;

        $port = Port::create($validated);

        return response()->json($port, 201);
    }

    public function show($id)
    {
        $port = Port::with('tariffItems')->findOrFail($id);

        return response()->json($port);
    }

    public function update(Request $request, $id)
    {
        $port = Port::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
        ]);

        $port->update($validated);

        return response()->json($port);
    }

    public function deactivate($id)
    {
        $port = Port::findOrFail($id);
        $port->update(['status' => false]);

        return response()->json($port);
    }

    public function reactivate($id)
    {
        $port = Port::findOrFail($id);
        $port->update(['status' => true]);

        return response()->json($port);
    }
}