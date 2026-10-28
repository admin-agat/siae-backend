<?php

// Controlador de Terceros — FORMULARIO MAESTRO (solo ADMIN, por middleware en routes/api.php)
// Ubicación: app/Http/Controllers/Api/ThirdPartyController.php
//
// Cambios en esta versión:
//   1. Si el tipo es CLIENTE, crea/actualiza su ficha en customers EN EL MISMO PASO
//      (código CUST-00001 automático). Ya no hay que registrar el cliente dos veces.
//   2. Antiduplicados: no permite el mismo NOMBRE + TIPO ni la misma IDENTIFICACIÓN dos veces.
//   3. Normaliza a MAYÚSCULAS y sin espacios extra (mb_ para respetar Ñ y tildes).
//   4. index/show cargan customer (ficha de cliente) y contracts (contratos MAGAP del productor,
//      tabla contracts de 2025 — se mantiene igual que antes).
//   5. Filtro opcional ?status=1 para que los selectores muestren solo activos.
//   6. Nueva columna address (dirección). Para CLIENTE: identificación y país obligatorios.
//   7. (27/10/2026) destroy() REEMPLAZADO por deactivate() + reactivate() — estándar del SIAE:
//      baja lógica reversible con rutas PATCH, nunca DELETE (igual que WarehouseController).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ThirdParty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThirdPartyController extends Controller
{
    private const TIPOS = 'PRODUCTOR,COMERCIALIZADORA,PROVEEDOR,CLIENTE';

    // GET /third-parties → lista (con su ficha de cliente y sus contratos MAGAP)
    public function index(Request $request)
    {
        $query = ThirdParty::with(['customer', 'contracts']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        return response()->json($query->orderBy('name')->get());
    }

    // GET /third-parties/{id}
    public function show($id)
    {
        return response()->json(ThirdParty::with(['customer', 'contracts'])->findOrFail($id));
    }

    // POST /third-parties → crear tercero (y su ficha de cliente si es CLIENTE)
    public function store(Request $request)
    {
        $data = $this->validarYNormalizar($request);

        // Antiduplicados (devuelve 422 con mensaje claro)
        if ($mensaje = $this->buscarDuplicado($data)) {
            return response()->json(['message' => $mensaje], 422);
        }

        // Transacción: o se guardan tercero + cliente, o no se guarda nada
        $tercero = DB::transaction(function () use ($data) {
            $tercero = ThirdParty::create($this->soloCamposTercero($data));

            if ($tercero->type === 'CLIENTE') {
                $this->guardarFichaCliente($tercero, $data);
            }

            return $tercero;
        });

        return response()->json($tercero->load(['customer', 'contracts']), 201);
    }

    // PUT /third-parties/{id} → actualizar
    public function update(Request $request, $id)
    {
        $tercero = ThirdParty::findOrFail($id);
        $data = $this->validarYNormalizar($request);

        if ($mensaje = $this->buscarDuplicado($data, $tercero->id)) {
            return response()->json(['message' => $mensaje], 422);
        }

        DB::transaction(function () use ($tercero, $data) {
            $tercero->update($this->soloCamposTercero($data));

            // Si es (o pasó a ser) CLIENTE, crea o actualiza su ficha de cliente
            if ($tercero->type === 'CLIENTE') {
                $this->guardarFichaCliente($tercero, $data);
            }
        });

        return response()->json($tercero->fresh()->load(['customer', 'contracts']));
    }

    // PATCH /third-parties/{id}/deactivate → baja lógica (status = false)
    // Reemplaza al antiguo destroy(): nunca se borra físico, y ahora se puede revertir.
    public function deactivate($id)
    {
        $tercero = ThirdParty::findOrFail($id);
        $tercero->update(['status' => false]);

        return response()->json([
            'message' => 'TERCERO DESACTIVADO',
            'data'    => $tercero->fresh()->load(['customer', 'contracts']),
        ]);
    }

    // PATCH /third-parties/{id}/reactivate → vuelve a activar (status = true)
    // Antes no existía: un tercero desactivado no tenía forma de volver desde la pantalla.
    public function reactivate($id)
    {
        $tercero = ThirdParty::findOrFail($id);
        $tercero->update(['status' => true]);

        return response()->json([
            'message' => 'TERCERO REACTIVADO',
            'data'    => $tercero->fresh()->load(['customer', 'contracts']),
        ]);
    }

    // ---------------- Ayudantes privados ----------------

    // Valida y deja los textos en MAYÚSCULAS / sin espacios extra
    private function validarYNormalizar(Request $request): array
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'type'           => 'required|in:' . self::TIPOS,
            'identification' => 'nullable|string|max:50',
            'zone'           => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:30',
            'email'          => 'nullable|email|max:255',
            'address'        => 'nullable|string|max:255',
            'status'         => 'nullable|boolean',
            // Solo se usan cuando type = CLIENTE (país obligatorio para cliente extranjero)
            'country'        => 'required_if:type,CLIENTE|nullable|string|max:255',
            'contact_name'   => 'nullable|string|max:255',
        ], [
            'name.required'      => 'EL NOMBRE ES OBLIGATORIO.',
            'type.in'            => 'EL TIPO DEBE SER PRODUCTOR, COMERCIALIZADORA, PROVEEDOR O CLIENTE.',
            'email.email'        => 'EL EMAIL NO TIENE UN FORMATO VÁLIDO.',
            'country.required_if' => 'EL PAÍS ES OBLIGATORIO PARA UN CLIENTE EXTRANJERO.',
        ]);

        // Cliente extranjero: la identificación es obligatoria (es lo que evita duplicados)
        if ($data['type'] === 'CLIENTE' && trim($data['identification'] ?? '') === '') {
            abort(response()->json([
                'message' => 'LA IDENTIFICACIÓN ES OBLIGATORIA PARA UN CLIENTE EXTRANJERO.',
            ], 422));
        }

        // Mayúsculas + colapsa espacios dobles ("gt  " → "GT")
        $mayus = fn ($v) => ($v === null || trim($v) === '')
            ? null
            : mb_strtoupper(preg_replace('/\s+/', ' ', trim($v)));

        $data['name']           = $mayus($data['name']);
        $data['identification'] = $mayus($data['identification'] ?? null);
        $data['zone']           = $mayus($data['zone'] ?? null);
        $data['address']        = $mayus($data['address'] ?? null);
        $data['country']        = $mayus($data['country'] ?? null);
        $data['contact_name']   = $mayus($data['contact_name'] ?? null);
        $data['phone']          = !empty($data['phone']) ? trim($data['phone']) : null;
        $data['email']          = !empty($data['email']) ? mb_strtolower(trim($data['email'])) : null;

        return $data;
    }

    // Devuelve un mensaje si ya existe otro tercero con el mismo nombre+tipo o la misma identificación
    private function buscarDuplicado(array $data, ?int $ignorarId = null): ?string
    {
        // Mismo nombre y mismo tipo (comparación sin importar mayúsculas)
        $mismoNombre = ThirdParty::whereRaw('UPPER(TRIM(name)) = ?', [$data['name']])
            ->where('type', $data['type'])
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->first();

        if ($mismoNombre) {
            return "YA EXISTE UN {$data['type']} CON EL NOMBRE {$data['name']}.";
        }

        // Misma identificación (solo si se escribió una)
        if ($data['identification']) {
            $mismaId = ThirdParty::whereRaw('UPPER(TRIM(identification)) = ?', [$data['identification']])
                ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
                ->first();

            if ($mismaId) {
                return "LA IDENTIFICACIÓN {$data['identification']} YA ESTÁ REGISTRADA A NOMBRE DE {$mismaId->name}.";
            }
        }

        return null;
    }

    // Deja solo las columnas de third_parties (country/contact_name son de customers)
    private function soloCamposTercero(array $data): array
    {
        $campos = [
            'name'           => $data['name'],
            'type'           => $data['type'],
            'identification' => $data['identification'],
            'zone'           => $data['zone'],
            'phone'          => $data['phone'],
            'email'          => $data['email'],
            'address'        => $data['address'],
        ];

        // status solo si vino en el request (el checkbox "Tercero activo")
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $campos['status'] = $data['status'];
        }

        return $campos;
    }

    // Crea o actualiza la ficha de customers ligada al tercero
    private function guardarFichaCliente(ThirdParty $tercero, array $data): void
    {
        $ficha = Customer::where('third_party_id', $tercero->id)->first();

        if ($ficha) {
            // Ya existe: solo actualiza país y contacto
            $ficha->update([
                'country'      => $data['country'],
                'contact_name' => $data['contact_name'],
            ]);
            return;
        }

        // Bloquea la tabla customers mientras calcula el código, para que dos
        // registros al mismo tiempo no reciban el mismo CUST-0000X
        DB::statement('LOCK TABLE customers IN EXCLUSIVE MODE');

        Customer::create([
            'customer_code'  => $this->siguienteCodigoCliente(),
            'third_party_id' => $tercero->id,
            'country'        => $data['country'],
            'contact_name'   => $data['contact_name'],
            'status'         => true,
            // negotiation_type queda NULL: la modalidad va por pedido/contrato, no por cliente
        ]);
    }

    // Siguiente código correlativo CUST-00001 (misma lógica que CustomerController)
    private function siguienteCodigoCliente(): string
    {
        $ultimo = Customer::where('customer_code', 'like', 'CUST-%')
            ->orderBy('id', 'desc')
            ->first();

        $siguiente = $ultimo
            ? ((int) last(explode('-', $ultimo->customer_code))) + 1
            : 1;

        return 'CUST-' . str_pad($siguiente, 5, '0', STR_PAD_LEFT);
    }
}