<?php

// USUARIOS (CONFIGURACIÓN, solo ADMIN) — 29/10/2026
// Ubicación: app/Http/Controllers/Api/UserController.php
//
// El ADMIN crea los usuarios de cada área con su rol. El campo "email" guarda el correo o un
// nombre de usuario (los bodegueros entran con "bquevedo1"). Nunca se borran: se desactivan y
// pierden la sesión al instante (se borran sus tokens).
// BODEGUERO: se le asigna su bodega (warehouses.responsible_user_id), que es la que puede operar.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public const ROLES = ['ADMIN', 'COORDINADOR_INVENTARIO', 'JEFE_BODEGA', 'BODEGUERO', 'EXPORTACIONES', 'COMERCIAL'];

    public function index()
    {
        $bodegas = Warehouse::whereNotNull('responsible_user_id')->get(['id', 'name', 'responsible_user_id'])
            ->keyBy('responsible_user_id');

        return response()->json(
            User::orderBy('name')->get(['id', 'name', 'email', 'role', 'phone', 'status', 'created_at'])
                ->map(fn ($u) => $u->toArray() + [
                    'warehouse_id'   => $bodegas[$u->id]->id ?? null,
                    'warehouse_name' => $bodegas[$u->id]->name ?? null,
                ])
        );
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        $usuario = DB::transaction(function () use ($data) {
            $usuario = User::create([
                'name'     => mb_strtoupper(trim($data['name'])),
                'email'    => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'role'     => $data['role'],
                'phone'    => $data['phone'] ?? null,
                'status'   => true,
            ]);
            $this->asignarBodega($usuario, $data);
            return $usuario;
        });

        return response()->json($usuario, 201);
    }

    public function update(Request $request, $id)
    {
        $usuario = User::findOrFail($id);
        $data = $this->validar($request, $usuario);

        if ($usuario->id === $request->user()->id && $data['role'] !== 'ADMIN') {
            return response()->json(['message' => 'NO PUEDES QUITARTE EL ROL ADMIN A TI MISMO.'], 422);
        }

        DB::transaction(function () use ($usuario, $data) {
            $usuario->fill([
                'name'  => mb_strtoupper(trim($data['name'])),
                'email' => mb_strtolower(trim($data['email'])),
                'role'  => $data['role'],
                'phone' => $data['phone'] ?? null,
            ]);
            // La contraseña solo cambia si se escribe una nueva
            if (!empty($data['password'])) {
                $usuario->password = $data['password'];
            }
            $cambioSensible = $usuario->isDirty(['role', 'password', 'email']);
            $usuario->save();
            $this->asignarBodega($usuario, $data);
            // Cambió rol, usuario o contraseña: debe volver a iniciar sesión
            if ($cambioSensible) {
                $usuario->tokens()->delete();
            }
        });

        return response()->json($usuario->fresh());
    }

    public function deactivate(Request $request, $id)
    {
        $usuario = User::findOrFail($id);
        if ($usuario->id === $request->user()->id) {
            return response()->json(['message' => 'NO PUEDES DESACTIVAR TU PROPIO USUARIO.'], 422);
        }
        $usuario->update(['status' => false]);
        $usuario->tokens()->delete(); // sale del sistema al instante

        return response()->json($usuario);
    }

    public function reactivate($id)
    {
        $usuario = User::findOrFail($id);
        $usuario->update(['status' => true]);

        return response()->json($usuario);
    }

    private function validar(Request $request, ?User $usuario = null): array
    {
        return $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => ['required', 'string', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password'     => [$usuario ? 'nullable' : 'required', 'string', 'min:8', 'max:100'],
            'role'         => 'required|in:' . implode(',', self::ROLES),
            'phone'        => 'nullable|string|max:20',
            'warehouse_id' => 'nullable|required_if:role,BODEGUERO|exists:warehouses,id',
        ], [
            'email.unique'             => 'ESE USUARIO O CORREO YA EXISTE.',
            'password.required'        => 'ESCRIBE UNA CONTRASEÑA.',
            'password.min'             => 'LA CONTRASEÑA DEBE TENER AL MENOS 8 CARACTERES.',
            'warehouse_id.required_if' => 'UN BODEGUERO DEBE TENER SU BODEGA.',
        ]);
    }

    // Un BODEGUERO queda como responsable de su bodega (una bodega tiene un solo responsable).
    // Si deja de ser BODEGUERO, se libera la bodega que tenía.
    private function asignarBodega(User $usuario, array $data): void
    {
        Warehouse::where('responsible_user_id', $usuario->id)
            ->when($data['role'] === 'BODEGUERO', fn ($q) => $q->where('id', '!=', $data['warehouse_id']))
            ->update(['responsible_user_id' => null]);

        if ($data['role'] === 'BODEGUERO') {
            Warehouse::find($data['warehouse_id'])?->update(['responsible_user_id' => $usuario->id]); // uno por uno: queda en la bitácora
        }
    }
}
