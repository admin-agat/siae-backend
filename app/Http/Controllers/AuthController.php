<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * LOGIN
     * Recibe email y password, verifica credenciales
     * y devuelve el token de acceso si son correctas.
     */
    public function login(Request $request)
    {
        // Validar que vengan email y password
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Buscar el usuario por email
        $user = User::where('email', $request->email)->first();

        // Verificar que existe y que la contraseña es correcta
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciales incorrectas.'
            ], 401);
        }

        // Generar token de acceso con Sanctum
        $token = $user->createToken('siae-token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    /**
     * LOGOUT
     * Elimina el token actual del usuario
     * para cerrar la sesión de forma segura.
     */
    public function logout(Request $request)
    {
        // Eliminar solo el token actual (no todos)
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.'
        ]);
    }
}