<?php

// 18/11/2026 (fase 2b): ajustes de la liquidación que Peter decide sin tocar el código.
return [
    // Bloquear el registro del pago de una LQ si el vendedor no tiene cuenta bancaria o cédula/RUC.
    // Peter (18/11/2026): se sube APAGADO; mientras tanto solo se muestra el aviso. Se enciende (true)
    // cuando Liquidaciones haya cargado las cuentas de los vendedores.
    'bloquear_pago_sin_datos' => false,
];
