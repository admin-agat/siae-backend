<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Crea el schema `terceros` y la tabla base `personas`.
     *
     * `personas` es la entidad única que representa a cualquier tercero
     * con el que AGAT-ECUAGREEN se relaciona: Productores, Comercializadoras,
     * Transportistas y Compradores (clientes internacionales).
     *
     * En lugar de tener una tabla por cada tipo de tercero (lo cual duplicaría
     * campos como RUC, dirección, contacto, forma de pago, etc.), se usa el
     * patrón de "Tabla de Terceros": una tabla base + flags booleanos de rol.
     * Esto permite que una misma persona/empresa tenga varios roles a la vez
     * (ej. una comercializadora que también actúa como transportista),
     * igual que se observó en el sistema Vástago Web que SIAE reemplaza.
     */
    public function up(): void
    {
        // Crear el schema dedicado a terceros, separado de comercial_internacional
        // (que es para transacciones/procesos) y de public (catálogos de producto).
        DB::statement('CREATE SCHEMA IF NOT EXISTS terceros');

        Schema::create('terceros.personas', function (Blueprint $table) {
            $table->id();

            // --- Identificación fiscal ---
            $table->string('tipo_identificacion', 20)
                ->comment('RUC, CEDULA, PASAPORTE');
            $table->string('identificacion', 20)
                ->comment('Número de RUC, cédula o pasaporte');

            // --- Datos generales ---
            $table->string('nombre', 255)
                ->comment('Razón social o nombre completo');
            $table->string('nombre_comercial', 255)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('celular', 30)->nullable();

            // --- Ubicación ---
            $table->string('pais', 100)->default('ECUADOR');
            $table->string('provincia', 100)->nullable();
            $table->string('ciudad', 100)->nullable();

            // --- Condiciones comerciales ---
            $table->string('forma_pago', 20)
                ->default('CREDITO')
                ->comment('CREDITO, CONTADO');
            $table->unsignedInteger('dias_credito')->nullable();
            $table->decimal('cupo_credito', 12, 2)->nullable();

            // Quién en AGAT gestiona la relación con este tercero
            $table->foreignId('vendedor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // --- Roles (una persona puede tener varios a la vez) ---
            $table->boolean('es_proveedor')->default(false)
                ->comment('Productor que entrega fruta directamente');
            $table->boolean('es_comercializadora')->default(false)
                ->comment('Intermediario entre AGAT y el productor (ej. AGASE, ATRAP)');
            $table->boolean('es_transportista')->default(false);
            $table->boolean('es_comprador')->default(false)
                ->comment('Cliente internacional (ej. Top Banana, World Banana)');

            // --- Estado ---
            // Regla de negocio del proyecto: nunca se elimina físicamente, solo se desactiva.
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->unique(['tipo_identificacion', 'identificacion']);
            $table->index('es_proveedor');
            $table->index('es_comercializadora');
            $table->index('es_transportista');
            $table->index('es_comprador');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terceros.personas');
        DB::statement('DROP SCHEMA IF EXISTS terceros CASCADE');
    }
};
