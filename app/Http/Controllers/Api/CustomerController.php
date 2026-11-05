<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

// 02/11/2026: solo lectura (selectores de pedidos, contratos, compras y cierre). La ficha del cliente
// (país, contacto, marcas, activo) se crea y edita en Terceros: ThirdPartyController.
class CustomerController extends Controller
{
    /**
     * Lista clientes internacionales, con su Tercero base cargado.
     */
    public function index(Request $request)
    {
        // tiene_contrato_vigente: true si hoy hay un contrato activo dentro de su vigencia
        $hoy = now(config('app.zona_operacion'))->toDateString();
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
}
