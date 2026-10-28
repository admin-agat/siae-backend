<?php

// Controlador de Contratos de CLIENTES (Exportaciones) — tabla customer_contracts
// Ubicación: app/Http/Controllers/Api/CustomerContractController.php
// Patrón del proyecto: namespace Api, deactivate/reactivate (nunca destroy).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerContract;
use Illuminate\Http\Request;

class CustomerContractController extends Controller
{
    // Incoterms permitidos (NULL = el contrato aplica a cualquier incoterm)
    private const INCOTERMS = ['FOB', 'CFR', 'CIF'];

    // GET /customer-contracts → lista de contratos con su cliente y tercero
    public function index(Request $request)
    {
        $query = CustomerContract::with('customer.thirdParty');

        // Filtro opcional por estado: ?status=1 solo activos
        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        // Filtro opcional por cliente: ?customer_id=3
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Más recientes primero
        return response()->json($query->orderByDesc('start_date')->get());
    }

    // POST /customer-contracts → crear
    public function store(Request $request)
    {
        $data = $this->validar($request);

        // Evita dos contratos activos que se crucen en fechas para el mismo cliente + incoterm
        if ($mensaje = $this->hayCruce($data)) {
            return response()->json(['message' => $mensaje], 422);
        }

        $data['created_by'] = auth()->id(); // quién lo registró
        $contrato = CustomerContract::create($data);

        return response()->json($contrato->load('customer.thirdParty'), 201);
    }

    // PUT /customer-contracts/{id} → actualizar
    public function update(Request $request, $id)
    {
        $contrato = CustomerContract::findOrFail($id);
        $data = $this->validar($request);

        // Mismo control de cruce, ignorando el propio contrato
        if ($mensaje = $this->hayCruce($data, $contrato->id)) {
            return response()->json(['message' => $mensaje], 422);
        }

        $contrato->update($data);

        return response()->json($contrato->load('customer.thirdParty'));
    }

    // PATCH /customer-contracts/{id}/deactivate → baja lógica
    public function deactivate($id)
    {
        $contrato = CustomerContract::findOrFail($id);
        $contrato->update(['status' => false]);
        return response()->json($contrato);
    }

    // PATCH /customer-contracts/{id}/reactivate → reactivar
    public function reactivate($id)
    {
        $contrato = CustomerContract::findOrFail($id);
        $contrato->update(['status' => true]);
        return response()->json($contrato);
    }

    // ---------------- Ayudantes privados ----------------

    // Validación común de store/update + normalización a MAYÚSCULAS
    private function validar(Request $request): array
    {
        $data = $request->validate([
            'contract_number' => 'nullable|string|max:50',
            'customer_id'     => 'required|exists:customers,id',
            'incoterm'        => 'nullable|in:' . implode(',', self::INCOTERMS),
            'start_date'      => 'required|date',
            'end_date'        => 'required|date|after_or_equal:start_date',
            'box_price'       => 'required|numeric|min:0',
            'weekly_boxes'    => 'nullable|integer|min:0',
            'comment'         => 'nullable|string',
        ], [
            'customer_id.required'    => 'DEBES SELECCIONAR UN CLIENTE.',
            'start_date.required'     => 'LA FECHA DE INICIO ES OBLIGATORIA.',
            'end_date.required'       => 'LA FECHA DE FIN ES OBLIGATORIA.',
            'end_date.after_or_equal' => 'LA FECHA DE FIN NO PUEDE SER ANTERIOR A LA DE INICIO.',
            'box_price.required'      => 'EL PRECIO POR CAJA ES OBLIGATORIO.',
            'incoterm.in'             => 'EL INCOTERM DEBE SER FOB, CFR O CIF.',
        ]);

        // Textos en mayúsculas (mb_ para respetar la Ñ y tildes); vacío → NULL
        $data['contract_number'] = !empty($data['contract_number'])
            ? mb_strtoupper(trim($data['contract_number']))
            : null;
        $data['comment'] = !empty($data['comment'])
            ? mb_strtoupper(trim($data['comment']))
            : null;
        $data['incoterm'] = $data['incoterm'] ?? null;

        return $data;
    }

    // Devuelve un mensaje si hay otro contrato ACTIVO del mismo cliente + incoterm
    // cuyas fechas se crucen con las nuevas; si no hay cruce devuelve null.
    private function hayCruce(array $data, ?int $ignorarId = null): ?string
    {
        $query = CustomerContract::where('status', true)
            ->where('customer_id', $data['customer_id'])
            // Se cruzan si: inicio existente <= fin nuevo  Y  fin existente >= inicio nuevo
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date']);

        // Mismo incoterm (NULL se compara con NULL)
        if ($data['incoterm'] === null) {
            $query->whereNull('incoterm');
        } else {
            $query->where('incoterm', $data['incoterm']);
        }

        if ($ignorarId) {
            $query->where('id', '!=', $ignorarId);
        }

        $existente = $query->first();

        return $existente
            ? "YA EXISTE UN CONTRATO ACTIVO PARA ESTE CLIENTE E INCOTERM EN ESAS FECHAS (DEL {$existente->start_date->format('d/m/Y')} AL {$existente->end_date->format('d/m/Y')})."
            : null;
    }
}
