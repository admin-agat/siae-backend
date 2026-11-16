<?php

// 16/11/2026: VACACIONES Y PERMISOS (Código del Trabajo, Art. 69 y 74). Valores de las respuestas de Peter
// del 16/11/2026; si LEGAL dice otra cosa se cambian aquí sin tocar el código.
return [
    // 15 días por año de servicio + 1 adicional por cada año desde el año indicado, hasta 15 adicionales
    'dias_base'               => 15,
    'anio_desde_adicional'    => 5,   // P1: desde el 5.º año cumplido (16 días en el año 5)
    'max_adicionales'         => 15,
    // P8: aviso a LEGAL cuando hay días ganados hace más de estos años sin gozar
    'anios_acumulacion_aviso' => 3,
];
