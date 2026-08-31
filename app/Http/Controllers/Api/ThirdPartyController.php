<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ThirdParty;
use Illuminate\Http\Request;

class ThirdPartyController extends Controller
{
    public function index(Request $request)
    {
        $query = ThirdParty::with(['customer', 'contracts']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Las 4 categorías reales de terceros en AGAT (coinciden con el
            // CHECK constraint de la tabla): productor, comercializadora,
            // proveedor y cliente.
            'type' => 'required|in:PRODUCTOR,COMERCIALIZADORA,PROVEEDOR,CLIENTE',
            'identification' => 'nullable|string|max:50',
            'zone' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
        ]);

        $thirdParty = ThirdParty::create($validated);

        return response()->json($thirdParty, 201);
    }

    public function show($id)
    {
        $thirdParty = ThirdParty::with(['customer', 'contracts'])->findOrFail($id);
        return response()->json($thirdParty);
    }

    public function update(Request $request, $id)
    {
        $thirdParty = ThirdParty::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:PRODUCTOR,COMERCIALIZADORA,PROVEEDOR,CLIENTE',
            'identification' => 'nullable|string|max:50',
            'zone' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
        ]);

        $thirdParty->update($validated);

        return response()->json($thirdParty);
    }

    public function destroy($id)
    {
        $thirdParty = ThirdParty::findOrFail($id);
        $thirdParty->update(['status' => false]); // nunca se borra físico

        return response()->json(['message' => 'Tercero desactivado']);
    }
}