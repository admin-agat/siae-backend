<?php

// ANTICIPOS Y PRÉSTAMOS AL PRODUCTOR (29/10/2026)
// Ubicación: app/Http/Controllers/Api/ProducerAdvanceController.php
//
// Dinero entregado por adelantado al productor/comercializadora. Se descuenta en sus
// liquidaciones (total o en partes). Saldo = monto − lo descontado en liquidaciones activas.
// Un anticipo ya descontado (aunque sea en parte) no se edita ni se desactiva.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProducerAdvance;
use App\Models\ThirdParty;
use Illuminate\Http\Request;

class ProducerAdvanceController extends Controller
{
    public function index(Request $request)
    {
        $query = ProducerAdvance::with('thirdParty:id,name,type')->orderByDesc('date')->orderByDesc('id');

        if ($request->filled('third_party_id')) {
            $query->where('third_party_id', $request->third_party_id);
        }
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', filter_var($request->status, FILTER_VALIDATE_BOOLEAN));
        }

        $anticipos = $query->get()->map(fn ($a) => $this->conSaldo($a));

        // ?con_saldo=1 → solo los que aún tienen saldo por descontar (lo usa la liquidación)
        if ($request->boolean('con_saldo')) {
            $anticipos = $anticipos->filter(fn ($a) => $a['status'] && $a['saldo'] > 0)->values();
        }

        return response()->json($anticipos);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $anticipo = ProducerAdvance::create($data + ['created_by' => $request->user()?->id, 'status' => true]);

        return response()->json($this->conSaldo($anticipo->load('thirdParty:id,name,type')), 201);
    }

    public function update(Request $request, $id)
    {
        $anticipo = ProducerAdvance::findOrFail($id);
        if ($anticipo->descontado() > 0) {
            return response()->json(['message' => 'ESTE ANTICIPO YA SE DESCONTÓ EN UNA LIQUIDACIÓN: NO SE PUEDE MODIFICAR.'], 422);
        }
        $anticipo->update($this->validar($request));

        return response()->json($this->conSaldo($anticipo->load('thirdParty:id,name,type')));
    }

    public function deactivate($id)
    {
        $anticipo = ProducerAdvance::findOrFail($id);
        if ($anticipo->descontado() > 0) {
            return response()->json(['message' => 'ESTE ANTICIPO YA SE DESCONTÓ EN UNA LIQUIDACIÓN: NO SE PUEDE DESACTIVAR.'], 422);
        }
        $anticipo->update(['status' => false]);

        return response()->json($this->conSaldo($anticipo));
    }

    public function reactivate($id)
    {
        $anticipo = ProducerAdvance::findOrFail($id);
        $anticipo->update(['status' => true]);

        return response()->json($this->conSaldo($anticipo));
    }

    private function validar(Request $request): array
    {
        $data = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id',
            'date'           => 'required|date',
            'type'           => 'required|in:' . implode(',', ProducerAdvance::TIPOS),
            'amount'         => 'required|numeric|min:0.01|max:9999999999',
            'concept'        => 'nullable|string|max:255',
            'reference'      => 'nullable|string|max:60',
        ], [
            'third_party_id.required' => 'ELIGE EL PRODUCTOR O COMERCIALIZADORA.',
            'amount.min'              => 'EL MONTO DEBE SER MAYOR A CERO.',
        ]);

        $tercero = ThirdParty::find($data['third_party_id']);
        if (!in_array($tercero->type, ['PRODUCTOR', 'COMERCIALIZADORA'])) {
            abort(response()->json([
                'message' => 'SOLO SE REGISTRAN ANTICIPOS A PRODUCTORES O COMERCIALIZADORAS.',
                'errors'  => ['third_party_id' => ['SOLO SE REGISTRAN ANTICIPOS A PRODUCTORES O COMERCIALIZADORAS.']],
            ], 422));
        }

        foreach (['concept', 'reference'] as $c) {
            if (isset($data[$c])) {
                $data[$c] = mb_strtoupper(trim($data[$c]));
            }
        }

        return $data;
    }

    private function conSaldo(ProducerAdvance $a): array
    {
        $descontado = $a->descontado();

        return $a->toArray() + [
            'descontado' => round($descontado, 2),
            'saldo'      => round((float) $a->amount - $descontado, 2),
        ];
    }
}
