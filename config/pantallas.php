<?php

// Pantallas del sistema que se pueden dar o quitar a un perfil o a una persona (30/10/2026).
// Ubicación: config/pantallas.php
//
// Permiso por pantalla: quien la tiene, la ve y puede hacer todo en ella. La API se protege con
// el middleware 'pantalla:' (routes/api.php) y el frontend arma el menú con la misma clave.
// Usuarios, Perfiles y Bitácora NO están aquí: son solo del ADMIN y no se delegan.
// 'roles' = tipos base que la tienen en su perfil inicial (lo que cada rol veía antes).
$inventario = ['JEFE_BODEGA', 'COORDINADOR_INVENTARIO'];

return [
    'dashboard'            => ['seccion' => 'PRINCIPAL', 'nombre' => 'Dashboard', 'roles' => $inventario],

    'pedido_semanal'       => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Pedido semanal', 'roles' => ['EXPORTACIONES']],
    'distribucion_barcos'  => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Distribución en barcos', 'roles' => ['EXPORTACIONES']],
    'cierre_embarque'      => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Cierre de embarque', 'roles' => ['EXPORTACIONES']],
    'navieras'             => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Navieras', 'roles' => ['EXPORTACIONES']],
    'bookings'             => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Bookings', 'roles' => ['EXPORTACIONES']],

    'compra_fruta'         => ['seccion' => 'COMERCIAL', 'nombre' => 'Compra de fruta', 'roles' => ['COMERCIAL']],
    'precios_mag'          => ['seccion' => 'COMERCIAL', 'nombre' => 'Precios oficiales MAG', 'roles' => ['COMERCIAL']],

    'bodegas'              => ['seccion' => 'INVENTARIO', 'nombre' => 'Bodegas', 'roles' => $inventario],
    'insumos'              => ['seccion' => 'INVENTARIO', 'nombre' => 'Insumos por categoría', 'roles' => $inventario],
    'motivos'              => ['seccion' => 'INVENTARIO', 'nombre' => 'Motivos de movimiento', 'roles' => $inventario],
    'stock'                => ['seccion' => 'INVENTARIO', 'nombre' => 'Stock general', 'roles' => [...$inventario, 'BODEGUERO']],
    'nuevo_movimiento'     => ['seccion' => 'INVENTARIO', 'nombre' => 'Nuevo movimiento', 'roles' => [...$inventario, 'BODEGUERO']],
    'despacho'             => ['seccion' => 'INVENTARIO', 'nombre' => 'Despacho de materiales', 'roles' => [...$inventario, 'BODEGUERO']],
    'historial_despachos'  => ['seccion' => 'INVENTARIO', 'nombre' => 'Historial de despachos', 'roles' => [...$inventario, 'BODEGUERO']],
    'transferencias'       => ['seccion' => 'INVENTARIO', 'nombre' => 'Transferencias pendientes', 'roles' => ['COORDINADOR_INVENTARIO']],
    // 31/10/2026: el bodeguero confirma lo que llega a SU bodega (las de otras bodegas no las ve)
    'recibir_transferencias' => ['seccion' => 'INVENTARIO', 'nombre' => 'Recibir transferencias', 'roles' => ['BODEGUERO']],
    'ordenes_compra'       => ['seccion' => 'INVENTARIO', 'nombre' => 'Órdenes de compra', 'roles' => $inventario],

    'tarjas'               => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Tarjas', 'roles' => []],
    'anticipos'            => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Anticipos y préstamos', 'roles' => []],
    'liquidar'             => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Liquidar', 'roles' => []],
    'pagos'                => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Pagos e historial', 'roles' => []],
    'notas_credito'        => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Notas de crédito', 'roles' => []],

    'terceros'             => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Terceros (maestro)', 'roles' => []],
    'comercializadoras'    => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Comercializadoras', 'roles' => []],
    'fincas'               => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Fincas', 'roles' => []],
    'clientes'             => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Clientes', 'roles' => []],
    'destinos'             => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Destinos', 'roles' => []],
    'contratos_clientes'   => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Contratos de clientes', 'roles' => []],
    'marcas'               => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Marcas y recetas', 'roles' => []],
    'puertos'              => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Puertos', 'roles' => []],
];
