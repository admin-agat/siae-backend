<?php

// PRECIOS OFICIALES MAG (30/10/2026)
// Precio mínimo por caja que fija el MAG por acuerdo ministerial, por tipo de caja y vigencia.
// La compra de fruta lo propone según el tipo de caja de la marca (se puede editar en la compra:
// el valor queda copiado en cada compra, así que corregir esta tabla no cambia compras anteriores).
//
//   GET   /official-prices                  listado (todos; la página filtra)
//   POST  /official-prices                  crear      (ADMIN, COMERCIAL)
//   PUT   /official-prices/{id}             editar     (ADMIN, COMERCIAL)
//   PATCH /official-prices/{id}/deactivate  desactivar (ADMIN, COMERCIAL)
//   PATCH /official-prices/{id}/reactivate  reactivar  (ADMIN, COMERCIAL)

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfficialBoxPrice;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OfficialBoxPriceController extends Controller
{
    public function index()
    {
        return response()->json(
            OfficialBoxPrice::with('boxType:id,name')->orderByDesc('valid_from')->orderBy('box_type_id')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $this->sinCruce($data);

        $precio = OfficialBoxPrice::create($data + ['status' => true, 'created_by' => $request->user()?->id]);

        return response()->json($precio->load('boxType:id,name'), 201);
    }

    public function update(Request $request, $id)
    {
        $precio = OfficialBoxPrice::findOrFail($id);
        $data = $this->validar($request);
        if ($precio->status) {
            $this->sinCruce($data, $precio->id);
        }
        $precio->update($data);

        return response()->json($precio->fresh()->load('boxType:id,name'));
    }

    public function deactivate($id)
    {
        $precio = OfficialBoxPrice::findOrFail($id);
        $precio->update(['status' => false]);

        return response()->json($precio->fresh()->load('boxType:id,name'));
    }

    public function reactivate($id)
    {
        $precio = OfficialBoxPrice::findOrFail($id);
        $this->sinCruce($precio->only(['box_type_id']) + [
            'valid_from' => $precio->valid_from->format('Y-m-d'),
            'valid_to'   => $precio->valid_to?->format('Y-m-d'),
        ], $precio->id);
        $precio->update(['status' => true]);

        return response()->json($precio->fresh()->load('boxType:id,name'));
    }

    private function validar(Request $request): array
    {
        $data = $request->validate([
            'box_type_id'      => 'required|exists:box_types,id',
            'valid_from'       => 'required|date',
            'valid_to'         => 'nullable|date',
            'price'            => 'required|numeric|min:0.01|max:999',
            'agreement_number' => 'nullable|string|max:60',
            'notes'            => 'nullable|string|max:255',
        ], [
            'box_type_id.required'     => 'ELIGE EL TIPO DE CAJA.',
            'valid_from.required'      => 'ESCRIBE DESDE CUÁNDO RIGE EL PRECIO.',
            'price.required'           => 'ESCRIBE EL PRECIO OFICIAL.',
            'price.min'                => 'EL PRECIO DEBE SER MAYOR A CERO.',
        ]);
        // Se compara a mano: after_or_equal:valid_from registraba un error de Carbon en el log
        if (!empty($data['valid_to']) && $data['valid_to'] < $data['valid_from']) {
            throw ValidationException::withMessages(['valid_to' => 'LA FECHA "HASTA" NO PUEDE SER ANTERIOR A "DESDE".']);
        }

        foreach (['agreement_number', 'notes'] as $campo) {
            $data[$campo] = isset($data[$campo]) && trim($data[$campo]) !== '' ? mb_strtoupper(trim($data[$campo])) : null;
        }
        $data['valid_to'] = $data['valid_to'] ?? null;

        return $data;
    }

    /**
     * Dos precios activos del mismo tipo de caja no pueden regir en las mismas fechas
     * (si no, no se sabría cuál proponer en la compra).
     */
    private function sinCruce(array $data, ?int $ignorarId = null): void
    {
        $hasta = $data['valid_to'] ?? '9999-12-31';
        $cruce = OfficialBoxPrice::where('box_type_id', $data['box_type_id'])
            ->where('status', true)
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->where('valid_from', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $data['valid_from']))
            ->first();

        if ($cruce) {
            abort(response()->json([
                'message' => 'YA HAY UN PRECIO OFICIAL PARA ESE TIPO DE CAJA DESDE EL ' . $cruce->valid_from->format('d/m/Y')
                    . ($cruce->valid_to ? ' HASTA EL ' . $cruce->valid_to->format('d/m/Y') : ' (SIN FECHA DE FIN)')
                    . '. PONLE FECHA "HASTA" AL ANTERIOR O CAMBIA LAS FECHAS.',
            ], 422));
        }
    }
}
