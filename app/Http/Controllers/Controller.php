<?php

namespace App\Http\Controllers;

abstract class Controller
{
    // 03/11/2026: campos de dinero que no se envían a quien no tiene el permiso "Ver costos y valores"
    // (bodegueros y despachadores solo ven cantidades).
    private const CAMPOS_COSTO = [
        'cost', 'unit_cost', 'total', 'discount', 'valor_total', 'costo_promedio', 'costo_inicial', 'valor_saldo',
        'valor_inicial', 'valor_final', 'costo_promedio_actual', 'ultima_compra', 'average_cost', 'last_purchase_cost', 'unit_price', 'subtotal',
    ];

    /**
     * Devuelve los datos tal cual si el usuario puede ver costos; si no, con los campos de dinero en null.
     */
    protected function sinCostos($usuario, $datos)
    {
        if (!$usuario || $usuario->puede('ver_costos')) {
            return $datos;
        }
        $limpiar = function ($v) use (&$limpiar) {
            if (!is_array($v)) {
                return $v;
            }
            foreach ($v as $k => $x) {
                $v[$k] = is_string($k) && in_array($k, self::CAMPOS_COSTO, true) ? null : $limpiar($x);
            }
            return $v;
        };
        return $limpiar(json_decode(json_encode($datos), true));
    }
}
