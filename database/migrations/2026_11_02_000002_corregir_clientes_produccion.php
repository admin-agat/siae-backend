<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 02/11/2026: dos cambios que se hicieron a mano en la base local y nunca tuvieron migración,
// por eso en producción fallaba crear clientes:
//   - customers.negotiation_type opcional (desde 28/10 la modalidad va en cada línea, no en el cliente)
//   - third_parties.address (dirección del tercero, la usa la ficha de Terceros)
// No hace nada donde ya están bien.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customers ALTER COLUMN negotiation_type DROP NOT NULL');

        if (!Schema::hasColumn('third_parties', 'address')) {
            Schema::table('third_parties', function (Blueprint $table) {
                $table->string('address')->nullable();
            });
        }
    }

    public function down(): void
    {
        // No se revierte: volver a exigir negotiation_type rompería los clientes creados sin él.
    }
};
