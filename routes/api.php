<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ThirdPartyController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\WarehouseController;
//use App\Http\Controllers\Api\SupplyGroupController;
use App\Http\Controllers\Api\SupplyCategoryController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\MovementReasonController;
use App\Http\Controllers\Api\InventoryMovementController;


use App\Http\Controllers\Api\PurchaseOrderController;

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
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/next-code', [PurchaseOrderController::class, 'nextCode']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);

    // Solo ADMIN puede ver/editar Terceros y Fincas (fuera del alcance de Jefe de Bodega / Coordinador de Inventario)
    Route::middleware('admin')->group(function () {
        Route::apiResource('farms', FarmController::class);
        Route::apiResource('third-parties', ThirdPartyController::class);
    });


    // Módulo Inventario
    // Route::apiResource('supply-groups', SupplyGroupController::class);
    Route::apiResource('supply-categories', SupplyCategoryController::class);
    Route::apiResource('supplies', SupplyController::class);
    Route::apiResource('movement-reasons', MovementReasonController::class);
    Route::apiResource('inventory-movements', InventoryMovementController::class);

    //Bodegas
    Route::apiResource('warehouses', WarehouseController::class);    
    Route::apiResource('warehouses', WarehouseController::class)->except(['destroy']);
    Route::patch('warehouses/{id}/deactivate', [WarehouseController::class, 'deactivate']);
    Route::patch('warehouses/{id}/reactivate', [WarehouseController::class, 'reactivate']);
    

    Route::get('/inventory/stock', [InventoryMovementController::class, 'stockGeneral']);

    // Route::patch('supply-groups/{id}/deactivate', [SupplyGroupController::class, 'deactivate']);
    // Route::patch('supply-groups/{id}/reactivate', [SupplyGroupController::class, 'reactivate']);

    Route::patch('supply-categories/{id}/deactivate', [SupplyCategoryController::class, 'deactivate']);
    Route::patch('supply-categories/{id}/reactivate', [SupplyCategoryController::class, 'reactivate']);


    Route::patch('supplies/{id}/deactivate', [SupplyController::class, 'deactivate']);
    Route::patch('supplies/{id}/reactivate', [SupplyController::class, 'reactivate']);

    Route::get('supply-categories/{id}/next-code', [SupplyCategoryController::class, 'nextCode']);

    Route::get('/warehouses/{warehouseId}/stock', [InventoryMovementController::class, 'stockByWarehouse']);

    Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
    Route::post('/inventory-movements', [InventoryMovementController::class, 'store']);
    Route::get('/inventory-movements/{id}', [InventoryMovementController::class, 'show']);
    Route::put('/inventory-movements/{id}', [InventoryMovementController::class, 'update']);
    Route::delete('/inventory-movements/{id}', [InventoryMovementController::class, 'destroy']);

    // Órdenes de compra
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/next-code', [PurchaseOrderController::class, 'nextCode']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);

    Route::get('/supplies', [SupplyController::class, 'index']);

    // Motivos de Movimiento (catálogo de Ingreso/Egreso)
    Route::prefix('movement-reasons')->group(function () {
        Route::get('/', [MovementReasonController::class, 'index']);
        Route::post('/', [MovementReasonController::class, 'store']);
        Route::get('/{id}', [MovementReasonController::class, 'show']);
        Route::put('/{id}', [MovementReasonController::class, 'update']);
        Route::patch('/{id}/deactivate', [MovementReasonController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [MovementReasonController::class, 'reactivate']);
    });

});