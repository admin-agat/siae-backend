<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 10/11/2026 (pedido de Exportaciones y Logística): las 4 modalidades de carga en todo el sistema, con los
// mismos códigos del pedido semanal (customer_order_lines.cargo_type, que no cambia):
//   GRANEL_BAJO_CUBIERTA = GRANEL BAJO CUBIERTA      CAJA_BAJO_CUBIERTA = PALETIZADO BAJO CUBIERTA
//   GRANEL_CONTENEDOR    = CONTENEDOR AL GRANEL      CAJA_CONTENEDOR    = CONTENEDOR PALETIZADO
// Antes la compra (bloques) usaba BAJO_CUBIERTA/CONTENEDOR y el cierre (bookings) BODEGA/CONTENEDOR: los
// existentes pasan a la versión GRANEL (casi todo lo cargado es granel); se corrigen a mano si eran paletizado.
return new class extends Migration
{
    private const NUEVAS = "('GRANEL_BAJO_CUBIERTA','CAJA_BAJO_CUBIERTA','GRANEL_CONTENEDOR','CAJA_CONTENEDOR')";

    public function up(): void
    {
        foreach (['purchase_blocks' => 'BAJO_CUBIERTA', 'shipment_bookings' => 'BODEGA'] as $tabla => $bajoCubierta) {
            DB::statement("ALTER TABLE $tabla DROP CONSTRAINT IF EXISTS {$tabla}_cargo_mode_check");
            DB::statement("ALTER TABLE $tabla ALTER COLUMN cargo_mode TYPE varchar(25)");
            DB::table($tabla)->where('cargo_mode', $bajoCubierta)->update(['cargo_mode' => 'GRANEL_BAJO_CUBIERTA']);
            DB::table($tabla)->where('cargo_mode', 'CONTENEDOR')->update(['cargo_mode' => 'GRANEL_CONTENEDOR']);
            DB::statement("ALTER TABLE $tabla ALTER COLUMN cargo_mode SET DEFAULT 'GRANEL_BAJO_CUBIERTA'");
            DB::statement("ALTER TABLE $tabla ADD CONSTRAINT {$tabla}_cargo_mode_check CHECK (cargo_mode IN " . self::NUEVAS . ')');
        }
    }

    public function down(): void
    {
        foreach (['purchase_blocks' => 'BAJO_CUBIERTA', 'shipment_bookings' => 'BODEGA'] as $tabla => $bajoCubierta) {
            DB::statement("ALTER TABLE $tabla DROP CONSTRAINT IF EXISTS {$tabla}_cargo_mode_check");
            DB::table($tabla)->whereIn('cargo_mode', ['GRANEL_BAJO_CUBIERTA', 'CAJA_BAJO_CUBIERTA'])->update(['cargo_mode' => $bajoCubierta]);
            DB::table($tabla)->whereIn('cargo_mode', ['GRANEL_CONTENEDOR', 'CAJA_CONTENEDOR'])->update(['cargo_mode' => 'CONTENEDOR']);
            DB::statement("ALTER TABLE $tabla ALTER COLUMN cargo_mode TYPE varchar(15)");
            DB::statement("ALTER TABLE $tabla ALTER COLUMN cargo_mode SET DEFAULT '$bajoCubierta'");
            DB::statement("ALTER TABLE $tabla ADD CONSTRAINT {$tabla}_cargo_mode_check CHECK (cargo_mode IN ('$bajoCubierta','CONTENEDOR'))");
        }
    }
};
