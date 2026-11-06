<?php

// USUARIOS (CONFIGURACIÓN, solo ADMIN) — 29/10/2026
// Ubicación: app/Http/Controllers/Api/UserController.php
//
// El ADMIN crea los usuarios de cada área con su rol. El campo "email" guarda el correo o un
// nombre de usuario (los bodegueros entran con "bquevedo1"). Nunca se borran: se desactivan y
// pierden la sesión al instante (se borran sus tokens).
// BODEGUERO: se le asigna su bodega (warehouses.responsible_user_id), que es la que puede operar.
// 30/10/2026 — permisos por persona: el usuario lleva un PERFIL (su tipo base pasa a "role"), de quién
// es asistente (boss_id) y excepciones propias: pantallas agregadas o quitadas solo para él.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        $bodegas = Warehouse::whereNotNull('responsible_user_id')->get(['id', 'name', 'responsible_user_id'])
            ->keyBy('responsible_user_id');

        return response()->json(
            User::with(['profile', 'boss:id,name', 'warehouses:id,name'])->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'department', 'phone', 'status', 'created_at', 'profile_id', 'boss_id', 'screens_added', 'screens_removed'])
                ->map(fn ($u) => collect($u->toArray())->except(['profile', 'boss', 'warehouses'])->all() + [
                    'profile_name'   => $u->profile?->name,
                    'boss_name'      => $u->boss?->name,
                    'pantallas'      => $u->pantallas(), // las que realmente ve (perfil + excepciones)
                    'warehouse_id'   => $bodegas[$u->id]->id ?? null,
                    'warehouse_name' => $bodegas[$u->id]->name ?? null,
                    // 03/11/2026: bodegas asignadas (sin ninguna = todas)
                    'warehouse_ids'   => $u->warehouses->pluck('id')->all(),
                    'warehouse_names' => $u->warehouses->pluck('name')->all(),
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
            ] + $this->permisos($data));
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
            return response()->json(['message' => 'NO PUEDES QUITARTE EL PERFIL ADMINISTRADOR A TI MISMO.'], 422);
        }
        if ((int) ($data['boss_id'] ?? 0) === $usuario->id) {
            return response()->json(['message' => 'UN USUARIO NO PUEDE SER ASISTENTE DE SÍ MISMO.'], 422);
        }

        DB::transaction(function () use ($usuario, $data) {
            $usuario->fill([
                'name'  => mb_strtoupper(trim($data['name'])),
                'email' => mb_strtolower(trim($data['email'])),
                'role'  => $data['role'],
                'phone' => $data['phone'] ?? null,
            ] + $this->permisos($data));
            // La contraseña solo cambia si se escribe una nueva
            if (!empty($data['password'])) {
                $usuario->password = $data['password'];
            }
            $cambioSensible = $usuario->isDirty(['role', 'password', 'email']);
            $usuario->save();
            $this->asignarBodega($usuario, $data);
            // Cambió tipo base, usuario o contraseña: debe volver a iniciar sesión (las pantallas se toman solas al abrir)
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
        $pantallas = array_keys(config('pantallas'));
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => ['required', 'string', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            // 02/11/2026: al menos 8 caracteres con letras y números (antes se aceptaba "12345678")
            'password'     => [$usuario ? 'nullable' : 'required', 'string', 'max:100', Password::min(8)->letters()->numbers()],
            'profile_id'        => ['required', Rule::exists('profiles', 'id')->where('status', true)],
            'boss_id'           => ['nullable', Rule::exists('users', 'id')->where('status', true)],
            'screens_added'     => 'nullable|array',
            'screens_added.*'   => ['string', Rule::in($pantallas)],
            'screens_removed'   => 'nullable|array',
            'screens_removed.*' => ['string', Rule::in($pantallas)],
            'phone'             => 'nullable|string|max:20',
            'department'        => 'nullable|string|max:60', // 05/11/2026: SISTEMAS, LOGÍSTICA... (sus OC lo llevan)
            'warehouse_id'      => 'nullable|exists:warehouses,id',
            'warehouse_ids'     => 'nullable|array',
            'warehouse_ids.*'   => 'integer|distinct|exists:warehouses,id',
        ], [
            'profile_id.required'      => 'ELIGE EL PERFIL DEL USUARIO.',
            'profile_id.exists'        => 'ESE PERFIL NO EXISTE O ESTÁ DESACTIVADO.',
            'boss_id.exists'           => 'EL JEFE ELEGIDO NO EXISTE O ESTÁ DESACTIVADO.',
            'email.unique'             => 'ESE USUARIO O CORREO YA EXISTE.',
            'password.required'        => 'ESCRIBE UNA CONTRASEÑA.',
            'password.min'             => 'LA CONTRASEÑA DEBE TENER AL MENOS 8 CARACTERES.',
            'password.letters'         => 'LA CONTRASEÑA DEBE TENER LETRAS Y NÚMEROS.',
            'password.numbers'         => 'LA CONTRASEÑA DEBE TENER LETRAS Y NÚMEROS.',
        ]);
        // El tipo base sale del perfil (de él dependen las reglas del rol en todo el sistema)
        $data['role'] = Profile::find($data['profile_id'])->base_role;
        if ($data['role'] === 'BODEGUERO' && empty($data['warehouse_id'])) {
            throw ValidationException::withMessages(['warehouse_id' => 'UN BODEGUERO DEBE TENER SU BODEGA.']);
        }
        $data['department'] = mb_strtoupper(trim((string) ($data['department'] ?? ''))) ?: null;
        if ($data['role'] === 'DEPARTAMENTO' && !$data['department']) {
            throw ValidationException::withMessages(['department' => 'ESCRIBE EL DEPARTAMENTO DEL USUARIO (EJ. SISTEMAS).']);
        }
        if (Profile::pantallasVedadas($data['role'], $data['screens_added'] ?? [])) {
            throw ValidationException::withMessages(['screens_added' => 'EL DASHBOARD ES SOLO PARA PERFILES DE TIPO GERENCIA.']);
        }

        return $data;
    }

    // Perfil, jefe y excepciones. Una pantalla no puede estar agregada y quitada a la vez; el ADMIN no lleva excepciones.
    private function permisos(array $data): array
    {
        $agregadas = array_values(array_unique($data['screens_added'] ?? []));
        $quitadas = array_values(array_diff(array_unique($data['screens_removed'] ?? []), $agregadas));
        $esAdmin = $data['role'] === 'ADMIN';

        return [
            'profile_id'      => $data['profile_id'],
            'boss_id'         => $data['boss_id'] ?? null,
            'department'      => $data['department'] ?? null,
            'screens_added'   => $esAdmin ? [] : $agregadas,
            'screens_removed' => $esAdmin ? [] : $quitadas,
        ];
    }

    // Un BODEGUERO queda como responsable de su bodega (una bodega tiene un solo responsable).
    // Si deja de ser BODEGUERO, se libera la bodega que tenía.
    // 03/11/2026: los demás (no ADMIN) pueden tener bodegas asignadas; sin ninguna = todas.
    private function asignarBodega(User $usuario, array $data): void
    {
        $asignadas = in_array($data['role'], ['ADMIN', 'BODEGUERO'], true) ? [] : array_map('intval', $data['warehouse_ids'] ?? []);
        $antes = $usuario->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $usuario->warehouses()->sync($asignadas);
        sort($asignadas);
        if ($antes !== $asignadas) {
            AuditLog::registrar('BODEGAS_USUARIO', 'users', $usuario->id, ['antes' => $antes, 'despues' => $asignadas]);
        }

        Warehouse::where('responsible_user_id', $usuario->id)
            ->when($data['role'] === 'BODEGUERO', fn ($q) => $q->where('id', '!=', $data['warehouse_id']))
            ->update(['responsible_user_id' => null]);

        if ($data['role'] === 'BODEGUERO') {
            Warehouse::find($data['warehouse_id'])?->update(['responsible_user_id' => $usuario->id]); // uno por uno: queda en la bitácora
        }
    }
}
