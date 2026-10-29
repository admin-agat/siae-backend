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
use App\Http\Controllers\Api\CustomerContractController;
use App\Http\Controllers\Api\CustomerOrderController;
use App\Http\Controllers\Api\ShipmentDistributionController;
use App\Http\Controllers\Api\PurchaseAllocationController;
use App\Http\Controllers\Api\TallySheetController;
use App\Http\Controllers\Api\ProducerAdvanceController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\ShipmentClosingController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| Rutas públicas — no requieren token
|--------------------------------------------------------------------------
*/
// throttle:5,1 → máximo 5 intentos por minuto por IP (frena que adivinen contraseñas)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Rutas protegidas — requieren token Sanctum
|--------------------------------------------------------------------------
| Permisos (28/10/2026): la LECTURA de catálogos queda abierta a cualquier
| usuario autenticado (los formularios la necesitan para sus selectores);
| la ESCRITURA se limita por rol con el middleware 'rol:' o 'admin':
|   rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO = todos menos BODEGUERO (= RutaNoBodeguero del frontend)
|   rol:ADMIN,COORDINADOR_INVENTARIO = ajustes que cambian el stock histórico
| throttle:120,1 → máximo 120 peticiones por minuto por usuario/IP.
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {


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

    // Solo ADMIN puede crear/editar/desactivar/reactivar Terceros y ver/editar Fincas
    Route::middleware('admin')->group(function () {
        // Fincas — 27/10/2026: sin destroy (DELETE). Baja lógica con PATCH, igual que Terceros.
        Route::apiResource('farms', FarmController::class)->except(['destroy']);
        Route::patch('/farms/{id}/deactivate', [FarmController::class, 'deactivate']);
        Route::patch('/farms/{id}/reactivate', [FarmController::class, 'reactivate']);

        // Terceros (escritura)
        Route::post('/third-parties', [ThirdPartyController::class, 'store']);
        Route::put('/third-parties/{id}', [ThirdPartyController::class, 'update']);
        Route::patch('/third-parties/{id}', [ThirdPartyController::class, 'update']);
        // 27/10/2026: se quitó Route::delete(... 'destroy'). Estándar del SIAE:
        // baja lógica reversible con PATCH deactivate/reactivate (igual que Bodegas).
        Route::patch('/third-parties/{id}/deactivate', [ThirdPartyController::class, 'deactivate']);
        Route::patch('/third-parties/{id}/reactivate', [ThirdPartyController::class, 'reactivate']);
    });

    /*
    |----------------------------------------------------------------
    | Bodegas
    |----------------------------------------------------------------
    */
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'show']);
    Route::middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO')->group(function () {
        Route::apiResource('warehouses', WarehouseController::class)->only(['store', 'update']);
        Route::patch('/warehouses/{id}/deactivate', [WarehouseController::class, 'deactivate']);
        Route::patch('/warehouses/{id}/reactivate', [WarehouseController::class, 'reactivate']);
    });
    // 28/10/2026: se quitó GET /warehouses/{id}/stock — apuntaba a stockByWarehouse(), que
    // no existe (daba 500). El stock por bodega se pide con GET /inventory/stock?warehouse_id=.

    // Marcas (GLOBAL VILLAGE, PALMS BANANAS, PALMS CON BANDA, DOÑA ELENA...)
    // Se crean solo por interfaz; su "code" es el que usan recetas y cupos.
    // Lectura abierta (Despacho de materiales); escritura solo ADMIN (están en CONFIGURACIÓN).
    Route::get('brands', [BrandController::class, 'index']);
    Route::middleware('admin')->group(function () {
        Route::post('brands', [BrandController::class, 'store']);
        Route::put('brands/{id}', [BrandController::class, 'update']);
        Route::patch('brands/{id}/deactivate', [BrandController::class, 'deactivate']);
        Route::patch('brands/{id}/reactivate', [BrandController::class, 'reactivate']);
    });

    /*
    |----------------------------------------------------------------
    | Insumos (Categorías + Supplies)
    |----------------------------------------------------------------
    */
    // 28/10/2026: sin DELETE (antes apiResource completo publicaba destroy, que no existe → 500)
    Route::apiResource('supply-categories', SupplyCategoryController::class)->only(['index', 'show']);
    Route::get('/supply-categories/{id}/next-code', [SupplyCategoryController::class, 'nextCode']);
    Route::apiResource('supplies', SupplyController::class)->only(['index', 'show']);

    Route::middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO')->group(function () {
        Route::apiResource('supply-categories', SupplyCategoryController::class)->only(['store', 'update']);
        Route::patch('/supply-categories/{id}/deactivate', [SupplyCategoryController::class, 'deactivate']);
        Route::patch('/supply-categories/{id}/reactivate', [SupplyCategoryController::class, 'reactivate']);

        Route::apiResource('supplies', SupplyController::class)->only(['store', 'update']);
        Route::patch('/supplies/{id}/deactivate', [SupplyController::class, 'deactivate']);
        Route::patch('/supplies/{id}/reactivate', [SupplyController::class, 'reactivate']);
    });

    /*
    |----------------------------------------------------------------
    | Motivos de Movimiento (catálogo de Ingreso/Egreso/Devolución)
    |----------------------------------------------------------------
    */
    Route::prefix('movement-reasons')->group(function () {
        Route::get('/', [MovementReasonController::class, 'index']);
        Route::get('/{id}', [MovementReasonController::class, 'show']);
        Route::post('/', [MovementReasonController::class, 'store'])->middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO');
        Route::put('/{id}', [MovementReasonController::class, 'update'])->middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO');
        Route::patch('/{id}/deactivate', [MovementReasonController::class, 'deactivate'])->middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO');
        Route::patch('/{id}/reactivate', [MovementReasonController::class, 'reactivate'])->middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO');
    });

    /*
    |----------------------------------------------------------------
    | Movimientos de Inventario
    |----------------------------------------------------------------
    | Rutas estáticas (/transfer, /pending-transfers) SIEMPRE antes de /{id}.
    */
    // 29/10/2026: movimientos, stock, despacho y órdenes de compra solo para los roles de
    // INVENTARIO (antes abiertos a cualquier usuario: EXPORTACIONES y COMERCIAL no los usan).
    Route::middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO,BODEGUERO')->group(function () {
    Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
    Route::post('/inventory-movements', [InventoryMovementController::class, 'store']);

    // Transferencia entre bodegas: EGRESO (origen) PENDIENTE → confirmación crea el INGRESO (destino)
    // 29/10/2026: semanas (cupos) a las que un productor puede devolver material
    Route::get('/inventory-movements/returnable-quotas/{thirdPartyId}', [InventoryMovementController::class, 'returnableQuotas']);
    Route::post('/inventory-movements/transfer', [InventoryMovementController::class, 'transfer']);
    Route::get('/inventory-movements/pending-transfers', [InventoryMovementController::class, 'pendingTransfers']);
    Route::post('/inventory-movements/{id}/confirm-transfer', [InventoryMovementController::class, 'confirmTransfer']);
    // NUEVO — cancelTransfer() existía en el controlador pero no tenía ruta
    Route::patch('/inventory-movements/{id}/cancel-transfer', [InventoryMovementController::class, 'cancelTransfer']);

    Route::get('/inventory-movements/{id}', [InventoryMovementController::class, 'show']);
    // Editar o anular un movimiento ya registrado cambia el stock histórico: solo
    // COORDINADOR_INVENTARIO y ADMIN (antes cualquier usuario autenticado podía hacerlo)
    Route::put('/inventory-movements/{id}', [InventoryMovementController::class, 'update'])->middleware('rol:ADMIN,COORDINADOR_INVENTARIO');
    Route::delete('/inventory-movements/{id}', [InventoryMovementController::class, 'destroy'])->middleware('rol:ADMIN,COORDINADOR_INVENTARIO');

    Route::get('/inventory/stock', [InventoryMovementController::class, 'stockGeneral']);

    /*
    |----------------------------------------------------------------
    | Despacho de Materiales (EGRESO por cupo — motivo ENTREGA A PRODUCTOR)
    |----------------------------------------------------------------
    | /calculate va ANTES de /{id} para que "calculate" no se tome como un id.
    */
    Route::prefix('material-dispatch')->group(function () {
        Route::post('/calculate', [MaterialDispatchController::class, 'calculate']);
        // Fase 3: cupos que Comercial asignó a un productor (para elegir en el despacho)
        Route::get('/quotas/{thirdPartyId}', [MaterialDispatchController::class, 'quotas']);
        Route::get('/', [MaterialDispatchController::class, 'index']);
        Route::post('/', [MaterialDispatchController::class, 'store']);
        Route::get('/{id}', [MaterialDispatchController::class, 'show']);
        Route::patch('/{id}/deactivate', [MaterialDispatchController::class, 'deactivate']);
    });

    // Recetas de materiales (BOM por marca). 29/10/2026: son CONFIGURACIÓN (Marcas y recetas)
    // → escritura solo ADMIN. La lectura sigue abierta: el Despacho de materiales la usa.
    Route::get('material-recipes/available-supplies', [MaterialRecipeController::class, 'availableSupplies']);
    Route::get('material-recipes', [MaterialRecipeController::class, 'index']);
    Route::middleware('admin')->group(function () {
        Route::post('material-recipes', [MaterialRecipeController::class, 'store']);
        Route::put('material-recipes/{id}', [MaterialRecipeController::class, 'update']);
        Route::patch('material-recipes/{id}/deactivate', [MaterialRecipeController::class, 'deactivate']);
        Route::patch('material-recipes/{id}/reactivate', [MaterialRecipeController::class, 'reactivate']);
    });

    /*
    |----------------------------------------------------------------
    | Órdenes de Compra
    |----------------------------------------------------------------
    */
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/next-code', [PurchaseOrderController::class, 'nextCode']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    // Cancelar OC: antes sin control de rol (un BODEGUERO podía cancelarla por API)
    Route::patch('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO');
    }); // fin roles de INVENTARIO

    /*
    |==================================================================
    | EXPORTACIONES
    |==================================================================
    */

    // Todo EXPORTACIONES: solo personal (ADMIN, JEFE_BODEGA, COORDINADOR_INVENTARIO), nunca BODEGUERO.
    // Antes cualquier usuario autenticado podía leer precios de contratos/pedidos o crear bookings.
    // Destinos, Clientes y Contratos (CONFIGURACIÓN): además, su escritura es solo ADMIN.
    // 29/10/2026: roles EXPORTACIONES y COMERCIAL. Todo el personal LEE; escribe:
    //   $exporta → pedido, barcos, navieras, bookings, cierre de embarque
    //   $compra  → compra de fruta (ADMIN y COMERCIAL)
    $exporta = 'rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO,EXPORTACIONES';
    $compra = 'rol:ADMIN,COMERCIAL';
    Route::middleware('rol:ADMIN,JEFE_BODEGA,COORDINADOR_INVENTARIO,EXPORTACIONES,COMERCIAL')->group(function () use ($exporta, $compra) {

        // Navieras
        Route::prefix('shipping-lines')->group(function () use ($exporta) {
            Route::get('/', [ShippingLineController::class, 'index']);
            Route::post('/', [ShippingLineController::class, 'store'])->middleware($exporta);
            Route::get('/{id}', [ShippingLineController::class, 'show']);
            Route::put('/{id}', [ShippingLineController::class, 'update'])->middleware($exporta);
            Route::patch('/{id}/deactivate', [ShippingLineController::class, 'deactivate'])->middleware($exporta);
            Route::patch('/{id}/reactivate', [ShippingLineController::class, 'reactivate'])->middleware($exporta);
        });

        // Barcos
        Route::prefix('vessels')->group(function () use ($exporta) {
            Route::get('/', [VesselController::class, 'index']);
            Route::get('/{vessel}', [VesselController::class, 'show']);
            Route::post('/', [VesselController::class, 'store'])->middleware($exporta);
            Route::put('/{vessel}', [VesselController::class, 'update'])->middleware($exporta);
            Route::patch('/{vessel}/deactivate', [VesselController::class, 'deactivate'])->middleware($exporta);
            Route::patch('/{vessel}/reactivate', [VesselController::class, 'reactivate'])->middleware($exporta);
        });

        // Bookings
        Route::prefix('bookings')->group(function () use ($exporta) {
            Route::get('/', [BookingController::class, 'index']);
            Route::post('/', [BookingController::class, 'store'])->middleware($exporta);
            Route::get('/{id}', [BookingController::class, 'show']);
            Route::put('/{id}', [BookingController::class, 'update'])->middleware($exporta);
            Route::patch('/{id}/deactivate', [BookingController::class, 'deactivate'])->middleware($exporta);
            Route::patch('/{id}/reactivate', [BookingController::class, 'reactivate'])->middleware($exporta);
        });

        // Puertos
        Route::prefix('ports')->group(function () {
            // 29/10/2026: Puertos es CONFIGURACIÓN → escritura solo ADMIN (lectura: el personal
            // la necesita en el pedido semanal y la distribución en barcos)
            Route::get('/', [PortController::class, 'index']);
            Route::post('/', [PortController::class, 'store'])->middleware('admin');
            Route::get('/{id}', [PortController::class, 'show']);
            Route::put('/{id}', [PortController::class, 'update'])->middleware('admin');
            Route::patch('/{id}/deactivate', [PortController::class, 'deactivate'])->middleware('admin');
            Route::patch('/{id}/reactivate', [PortController::class, 'reactivate'])->middleware('admin');
        });

        // Conceptos de tarifa por puerto (escritura solo ADMIN, igual que Puertos)
        Route::prefix('port-tariff-items')->group(function () {
            Route::get('/', [PortTariffItemController::class, 'index']);
            Route::post('/', [PortTariffItemController::class, 'store'])->middleware('admin');
            Route::get('/{id}', [PortTariffItemController::class, 'show']);
            Route::put('/{id}', [PortTariffItemController::class, 'update'])->middleware('admin');
            Route::patch('/{id}/deactivate', [PortTariffItemController::class, 'deactivate'])->middleware('admin');
            Route::patch('/{id}/reactivate', [PortTariffItemController::class, 'reactivate'])->middleware('admin');
            Route::get('/{id}/price-history', [PortTariffItemController::class, 'priceHistory']);
        });

        // Destinos
        Route::prefix('destinations')->group(function () {
            Route::get('/', [DestinationController::class, 'index']);
            Route::post('/', [DestinationController::class, 'store'])->middleware('admin');
            Route::put('/{id}', [DestinationController::class, 'update'])->middleware('admin');
            Route::patch('/{id}/deactivate', [DestinationController::class, 'deactivate'])->middleware('admin');
            Route::patch('/{id}/reactivate', [DestinationController::class, 'reactivate'])->middleware('admin');
        });

        // Clientes (Customers)
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::post('/', [CustomerController::class, 'store'])->middleware('admin');
            Route::get('/{id}', [CustomerController::class, 'show']);
            Route::put('/{id}', [CustomerController::class, 'update'])->middleware('admin');
            Route::patch('/{id}/deactivate', [CustomerController::class, 'deactivate'])->middleware('admin');
            Route::patch('/{id}/reactivate', [CustomerController::class, 'reactivate'])->middleware('admin');
        });

        // Contratos de CLIENTES (precio por caja y vigencia). No confundir con contracts (MAGAP productores)
        Route::prefix('customer-contracts')->group(function () {
            Route::get('/', [CustomerContractController::class, 'index']);
            Route::post('/', [CustomerContractController::class, 'store'])->middleware('admin');
            Route::put('/{id}', [CustomerContractController::class, 'update'])->middleware('admin');
            Route::patch('/{id}/deactivate', [CustomerContractController::class, 'deactivate'])->middleware('admin');
            Route::patch('/{id}/reactivate', [CustomerContractController::class, 'reactivate'])->middleware('admin');
        });

        // Pedido semanal de Rusia (cabecera + líneas). Un solo pedido por semana/año.
        Route::prefix('customer-orders')->group(function () use ($exporta, $compra) {
            Route::get('/', [CustomerOrderController::class, 'index']);
            Route::post('/', [CustomerOrderController::class, 'store'])->middleware($exporta);
            Route::get('/{id}', [CustomerOrderController::class, 'show']);
            Route::put('/{id}', [CustomerOrderController::class, 'update'])->middleware($exporta);
            Route::patch('/{id}/deactivate', [CustomerOrderController::class, 'deactivate'])->middleware($exporta);
            Route::patch('/{id}/reactivate', [CustomerOrderController::class, 'reactivate'])->middleware($exporta);
            // Fase 2: Logística reparte el pedido en barcos
            Route::get('/{id}/distribution', [ShipmentDistributionController::class, 'show']);
            Route::put('/{id}/distribution', [ShipmentDistributionController::class, 'update'])->middleware($exporta);
            // Fase 3: Comercial compra la fruta a productores/comercializadoras
            Route::get('/{id}/purchases', [PurchaseAllocationController::class, 'show']);
            Route::put('/{id}/purchases', [PurchaseAllocationController::class, 'update'])->middleware($compra);
            // 29/10/2026: cierre de embarque por booking (lo realmente embarcado + documentos)
            Route::get('/{id}/closing', [ShipmentClosingController::class, 'show']);
            Route::put('/{id}/closing', [ShipmentClosingController::class, 'update'])->middleware($exporta);
        });

        // Embarques (Shipments)
        Route::prefix('shipments')->group(function () use ($exporta) {
            Route::get('/', [ShipmentController::class, 'index']);
            Route::get('/next-code', [ShipmentController::class, 'nextCode']);
            Route::post('/', [ShipmentController::class, 'store'])->middleware($exporta);
            Route::get('/{id}', [ShipmentController::class, 'show']);
            Route::put('/{id}', [ShipmentController::class, 'update'])->middleware($exporta);
            Route::patch('/{id}/deactivate', [ShipmentController::class, 'deactivate'])->middleware($exporta);
            Route::patch('/{id}/reactivate', [ShipmentController::class, 'reactivate'])->middleware($exporta);
        });

        Route::prefix('shipment-lines')->group(function () use ($exporta) {
            Route::get('/', [ShipmentLineController::class, 'index']);
            Route::post('/', [ShipmentLineController::class, 'store'])->middleware($exporta);
            Route::put('/{id}', [ShipmentLineController::class, 'update'])->middleware($exporta);
            Route::delete('/{id}', [ShipmentLineController::class, 'destroy'])->middleware($exporta);
        });
    });

    /*
    |==================================================================
    | USUARIOS (CONFIGURACIÓN, 29/10/2026) — solo ADMIN
    |==================================================================
    */
    Route::middleware('admin')->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::patch('/{id}/deactivate', [UserController::class, 'deactivate']);
        Route::patch('/{id}/reactivate', [UserController::class, 'reactivate']);
    });

    /*
    |==================================================================
    | LIQUIDACIÓN (29/10/2026) — dinero al productor: solo ADMIN
    |==================================================================
    | Tarjas (lo recibido en puerto), anticipos/préstamos y liquidaciones con pagos 100 % u 80/20.
    */
    Route::middleware('admin')->group(function () {
        Route::get('customer-orders/{id}/tallies', [TallySheetController::class, 'show']);
        Route::put('customer-orders/{id}/tallies', [TallySheetController::class, 'update']);

        Route::prefix('producer-advances')->group(function () {
            Route::get('/', [ProducerAdvanceController::class, 'index']);
            Route::post('/', [ProducerAdvanceController::class, 'store']);
            Route::put('/{id}', [ProducerAdvanceController::class, 'update']);
            Route::patch('/{id}/deactivate', [ProducerAdvanceController::class, 'deactivate']);
            Route::patch('/{id}/reactivate', [ProducerAdvanceController::class, 'reactivate']);
        });

        Route::prefix('settlements')->group(function () {
            Route::get('/quotas', [SettlementController::class, 'quotas']);
            Route::get('/preview/{quotaId}', [SettlementController::class, 'preview']);
            Route::get('/', [SettlementController::class, 'index']);
            Route::post('/', [SettlementController::class, 'store']);
            Route::get('/{id}', [SettlementController::class, 'show']);
            Route::patch('/{id}/payments/{installment}/pay', [SettlementController::class, 'pay']);
            Route::patch('/{id}/payments/{installment}/unpay', [SettlementController::class, 'unpay']);
            Route::patch('/{id}/annul', [SettlementController::class, 'annul']);
        });
    });

    // SKU/Recetas, Marca BL, Invoice: pendientes de construir (controladores
    // ya importados arriba). Se agregan sus rutas aquí mismo cuando se
    // implementen, siguiendo el mismo patrón prefix()->group() de arriba.

});