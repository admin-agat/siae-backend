<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;

class FarmController extends Controller
{
    public function index(Request $request)
    {
        $query = Farm::with('thirdParty');

        if ($request->has('third_party_id')) {
            $query->where('third_party_id', $request->third_party_id);
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->search . '%')
                  ->orWhere('magap_code', 'ilike', '%' . $request->search . '%');
            });
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'name' => 'required|string|max:255',
            'magap_code' => 'nullable|string|max:50',
            'zone' => 'nullable|string|max:100',
        ]);

        $farm = Farm::create($validated);
        return response()->json($farm->load('thirdParty'), 201);
    }

    public function show($id)
    {
        $farm = Farm::with('thirdParty')->findOrFail($id);
        return response()->json($farm);
    }

    public function update(Request $request, $id)
    {
        $farm = Farm::findOrFail($id);

        $validated = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'name' => 'required|string|max:255',
            'magap_code' => 'nullable|string|max:50',
            'zone' => 'nullable|string|max:100',
        ]);

        $farm->update($validated);
        return response()->json($farm->load('thirdParty'));
    }

    public function destroy($id)
    {
        $farm = Farm::findOrFail($id);
        $farm->update(['status' => false]); // nunca se borra físico
        return response()->json(['message' => 'Finca desactivada']);
    }
}