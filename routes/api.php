<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MaterialRecipeController;
use App\Http\Controllers\Api\MaterialDispatchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ThirdPartyController;
use App\Http\Controllers\Api\FarmController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\SupplyCategoryController;
use App\Http\Controllers\Api\SupplyController;
use App\Http\Controllers\Api\MovementReasonController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\PurchaseOrderController;

use App\Http\Controllers\Api\VesselController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\ShipmentLineController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ShippingLineController;
use App\Http\Controllers\Api\PortController;
use App\Http\Controllers\Api\PortTariffItemController;
use App\Http\Controllers\Api\DestinationController;
use App\Http\Controllers\Api\CustomerController;

// Aún sin controlador implementado (submódulos "Pronto" en el sidebar) —
// se dejan importados para cuando se construyan, así no hay que tocar
// este bloque de imports otra vez.
use App\Http\Controllers\Api\SkuController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\ShipmentTypeController;
use App\Http\Controllers\Api\BoxWeightController;
use App\Http\Controllers\Api\BoxTypeController;
use App\Http\Controllers\Api\PackageTypeController;
use App\Http\Controllers\Api\StickerTypeController;

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

    /*
    |----------------------------------------------------------------
    | Terceros
    |----------------------------------------------------------------
    | Lectura abierta a cualquier rol autenticado (la necesita el
    | formulario de Nuevo Movimiento y el Despacho de Materiales para
    | el selector Proveedor/Productor). Escritura restringida a ADMIN.
    */
    Route::get('/third-parties', [ThirdPartyController::class, 'index']);
    Route::get('/third-parties/{id}', [ThirdPartyController::class, 'show']);

    // Solo ADMIN puede crear/editar/eliminar Terceros y ver/editar Fincas
    Route::middleware('admin')->group(function () {
        Route::apiResource('farms', FarmController::class);
        Route::post('/third-parties', [ThirdPartyController::class, 'store']);
        Route::put('/third-parties/{id}', [ThirdPartyController::class, 'update']);
        Route::patch('/third-parties/{id}', [ThirdPartyController::class, 'update']);
        Route::delete('/third-parties/{id}', [ThirdPartyController::class, 'destroy']);
    });

    /*
    |----------------------------------------------------------------
    | Bodegas
    |----------------------------------------------------------------
    */
    Route::apiResource('warehouses', WarehouseController::class)->except(['destroy']);
    Route::patch('/warehouses/{id}/deactivate', [WarehouseController::class, 'deactivate']);
    Route::patch('/warehouses/{id}/reactivate', [WarehouseController::class, 'reactivate']);
    // PENDIENTE: el método stockByWarehouse() NO existe en InventoryMovementController
    // (da 500 si se llama). Se resuelve en una sesión aparte.
    Route::get('/warehouses/{warehouseId}/stock', [InventoryMovementController::class, 'stockByWarehouse']);

    // Marcas (GLOBAL VILLAGE, PALMS BANANAS, PALMS CON BANDA, DOÑA ELENA...)
    // Se crean solo por interfaz; su "code" es el que usan recetas y cupos.
    Route::get('brands', [BrandController::class, 'index']);
    Route::post('brands', [BrandController::class, 'store']);
    Route::put('brands/{id}', [BrandController::class, 'update']);
    Route::patch('brands/{id}/deactivate', [BrandController::class, 'deactivate']);
    Route::patch('brands/{id}/reactivate', [BrandController::class, 'reactivate']);

    /*
    |----------------------------------------------------------------
    | Insumos (Categorías + Supplies)
    |----------------------------------------------------------------
    */
    Route::apiResource('supply-categories', SupplyCategoryController::class);
    Route::patch('/supply-categories/{id}/deactivate', [SupplyCategoryController::class, 'deactivate']);
    Route::patch('/supply-categories/{id}/reactivate', [SupplyCategoryController::class, 'reactivate']);
    Route::get('/supply-categories/{id}/next-code', [SupplyCategoryController::class, 'nextCode']);

    Route::apiResource('supplies', SupplyController::class);
    Route::patch('/supplies/{id}/deactivate', [SupplyController::class, 'deactivate']);
    Route::patch('/supplies/{id}/reactivate', [SupplyController::class, 'reactivate']);

    /*
    |----------------------------------------------------------------
    | Motivos de Movimiento (catálogo de Ingreso/Egreso/Devolución)
    |----------------------------------------------------------------
    */
    Route::prefix('movement-reasons')->group(function () {
        Route::get('/', [MovementReasonController::class, 'index']);
        Route::post('/', [MovementReasonController::class, 'store']);
        Route::get('/{id}', [MovementReasonController::class, 'show']);
        Route::put('/{id}', [MovementReasonController::class, 'update']);
        Route::patch('/{id}/deactivate', [MovementReasonController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [MovementReasonController::class, 'reactivate']);
    });

    /*
    |----------------------------------------------------------------
    | Movimientos de Inventario
    |----------------------------------------------------------------
    | Rutas estáticas (/transfer, /pending-transfers) SIEMPRE antes de /{id}.
    */
    Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
    Route::post('/inventory-movements', [InventoryMovementController::class, 'store']);

    // Transferencia entre bodegas: EGRESO (origen) PENDIENTE → confirmación crea el INGRESO (destino)
    Route::post('/inventory-movements/transfer', [InventoryMovementController::class, 'transfer']);
    Route::get('/inventory-movements/pending-transfers', [InventoryMovementController::class, 'pendingTransfers']);
    Route::post('/inventory-movements/{id}/confirm-transfer', [InventoryMovementController::class, 'confirmTransfer']);
    // NUEVO — cancelTransfer() existía en el controlador pero no tenía ruta
    Route::patch('/inventory-movements/{id}/cancel-transfer', [InventoryMovementController::class, 'cancelTransfer']);

    Route::get('/inventory-movements/{id}', [InventoryMovementController::class, 'show']);
    Route::put('/inventory-movements/{id}', [InventoryMovementController::class, 'update']);
    Route::delete('/inventory-movements/{id}', [InventoryMovementController::class, 'destroy']);

    Route::get('/inventory/stock', [InventoryMovementController::class, 'stockGeneral']);

    /*
    |----------------------------------------------------------------
    | Despacho de Materiales (EGRESO por cupo — motivo ENTREGA A PRODUCTOR)
    |----------------------------------------------------------------
    | /calculate va ANTES de /{id} para que "calculate" no se tome como un id.
    */
    Route::prefix('material-dispatch')->group(function () {
        Route::post('/calculate', [MaterialDispatchController::class, 'calculate']);
        Route::get('/', [MaterialDispatchController::class, 'index']);
        Route::post('/', [MaterialDispatchController::class, 'store']);
        Route::get('/{id}', [MaterialDispatchController::class, 'show']);
        Route::patch('/{id}/deactivate', [MaterialDispatchController::class, 'deactivate']);
    });

    // Recetas de materiales (BOM por marca)
    Route::get('material-recipes/available-supplies', [MaterialRecipeController::class, 'availableSupplies']);
    Route::get('material-recipes', [MaterialRecipeController::class, 'index']);
    Route::post('material-recipes', [MaterialRecipeController::class, 'store']);
    Route::put('material-recipes/{id}', [MaterialRecipeController::class, 'update']);
    Route::patch('material-recipes/{id}/deactivate', [MaterialRecipeController::class, 'deactivate']);
    Route::patch('material-recipes/{id}/reactivate', [MaterialRecipeController::class, 'reactivate']);

    /*
    |----------------------------------------------------------------
    | Órdenes de Compra
    |----------------------------------------------------------------
    */
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/next-code', [PurchaseOrderController::class, 'nextCode']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::patch('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);

    /*
    |==================================================================
    | EXPORTACIONES
    |==================================================================
    */

    // Navieras
    Route::prefix('shipping-lines')->group(function () {
        Route::get('/', [ShippingLineController::class, 'index']);
        Route::post('/', [ShippingLineController::class, 'store']);
        Route::get('/{id}', [ShippingLineController::class, 'show']);
        Route::put('/{id}', [ShippingLineController::class, 'update']);
        Route::patch('/{id}/deactivate', [ShippingLineController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [ShippingLineController::class, 'reactivate']);
    });

    // Barcos
    Route::prefix('vessels')->group(function () {
        Route::get('/', [VesselController::class, 'index']);
        Route::get('/{vessel}', [VesselController::class, 'show']);
        Route::post('/', [VesselController::class, 'store']);
        Route::put('/{vessel}', [VesselController::class, 'update']);
        Route::patch('/{vessel}/deactivate', [VesselController::class, 'deactivate']);
        Route::patch('/{vessel}/reactivate', [VesselController::class, 'reactivate']);
    });

    // Bookings
    Route::prefix('bookings')->group(function () {
        Route::get('/', [BookingController::class, 'index']);
        Route::post('/', [BookingController::class, 'store']);
        Route::get('/{id}', [BookingController::class, 'show']);
        Route::put('/{id}', [BookingController::class, 'update']);
        Route::patch('/{id}/deactivate', [BookingController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [BookingController::class, 'reactivate']);
    });

    // Puertos
    Route::prefix('ports')->group(function () {
        Route::get('/', [PortController::class, 'index']);
        Route::post('/', [PortController::class, 'store']);
        Route::get('/{id}', [PortController::class, 'show']);
        Route::put('/{id}', [PortController::class, 'update']);
        Route::patch('/{id}/deactivate', [PortController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [PortController::class, 'reactivate']);
    });

    // Conceptos de tarifa por puerto
    Route::prefix('port-tariff-items')->group(function () {
        Route::get('/', [PortTariffItemController::class, 'index']);
        Route::post('/', [PortTariffItemController::class, 'store']);
        Route::get('/{id}', [PortTariffItemController::class, 'show']);
        Route::put('/{id}', [PortTariffItemController::class, 'update']);
        Route::patch('/{id}/deactivate', [PortTariffItemController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [PortTariffItemController::class, 'reactivate']);
        Route::get('/{id}/price-history', [PortTariffItemController::class, 'priceHistory']);
    });

    // Destinos
    Route::prefix('destinations')->group(function () {
        Route::get('/', [DestinationController::class, 'index']);
        Route::post('/', [DestinationController::class, 'store']);
        Route::put('/{id}', [DestinationController::class, 'update']);
        Route::patch('/{id}/deactivate', [DestinationController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [DestinationController::class, 'reactivate']);
    });

    // Clientes (Customers)
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::put('/{id}', [CustomerController::class, 'update']);
        Route::patch('/{id}/deactivate', [CustomerController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [CustomerController::class, 'reactivate']);
    });

    // Embarques (Shipments)
    Route::prefix('shipments')->group(function () {
        Route::get('/', [ShipmentController::class, 'index']);
        Route::get('/next-code', [ShipmentController::class, 'nextCode']);
        Route::post('/', [ShipmentController::class, 'store']);
        Route::get('/{id}', [ShipmentController::class, 'show']);
        Route::put('/{id}', [ShipmentController::class, 'update']);
        Route::patch('/{id}/deactivate', [ShipmentController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [ShipmentController::class, 'reactivate']);
    });

    Route::prefix('shipment-lines')->group(function () {
        Route::get('/', [ShipmentLineController::class, 'index']);
        Route::post('/', [ShipmentLineController::class, 'store']);
        Route::put('/{id}', [ShipmentLineController::class, 'update']);
        Route::delete('/{id}', [ShipmentLineController::class, 'destroy']);
    });

    // SKU/Recetas, Marca BL, Invoice: pendientes de construir (controladores
    // ya importados arriba). Se agregan sus rutas aquí mismo cuando se
    // implementen, siguiendo el mismo patrón prefix()->group() de arriba.

});