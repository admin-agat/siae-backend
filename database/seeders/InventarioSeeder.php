<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra datos iniciales del módulo de Inventario:
 *   - Bodegas (1 principal + regionales)
 *   - Motivos de movimiento (ingreso/egreso)
 *   - Tipos, Grupos y Líneas de producto (catálogos planos e independientes)
 *
 * Uso: php artisan db:seed --class=Database\\Seeders\\InventarioSeeder
 *
 * NOTA sobre bodegas: solo Machala, El Triunfo y San Juan están
 * confirmadas con el negocio real. Las demás (004-010) quedan como
 * placeholder "BODEGA REGIONAL N" — edítalas con los nombres/zonas
 * reales cuando los tengas.
 */
class InventarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrarBodegas();
        $this->sembrarMotivosMovimiento();
        $this->sembrarTiposProducto();
        $this->sembrarGruposProducto();
        $this->sembrarLineasProducto();
    }

    private function sembrarBodegas(): void
    {
        $bodegas = [
            ['codigo' => '001', 'nombre' => 'BODEGA PRINCIPAL', 'zona' => null, 'es_principal' => true],
            ['codigo' => '002', 'nombre' => 'BODEGA MACHALA', 'zona' => 'Machala', 'es_principal' => false],
            ['codigo' => '003', 'nombre' => 'BODEGA EL TRIUNFO', 'zona' => 'El Triunfo', 'es_principal' => false],
            ['codigo' => '004', 'nombre' => 'BODEGA SAN JUAN', 'zona' => 'San Juan', 'es_principal' => false],
            // Placeholders - confirmar nombres/zonas reales con la operación
            ['codigo' => '005', 'nombre' => 'BODEGA REGIONAL 5', 'zona' => null, 'es_principal' => false],
            ['codigo' => '006', 'nombre' => 'BODEGA REGIONAL 6', 'zona' => null, 'es_principal' => false],
            ['codigo' => '007', 'nombre' => 'BODEGA REGIONAL 7', 'zona' => null, 'es_principal' => false],
            ['codigo' => '008', 'nombre' => 'BODEGA REGIONAL 8', 'zona' => null, 'es_principal' => false],
            ['codigo' => '009', 'nombre' => 'BODEGA REGIONAL 9', 'zona' => null, 'es_principal' => false],
            ['codigo' => '010', 'nombre' => 'BODEGA REGIONAL 10', 'zona' => null, 'es_principal' => false],
        ];

        foreach ($bodegas as $bodega) {
            DB::table('terceros.bodegas')->updateOrInsert(
                ['codigo' => $bodega['codigo']],
                array_merge($bodega, ['activo' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        $this->command->info('Bodegas sembradas: ' . count($bodegas));
    }

    private function sembrarMotivosMovimiento(): void
    {
        $motivos = [
            // Ingreso
            ['nombre' => 'Compra a proveedor', 'tipo_permitido' => 'ingreso', 'requiere_persona' => true],
            ['nombre' => 'Ajuste positivo de inventario', 'tipo_permitido' => 'ingreso', 'requiere_persona' => false],
            ['nombre' => 'Devolución de cliente', 'tipo_permitido' => 'ingreso', 'requiere_persona' => true],

            // Egreso
            ['nombre' => 'Despacho a productor', 'tipo_permitido' => 'egreso', 'requiere_persona' => true],
            ['nombre' => 'Consumo propio', 'tipo_permitido' => 'egreso', 'requiere_persona' => false],
            ['nombre' => 'Ajuste negativo de inventario', 'tipo_permitido' => 'egreso', 'requiere_persona' => false],
            ['nombre' => 'Merma', 'tipo_permitido' => 'egreso', 'requiere_persona' => false],
            ['nombre' => 'Transferencia a otra bodega', 'tipo_permitido' => 'egreso', 'requiere_persona' => false],
        ];

        foreach ($motivos as $motivo) {
            DB::table('inventario.motivos_movimiento')->updateOrInsert(
                ['nombre' => $motivo['nombre']],
                array_merge($motivo, ['activo' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        $this->command->info('Motivos de movimiento sembrados: ' . count($motivos));
    }

    private function sembrarTiposProducto(): void
    {
        $tipos = [
            ['nombre' => 'PRODUCTOS TERMINADOS', 'maneja_inventario' => true],
            ['nombre' => 'SERVICIOS', 'maneja_inventario' => false],
        ];

        foreach ($tipos as $tipo) {
            DB::table('inventario.tipos_producto')->updateOrInsert(
                ['nombre' => $tipo['nombre']],
                array_merge($tipo, ['activo' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        $this->command->info('Tipos de producto sembrados: ' . count($tipos));
    }

    private function sembrarGruposProducto(): void
    {
        // Catálogo plano, basado en los 7 ejemplos reales confirmados
        $grupos = [
            'MATERIAL POST COSECHA',
            'CARTÓN',
            'MATERIAL DE PALETIZADO Y CONTENEDOR',
            'MATERIAL CIERRE CONTENEDOR',
        ];

        foreach ($grupos as $nombre) {
            DB::table('inventario.grupos_producto')->updateOrInsert(
                ['nombre' => $nombre],
                ['activo' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->command->info('Grupos de producto sembrados: ' . count($grupos));
    }

    private function sembrarLineasProducto(): void
    {
        // Catálogo plano e independiente (sin FK a grupo), confirmado
        // en la corrección de hoy
        $lineas = [
            'QUIMICOS',
            'TAPAS',
            'PALETIZADO',
            'CONTENEDOR',
        ];

        foreach ($lineas as $nombre) {
            DB::table('inventario.lineas_producto')->updateOrInsert(
                ['nombre' => $nombre],
                ['activo' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->command->info('Líneas de producto sembradas: ' . count($lineas));
    }
}
