<?php

// Controlador de Contratos de CLIENTES (Exportaciones) — tabla customer_contracts
// Ubicación: app/Http/Controllers/Api/CustomerContractController.php
// Patrón del proyecto: namespace Api, deactivate/reactivate (nunca destroy).
// 02/11/2026: el contrato ya no tiene un solo precio: lleva una lista de precios por MARCA + PRESENTACIÓN
// (prices: [{brand_id, presentation, box_price}]). Las marcas deben ser del cliente.
// 13/11/2026: los precios son OPCIONALES. Con Rusia por ahora solo se maneja la cantidad (cajas por
// semana); el pedido CONTRATO sin precio queda "SIN PRECIO" hasta que se registre (decisión de Peter).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerContractController extends Controller
{
    // Incoterms permitidos (NULL = el contrato aplica a cualquier incoterm)
    private const INCOTERMS = ['FOB', 'CFR', 'CIF'];

    // GET /customer-contracts → lista de contratos con su cliente y tercero
    public function index(Request $request)
    {
        $query = CustomerContract::with(['customer.thirdParty', 'prices.brand:id,name,code']);

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
        $contrato = DB::transaction(function () use ($data) {
            $contrato = CustomerContract::create(collect($data)->except('prices')->all());
            $this->guardarPrecios($contrato, $data['prices']);
            return $contrato;
        });

        return response()->json($contrato->load(['customer.thirdParty', 'prices.brand:id,name,code']), 201);
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

        DB::transaction(function () use ($contrato, $data) {
            $contrato->update(collect($data)->except('prices')->all());
            $this->guardarPrecios($contrato, $data['prices']);
        });

        return response()->json($contrato->load(['customer.thirdParty', 'prices.brand:id,name,code']));
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
            'end_date'        => 'required|date',
            'weekly_boxes'    => 'nullable|integer|min:0',
            'comment'         => 'nullable|string',
            'prices'                => 'present|array', // 13/11/2026: puede ir vacío (contrato solo de cantidad)
            'prices.*.brand_id'     => 'required|exists:brands,id',
            'prices.*.presentation' => 'required|in:' . implode(',', Brand::PRESENTACIONES),
            'prices.*.box_price'    => 'required|numeric|min:0',
        ], [
            'customer_id.required'    => 'DEBES SELECCIONAR UN CLIENTE.',
            'start_date.required'     => 'LA FECHA DE INICIO ES OBLIGATORIA.',
            'end_date.required'       => 'LA FECHA DE FIN ES OBLIGATORIA.',
            'prices.*.brand_id.required'  => 'CADA PRECIO DEBE TENER MARCA.',
            'prices.*.box_price.required' => 'CADA PRECIO DEBE TENER EL PRECIO POR CAJA.',
            'incoterm.in'             => 'EL INCOTERM DEBE SER FOB, CFR O CIF.',
        ]);
        // 30/10/2026: se compara a mano — after_or_equal:start_date registraba un error de Carbon en el log
        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            throw ValidationException::withMessages(['end_date' => 'LA FECHA DE FIN NO PUEDE SER ANTERIOR A LA DE INICIO.']);
        }

        // Textos en mayúsculas (mb_ para respetar la Ñ y tildes); vacío → NULL
        $data['contract_number'] = !empty($data['contract_number'])
            ? mb_strtoupper(trim($data['contract_number']))
            : null;
        $data['comment'] = !empty($data['comment'])
            ? mb_strtoupper(trim($data['comment']))
            : null;
        $data['incoterm'] = $data['incoterm'] ?? null;

        // Precios: marcas del cliente y sin repetir marca + presentación
        $marcasCliente = Customer::with('brands:id')->find($data['customer_id'])->brands->pluck('id')->all();
        $nombres = Brand::whereIn('id', array_column($data['prices'], 'brand_id'))->pluck('name', 'id');
        $vistos = [];
        $errores = [];
        foreach ($data['prices'] as $i => $p) {
            $marca = $nombres[$p['brand_id']] ?? '';
            if (!in_array((int) $p['brand_id'], $marcasCliente)) {
                $errores["prices.$i.brand_id"] = "$marca NO ES UNA MARCA DE ESTE CLIENTE (ASÍGNALA EN CONFIGURACIÓN → TERCEROS).";
            }
            $clave = $p['brand_id'] . '|' . $p['presentation'];
            if (isset($vistos[$clave])) {
                $errores["prices.$i.presentation"] = "$marca {$p['presentation']} ESTÁ REPETIDA EN LOS PRECIOS.";
            }
            $vistos[$clave] = true;
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $data;
    }

    // Reemplaza los precios del contrato (los pedidos ya guardados conservan su precio congelado)
    private function guardarPrecios(CustomerContract $contrato, array $precios): void
    {
        $contrato->prices()->delete();
        foreach ($precios as $p) {
            $contrato->prices()->create([
                'brand_id'     => $p['brand_id'],
                'presentation' => $p['presentation'],
                'box_price'    => $p['box_price'],
            ]);
        }
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
