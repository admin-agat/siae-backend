<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 11/11/2026 (pedido del usuario): los comerciales que compran la fruta salen de una lista que maneja ADMIN,
// en vez de texto libre. Una compra puede ser de uno o varios comerciales: se guarda en
// producer_quota_lines.executive como "MARIA ELENA / JORGE ZERNA" (por eso se amplía a 255).
return new class extends Migration
{
    private const INICIALES = ['MARIA ELENA', 'ROMAN KOROTKOV', 'JORGE ZERNA', 'HEYDI MENDOZA'];

    public function up(): void
    {
        Schema::create('purchase_executives', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        DB::table('purchase_executives')->insert(array_map(fn ($n) => [
            'name' => $n, 'status' => true, 'created_at' => now(), 'updated_at' => now(),
        ], self::INICIALES));

        DB::statement('ALTER TABLE producer_quota_lines ALTER COLUMN executive TYPE varchar(255)');
    }

    public function down(): void
    {
        DB::statement('UPDATE producer_quota_lines SET executive = LEFT(executive, 60) WHERE LENGTH(executive) > 60');
        DB::statement('ALTER TABLE producer_quota_lines ALTER COLUMN executive TYPE varchar(60)');
        Schema::dropIfExists('purchase_executives');
    }
};
