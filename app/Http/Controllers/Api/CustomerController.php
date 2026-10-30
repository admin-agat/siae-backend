<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Lista clientes internacionales, con su Tercero base cargado.
     */
    public function index(Request $request)
    {
        // tiene_contrato_vigente: true si hoy hay un contrato activo dentro de su vigencia
        $hoy = now()->toDateString();
        $query = Customer::with(['thirdParty', 'brands'])
            ->withExists(['contracts as tiene_contrato_vigente' => fn ($q) => $q
                ->where('status', true)
                ->where('start_date', '<=', $hoy)
                ->where('end_date', '>=', $hoy)]);

        // Filtro opcional por estado: ?status=1 solo activos (igual que Destinos).
        // Antes estaba fijo en activos: un cliente desactivado ya no se podía reactivar.
        // Sin ?status devuelve todos (la página de Clientes filtra por pestaña).
        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        return response()->json($query->orderBy('id', 'desc')->get());
    }

    /**
     * Muestra un cliente puntual.
     */
    public function show($id)
    {
        return Customer::with(['thirdParty', 'brands'])->findOrFail($id);
    }

    /**
     * Crea un cliente nuevo a partir de un Tercero ya existente.
     * third_party_id es obligatorio y único (un Tercero no puede tener
     * dos registros de Customer, confirmado por el NOT NULL de la
     * columna).
     * negotiation_type ya NO se pide (28/10/2026): un cliente compra por CONTRATO y
     * por SPOT a la vez; la modalidad va en cada línea de pedido/embarque. Queda NULL,
     * igual que las fichas que crea ThirdPartyController.
     */
    public function store(Request $request)
    {
        $validado = $request->validate([
            'third_party_id' => 'required|exists:third_parties,id|unique:customers,third_party_id',
            'country' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'brand_ids' => 'nullable|array',
            'brand_ids.*' => 'integer|exists:brands,id',
        ], [
            'third_party_id.unique' => 'ESTE TERCERO YA ESTÁ REGISTRADO COMO CLIENTE.',
        ]);

        $customer = Customer::create([
            'customer_code' => $this->calcularSiguienteCodigo(),
            'third_party_id' => $validado['third_party_id'],
            'country' => $validado['country'] ?? null,
            'contact_name' => $validado['contact_name'] ?? null,
            'status' => true,
        ]);

        // Marcas del cliente (varias; una marca puede ser de varios clientes)
        $this->sincronizarMarcas($customer, $validado['brand_ids'] ?? []);

        return response()->json($customer->load(['thirdParty', 'brands']), 201);
    }

    /**
     * Actualiza los datos propios de Customer (no del Tercero base — eso
     * se edita desde el módulo de Terceros).
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $validado = $request->validate([
            'country' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'brand_ids' => 'nullable|array',
            'brand_ids.*' => 'integer|exists:brands,id',
        ]);

        $customer->update(collect($validado)->except('brand_ids')->all());

        // Solo se tocan las marcas si el frontend las envía
        if ($request->has('brand_ids')) {
            $this->sincronizarMarcas($customer, $validado['brand_ids'] ?? []);
        }

        return response()->json($customer->load(['thirdParty', 'brands']));
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

    /**
     * Asigna las marcas del cliente y deja en la bitácora cuáles tenía y cuáles quedaron
     * (sync() de la tabla pivote no dispara eventos de Eloquent).
     */
    private function sincronizarMarcas(Customer $customer, array $ids): void
    {
        $antes = $customer->brands()->pluck('brands.id')->sort()->values()->all();
        $customer->brands()->sync($ids);
        $despues = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($antes !== $despues) {
            \App\Models\AuditLog::registrar('EDITAR', 'customers', $customer->id, ['brand_ids' => [$antes, $despues]]);
        }
    }
}
