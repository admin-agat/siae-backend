<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Antes: 'email' => 'required|email' — esa regla exige formato de
        // correo real y rechazaba a los BODEGUERO, cuyo usuario es un
        // username tipo "bquevedo1" (no un correo). El campo en la BD se
        // llama "email" pero en la práctica guarda username O correo según
        // el rol, así que la validación debe ser solo "string".
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            // 30/10/2026: bitácora de intentos fallidos (sin la contraseña)
            AuditLog::registrar('LOGIN_FALLIDO', 'users', null, ['usuario' => mb_substr($credentials['email'], 0, 255)]);
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden.'],
            ]);
        }

        $user = Auth::user();
        // 29/10/2026: usuario desactivado desde CONFIGURACIÓN → Usuarios
        if (!$user->status) {
            AuditLog::registrar('LOGIN_FALLIDO', 'users', $user->id, ['motivo' => 'USUARIO DESACTIVADO'], $user);
            throw ValidationException::withMessages([
                'email' => ['Tu usuario está desactivado. Consulta con el administrador.'],
            ]);
        }
        $token = $user->createToken('auth_token')->plainTextToken;
        AuditLog::registrar('INICIO_SESION', 'users', $user->id, null, $user);

        return response()->json([
            'user' => $this->conPermisos($user),
            'token' => $token,
        ]);
    }

    // 30/10/2026: el frontend lo pide al abrir el sistema para tomar al instante los permisos que el ADMIN cambió
    public function me(Request $request)
    {
        return response()->json($this->conPermisos($request->user()));
    }

    private function conPermisos($user): array
    {
        $user->loadMissing('profile');
        return $user->only(['id', 'name', 'email', 'role', 'department', 'phone', 'boss_id'])
            + ['profile_name' => $user->profile?->name, 'pantallas' => $user->pantallas(),
                // 03/11/2026: bodegas que puede operar (null = todas); el backend igual lo valida
                'bodegas' => $user->bodegasPermitidas(),
                // 09/11/2026: PRIMER_INGRESO | VENCIDA | null — el frontend muestra solo "Cambia tu contraseña"
                'cambiar_clave' => $user->motivoCambioClave()];
    }

    // 09/11/2026 — LOPDP: el propio usuario cambia su contraseña (obligatorio al primer ingreso y cada 3 meses).
    // Cierra sus otras sesiones (ej. el celular) y queda en la bitácora sin la contraseña.
    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'actual'  => 'required|string',
            'nueva'   => ['required', 'string', 'max:100', 'confirmed', 'different:actual', Password::min(8)->letters()->numbers()],
        ], [
            'actual.required'  => 'ESCRIBE TU CONTRASEÑA ACTUAL.',
            'nueva.required'   => 'ESCRIBE LA CONTRASEÑA NUEVA.',
            'nueva.confirmed'  => 'LA CONTRASEÑA NUEVA Y SU REPETICIÓN NO COINCIDEN.',
            'nueva.different'  => 'LA CONTRASEÑA NUEVA DEBE SER DISTINTA DE LA ACTUAL.',
            'nueva.min'        => 'LA CONTRASEÑA DEBE TENER AL MENOS 8 CARACTERES.',
            'nueva.password.letters' => 'LA CONTRASEÑA DEBE TENER LETRAS Y NÚMEROS.',
            'nueva.password.numbers' => 'LA CONTRASEÑA DEBE TENER LETRAS Y NÚMEROS.',
        ]);

        if (!Hash::check($data['actual'], $user->password)) {
            throw ValidationException::withMessages(['actual' => ['LA CONTRASEÑA ACTUAL NO ES CORRECTA.']]);
        }

        $user->forceFill([
            'password'             => $data['nueva'],
            'must_change_password' => false,
            'password_changed_at'  => now(),
        ])->save();
        $user->tokens()->where('id', '<>', $user->currentAccessToken()->id ?? 0)->delete();
        AuditLog::registrar('CAMBIO_CLAVE', 'users', $user->id);

        return response()->json($this->conPermisos($user->fresh()));
    }

    public function logout(Request $request)
    {
        AuditLog::registrar('CIERRE_SESION', 'users', $request->user()->id);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }
}