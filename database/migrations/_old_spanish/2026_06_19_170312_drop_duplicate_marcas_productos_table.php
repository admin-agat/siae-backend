<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eliminamos las tablas duplicadas creadas por error en comercial_internacional.
        // El catálogo real de marcas y tipos de caja ya existe en el schema public
        // (public.marcas y public.tipos_caja), con datos reales desde el 2026-06-10.

        // Primero productos, porque tiene la FK hacia marcas
        Schema::dropIfExists('comercial_internacional.productos');
        Schema::dropIfExists('comercial_internacional.marcas');
    }

    public function down(): void
    {
        // No revertimos: estas tablas fueron un error de duplicación,
        // no queremos recrearlas accidentalmente con un rollback.
    }
};