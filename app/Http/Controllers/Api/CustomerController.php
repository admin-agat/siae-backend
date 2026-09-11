<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Lista clientes internacionales activos, con su Tercero base cargado.
     */
    public function index(Request $request)
    {
        $query = Customer::with('thirdParty')->where('status', true);

        return response()->json($query->orderBy('id', 'desc')->get());
    }

    /**
     * Muestra un cliente puntual.
     */
    public function show($id)
    {
        return Customer::with('thirdParty')->findOrFail($id);
    }

    /**
     * Crea un cliente nuevo a partir de un Tercero ya existente.
     * third_party_id es obligatorio y único (un Tercero no puede tener
     * dos registros de Customer, confirmado por el NOT NULL de la
     * columna). negotiation_type también es obligatorio (NOT NULL en
     * la base, sin default).
     */
    public function store(Request $request)
    {
        $validado = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id|unique:customers,third_party_id',
            'negotiation_type' => 'required|string|max:255',
            'country' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
        ]);

        $customer = Customer::create([
            'customer_code' => $this->calcularSiguienteCodigo(),
            'third_party_id' => $validado['third_party_id'],
            'negotiation_type' => $validado['negotiation_type'],
            'country' => $validado['country'] ?? null,
            'contact_name' => $validado['contact_name'] ?? null,
            'status' => true,
        ]);

        return response()->json($customer->load('thirdParty'), 201);
    }

    /**
     * Actualiza los datos propios de Customer (no del Tercero base — eso
     * se edita desde el módulo de Terceros).
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $validado = $request->validate([
            'negotiation_type' => 'required|string|max:255',
            'country' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
        ]);

        $customer->update($validado);

        return response()->json($customer->load('thirdParty'));
    }

    /**
     * Soft delete: nunca se borra físico, mismo estándar que Bodegas/Insumos/Motivos.
     */
    public function deactivate($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->update(['status' => false]);

        return response()->json(['message' => 'Cliente desactivado correctamente']);
    }

    public function reactivate($id)
    {
        $customer = Customer::findOrFail($id);
        $customer->update(['status' => true]);

        return response()->json(['message' => 'Cliente reactivado correctamente']);
    }

    /**
     * Genera el siguiente código correlativo tipo CUST-00001.
     */
    private function calcularSiguienteCodigo()
    {
        $ultimo = Customer::where('customer_code', 'like', 'CUST-%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$ultimo) {
            $siguiente = 1;
        } else {
            $partes = explode('-', $ultimo->customer_code);
            $siguiente = ((int) end($partes)) + 1;
        }

        return 'CUST-' . str_pad($siguiente, 5, '0', STR_PAD_LEFT);
    }
}