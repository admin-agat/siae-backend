<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Se quitó la regla "email" (formato de correo). Ahora acepta tanto
        // un correo real (admin.it.agat@gmail.com) como un username simple
        // sin arroba (bquevedo1), porque los bodegueros no tienen correo.
        // La clave 'email' del array se mantiene igual: es la que Auth::attempt
        // usa para consultar contra la columna users.email, no exige que el
        // valor tenga formato de correo.
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden.'],
            ]);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }
}