<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompradorController;

/*
|--------------------------------------------------------------------------
| Rutas públicas — no requieren token
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Rutas protegidas — requieren token Sanctum
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('compradores', CompradorController::class);
    
    // Aquí irán las rutas de cada módulo
    // Route::apiResource('/compradores', CompradorController::class);
    // Route::apiResource('/pedidos', PedidoController::class);
});