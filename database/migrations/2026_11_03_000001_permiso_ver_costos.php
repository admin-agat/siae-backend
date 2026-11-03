<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 03/11/2026: nuevo permiso "Ver costos y valores" (ver_costos). Para que nadie pierda lo que ya veía,
// se les da a los perfiles que manejan insumos u órdenes de compra (jefe de bodega, coordinador).
// Los bodegueros no lo tienen: solo ven cantidades.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('profiles')->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (in_array('ver_costos', $pantallas) || !array_intersect(['insumos', 'ordenes_compra'], $pantallas)) {
                continue;
            }
            $pantallas[] = 'ver_costos';
            DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode($pantallas)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('profiles')->get(['id', 'screens']) as $p) {
            $pantallas = json_decode($p->screens ?? '[]', true) ?: [];
            if (in_array('ver_costos', $pantallas)) {
                DB::table('profiles')->where('id', $p->id)->update(['screens' => json_encode(array_values(array_diff($pantallas, ['ver_costos'])))]);
            }
        }
    }
};
