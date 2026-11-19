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
    // 02/11/2026: el Dashboard era solo de gerencia ('solo' = únicos tipos base que podían tenerla).
    // 11/11/2026: pasa a ser el Tablero de operaciones con datos reales. No muestra dinero, así que también lo
    // ven Exportaciones y Comercial (se quita 'solo'). La clave sigue siendo 'dashboard' para no tocar perfiles.
    'dashboard'            => ['seccion' => 'PRINCIPAL', 'nombre' => 'Tablero de operaciones', 'roles' => ['GERENCIA', 'EXPORTACIONES', 'COMERCIAL']],

    'pedido_semanal'       => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Pedido semanal', 'roles' => ['EXPORTACIONES']],
    'distribucion_barcos'  => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Distribución en barcos', 'roles' => ['EXPORTACIONES']],
    'cierre_embarque'      => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Cierre de embarque', 'roles' => ['EXPORTACIONES']],
    'navieras'             => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Navieras', 'roles' => ['EXPORTACIONES']],

    'compra_fruta'         => ['seccion' => 'COMERCIAL', 'nombre' => 'Compra de fruta', 'roles' => ['COMERCIAL']],
    // 19/11/2026 (fase 1b): permiso para pulsar PAGAR (anticipo 80 % SPOT) en Compra de fruta. En ningún perfil:
    // el ADMIN se lo da a la asistente comercial como excepción por persona.
    'pagar_anticipo_80'    => ['seccion' => 'COMERCIAL', 'nombre' => 'Pedir anticipo 80 % (permiso)', 'roles' => [], 'solo' => ['COMERCIAL']],
    'precios_mag'          => ['seccion' => 'COMERCIAL', 'nombre' => 'Precios oficiales MAG', 'roles' => ['COMERCIAL']],

    'bodegas'              => ['seccion' => 'INVENTARIO', 'nombre' => 'Bodegas', 'roles' => $inventario],
    'insumos'              => ['seccion' => 'INVENTARIO', 'nombre' => 'Insumos por categoría', 'roles' => $inventario],
    'motivos'              => ['seccion' => 'INVENTARIO', 'nombre' => 'Motivos de movimiento', 'roles' => $inventario],
    'stock'                => ['seccion' => 'INVENTARIO', 'nombre' => 'Stock general', 'roles' => [...$inventario, 'BODEGUERO']],
    'nuevo_movimiento'     => ['seccion' => 'INVENTARIO', 'nombre' => 'Nuevo movimiento', 'roles' => [...$inventario, 'BODEGUERO']],
    'despacho'             => ['seccion' => 'INVENTARIO', 'nombre' => 'Despacho de materiales', 'roles' => [...$inventario, 'BODEGUERO']],
    'historial_despachos'  => ['seccion' => 'INVENTARIO', 'nombre' => 'Historial de despachos', 'roles' => [...$inventario, 'BODEGUERO']],
    // 31/10/2026: historial de movimientos y kárdex (corregir/anular: solo COORDINADOR_INVENTARIO y ADMIN)
    'movimientos'          => ['seccion' => 'INVENTARIO', 'nombre' => 'Movimientos y kárdex', 'roles' => [...$inventario, 'BODEGUERO']],
    'transferencias'       => ['seccion' => 'INVENTARIO', 'nombre' => 'Transferencias pendientes', 'roles' => ['COORDINADOR_INVENTARIO']],
    // 31/10/2026: el bodeguero confirma lo que llega a SU bodega (las de otras bodegas no las ve)
    'recibir_transferencias' => ['seccion' => 'INVENTARIO', 'nombre' => 'Recibir transferencias', 'roles' => ['BODEGUERO']],
    'ordenes_compra'       => ['seccion' => 'INVENTARIO', 'nombre' => 'Órdenes de compra', 'roles' => $inventario],
    // 03/11/2026: no es una pantalla, es un permiso: ver costos y valores (costo promedio, valor a descontar,
    // kárdex valorizado). Sin él, bodegueros y despachadores solo ven cantidades (la API tampoco los envía).
    'ver_costos'           => ['seccion' => 'INVENTARIO', 'nombre' => 'Ver costos y valores (permiso)', 'roles' => $inventario],

    // 06/11/2026: el Departamento de Liquidaciones (tipo LIQUIDACIONES) lleva toda la sección
    'parametros_liquidacion' => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Parámetros de la semana y tabla de retención', 'roles' => ['LIQUIDACIONES']],
    // 12/11/2026: Liquidaciones ya no mete contratos (los registra LEGAL): edita la retención y ve los contratos
    'contratos_productores' => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Retención de productores (ve los contratos)', 'roles' => ['LIQUIDACIONES']],
    // 18/11/2026 (fase 2b): completa la ficha de productores y comercializadoras (cédula si falta, zona, teléfono,
    // correo, dirección) sin entrar a Terceros. Permiso aparte para poder quitárselo a una persona.
    'ficha_vendedores'     => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Ficha de vendedores (completar datos)', 'roles' => ['LIQUIDACIONES']],
    // 17/11/2026: Tarjas se muestra en EXPORTACIONES (el rol por defecto sigue siendo LIQUIDACIONES)
    'tarjas'               => ['seccion' => 'EXPORTACIONES', 'nombre' => 'Tarjas', 'roles' => ['LIQUIDACIONES', 'TARJADOR']], // 17/11/2026: + TARJADOR (puerto)
    'anticipos'            => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Anticipos y préstamos', 'roles' => ['LIQUIDACIONES']],
    'liquidar'             => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Liquidar', 'roles' => ['LIQUIDACIONES']],
    'pagos'                => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Pagos e historial', 'roles' => ['LIQUIDACIONES']],
    // 19/11/2026 (fase 1b): el jefe de Liquidaciones aprueba las órdenes (excepción por persona, P3); Gerencia y
    // Finanzas ven el tablero sin cuentas ni botones (P19)
    'aprobar_pagos_liquidacion' => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Aprobar anticipos y pagos (jefe)', 'roles' => [], 'solo' => ['LIQUIDACIONES']],
    'tablero_pagos_lectura' => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Tablero de anticipos y pagos (solo lectura)', 'roles' => ['GERENCIA', 'FINANZAS']],
    'notas_credito'        => ['seccion' => 'LIQUIDACIÓN', 'nombre' => 'Notas de crédito', 'roles' => ['LIQUIDACIONES']],

    // 12/11/2026: Departamento LEGAL: contratos de productores (cupo, precio, fechas…) y cajas registradas en el MAG
    'contratos_legal'      => ['seccion' => 'LEGAL', 'nombre' => 'Contratos de productores', 'roles' => ['LEGAL']],
    // 16/11/2026: vacaciones fase 1 — ficha de empleados, kárdex y saldo de vacaciones, departamentos y feriados
    'empleados_saldos'     => ['seccion' => 'LEGAL', 'nombre' => 'Empleados y saldos de vacaciones', 'roles' => ['LEGAL']],

    // 02/11/2026: gastos de cada viaje (los registra Finanzas) y ganancia por viaje
    'gastos_viaje'         => ['seccion' => 'FINANZAS', 'nombre' => 'Gastos del viaje', 'roles' => ['FINANZAS']],
    'ganancia_viaje'       => ['seccion' => 'FINANZAS', 'nombre' => 'Ganancia por viaje', 'roles' => ['FINANZAS', 'GERENCIA']],
    // 16/11/2026: tablero financiero (suma de Ganancia por viaje por semanas, cliente y barco)
    'tablero_financiero'   => ['seccion' => 'FINANZAS', 'nombre' => 'Tablero financiero', 'roles' => ['FINANZAS', 'GERENCIA']],

    // 05/11/2026: OC de cada departamento → Contabilidad aprueba (solo ella) y genera el TXT de pagos del banco
    'oc_departamento'      => ['seccion' => 'COMPRAS', 'nombre' => 'Órdenes de compra del departamento', 'roles' => ['DEPARTAMENTO']],
    // 19/11/2026 (fase 1b): los lotes al banco pasan a Cuentas por pagar; aquí solo se aprueban las OC
    'aprobar_oc'           => ['seccion' => 'CONTABILIDAD', 'nombre' => 'Aprobación de OC', 'roles' => ['CONTABILIDAD']],
    // 19/11/2026 (fase 1b, 5.5): preparar el lote y el TXT, y confirmar lo que pagó el banco (permisos separados)
    'pagos_banco'          => ['seccion' => 'CONTABILIDAD', 'nombre' => 'Cuentas por pagar (lote y TXT)', 'roles' => ['CONTABILIDAD'], 'solo' => ['CONTABILIDAD']],
    'confirmar_pagos'      => ['seccion' => 'CONTABILIDAD', 'nombre' => 'Confirmar pagos del banco (permiso)', 'roles' => ['CONTABILIDAD'], 'solo' => ['CONTABILIDAD']],
    'cuentas_bancarias'    => ['seccion' => 'CONTABILIDAD', 'nombre' => 'Cuentas bancarias de proveedores', 'roles' => ['CONTABILIDAD']],

    'terceros'             => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Terceros (maestro)', 'roles' => []],
    'fincas'               => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Fincas', 'roles' => []],
    'destinos'             => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Destinos', 'roles' => []],
    'contratos_clientes'   => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Contratos de clientes', 'roles' => []],
    'marcas'               => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Marcas y recetas', 'roles' => []],
    'puertos'              => ['seccion' => 'CONFIGURACIÓN', 'nombre' => 'Puertos', 'roles' => []],
];
