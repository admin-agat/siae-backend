<?php

// Migración Fase 1 del flujo de PEDIDOS (28/10/2026)
//   1. customer_brand → marcas de cada cliente (muchos a muchos):
//      GT usa GLOBAL VILLAGE y DOÑA ELENA; RVI usa PALMS BANANA y DOÑA ELENA.
//      Rusia no escribe la marca en el correo: la define Comercial al comprar la fruta,
//      escogiendo entre las marcas de este cliente.
//   2. ports → columnas code y city (el modelo/modal ya las usaban pero NO existían:
//      crear un puerto con código daba error SQL) y port_type SALIDA / DESTINO,
//      para separar los puertos de Ecuador (salida del pedido) de los de destino.
//   3. Se registran los puertos de salida GUAYAQUIL y PUERTO BOLÍVAR.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ================= 1. MARCAS POR CLIENTE =================
        Schema::create('customer_brand', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->timestamps();

            // La misma marca no se repite para el mismo cliente
            $table->unique(['customer_id', 'brand_id']);
        });

        // ================= 2. PUERTOS =================
        Schema::table('ports', function (Blueprint $table) {
            if (!Schema::hasColumn('ports', 'code')) {
                $table->string('code', 50)->nullable();
            }
            if (!Schema::hasColumn('ports', 'city')) {
                $table->string('city', 255)->nullable();
            }
            // Los puertos que ya existen (ST PETERSBURG) son de destino
            $table->string('port_type', 10)->default('DESTINO');
        });

        DB::statement("ALTER TABLE ports ADD CONSTRAINT ports_port_type_check
            CHECK (port_type IN ('SALIDA','DESTINO'))");

        // ================= 3. PUERTOS DE SALIDA =================
        foreach ([
            ['name' => 'GUAYAQUIL', 'code' => 'GYE', 'city' => 'GUAYAQUIL'],
            ['name' => 'PUERTO BOLÍVAR', 'code' => 'PBO', 'city' => 'MACHALA'],
        ] as $puerto) {
            if (!DB::table('ports')->where('name', $puerto['name'])->exists()) {
                DB::table('ports')->insert($puerto + [
                    'country' => 'ECUADOR',
                    'port_type' => 'SALIDA',
                    'status' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Solo se borran los puertos de salida si ningún pedido los usa todavía
        DB::table('ports')
            ->whereIn('name', ['GUAYAQUIL', 'PUERTO BOLÍVAR'])
            ->whereNotIn('id', DB::table('customer_order_lines')->whereNotNull('departure_port_id')->select('departure_port_id'))
            ->delete();

        DB::statement('ALTER TABLE ports DROP CONSTRAINT IF EXISTS ports_port_type_check');
        Schema::table('ports', function (Blueprint $table) {
            $table->dropColumn(['port_type', 'code', 'city']);
        });

        Schema::dropIfExists('customer_brand');
    }
};
