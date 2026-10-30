<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        AuditLog::registrar('CIERRE_SESION', 'users', $request->user()->id);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }
}