<?php

// Migración: módulos CONTRATOS y PEDIDOS (Exportaciones)
// Crea 3 tablas nuevas, en este orden (cada una depende de la anterior):
//   1. customer_contracts    → contrato de VENTA de un cliente: vigencia (inicio/fin) + precio por caja
//   2. customer_orders       → cabecera del pedido semanal que envía Rusia (semana/año)
//   3. customer_order_lines  → renglones del correo (cliente, cajas, incoterm, carga, puerto, modalidad, precio)
// Se usa migración porque son tablas nuevas grandes (regla del proyecto).
// NO confundir con la tabla contracts (2025): contratos MAGAP con PRODUCTORES, no se toca.
// Esta versión REEMPLAZA a las dos migraciones anteriores de hoy (no usar esas).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ================= 1. CONTRATOS DE CLIENTES =================
        Schema::create('customer_contracts', function (Blueprint $table) {
            $table->id();

            // Número del contrato en papel (si lo tiene); opcional
            $table->string('contract_number', 50)->nullable();

            // Cliente de Rusia dueño del contrato (GT, RVI...)
            $table->foreignId('customer_id')->constrained('customers');

            // Incoterm al que aplica el precio. NULL = aplica a cualquier incoterm.
            // Si el cliente tiene precio FOB y otro CFR, se registran 2 contratos con la misma vigencia.
            $table->string('incoterm', 10)->nullable();

            // Vigencia del contrato (ej. 6 meses)
            $table->date('start_date');
            $table->date('end_date');

            // Precio pactado por caja (USD). numeric(10,2) → hasta 99 999 999.99
            $table->decimal('box_price', 10, 2);

            // Volumen semanal comprometido (opcional; si no se pacta, queda NULL)
            $table->unsignedInteger('weekly_boxes')->nullable();

            $table->text('comment')->nullable();

            // Baja lógica (deactivate/reactivate), nunca DELETE
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // Índice para buscar rápido "el contrato vigente de este cliente en esta fecha"
            $table->index(['customer_id', 'start_date', 'end_date']);
        });

        // La fecha fin no puede ser anterior a la de inicio (lo valida la base, no solo el frontend)
        DB::statement("ALTER TABLE customer_contracts ADD CONSTRAINT customer_contracts_dates_check
            CHECK (end_date >= start_date)");

        // ================= 2. PEDIDOS (cabecera) =================
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();

            // Semana bananera (ISO 8601) y año. Ej: semana 37 / 2026
            $table->unsignedSmallInteger('week');
            $table->unsignedSmallInteger('year');

            // Fecha en que llegó el correo de Rusia
            $table->date('received_at')->nullable();

            $table->text('comment')->nullable();
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            // Un solo pedido por semana: las correcciones de Rusia se EDITAN, no se duplican
            $table->unique(['week', 'year']);
        });

        // ================= 3. PEDIDOS (líneas) =================
        Schema::create('customer_order_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_order_id')->constrained('customer_orders')->cascadeOnDelete();

            // Cliente de Rusia (GT, RVI...)
            $table->foreignId('customer_id')->constrained('customers');

            // Cajas pedidas en este renglón
            $table->unsignedInteger('boxes');

            // FOB / CFR / CIF
            $table->string('incoterm', 10);

            // Mismas 4 combinaciones que shipment_lines.type (sesión 31)
            $table->string('cargo_type', 30);

            // Solo para contenedor: "9 * 1200" → containers_qty = 9, boxes_per_container = 1200
            $table->unsignedInteger('containers_qty')->nullable();
            $table->unsignedInteger('boxes_per_container')->nullable();

            // Puerto de salida en Ecuador (GUAYAQUIL / PUERTO BOLÍVAR)
            $table->foreignId('departure_port_id')->nullable()->constrained('ports');

            // CONTRATO / SPOT
            $table->string('negotiation_type', 20);

            // Si es CONTRATO: contrato vigente del que sale el precio. Si es SPOT: NULL.
            $table->foreignId('customer_contract_id')->nullable()->constrained('customer_contracts');

            // Precio por caja CONGELADO en la línea:
            //   CONTRATO → se copia del contrato al guardar
            //   SPOT     → se escribe a mano (puede llegar después, por eso nullable)
            // Se copia para que, si el contrato cambia, los pedidos ya registrados conserven su precio.
            $table->decimal('box_price', 10, 2)->nullable();

            // Número de pedido: Rusia lo manda después ("Mas tarde les paso los números")
            $table->string('order_number', 50)->nullable();

            // Detalles sin columna propia (ej. "CON LA CINTA PACKED")
            $table->string('notes', 255)->nullable();

            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // Valores permitidos (la base rechaza textos mal escritos)
        DB::statement("ALTER TABLE customer_order_lines ADD CONSTRAINT customer_order_lines_cargo_type_check
            CHECK (cargo_type IN ('GRANEL_BAJO_CUBIERTA','GRANEL_CONTENEDOR','CAJA_BAJO_CUBIERTA','CAJA_CONTENEDOR'))");

        DB::statement("ALTER TABLE customer_order_lines ADD CONSTRAINT customer_order_lines_negotiation_type_check
            CHECK (negotiation_type IN ('CONTRATO','SPOT'))");
    }

    public function down(): void
    {
        // Orden inverso: primero lo que depende de otras tablas
        Schema::dropIfExists('customer_order_lines');
        Schema::dropIfExists('customer_orders');
        Schema::dropIfExists('customer_contracts');
    }
};
