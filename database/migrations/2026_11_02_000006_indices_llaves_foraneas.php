<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 02/11/2026 (revisión de optimización): PostgreSQL no crea índice para las llaves foráneas y 93 no
// lo tenían. Se agregan los de las tablas que crecen cada semana y que se consultan unidas: líneas de
// movimientos (el stock se calcula con ellas), pedidos, cupos, embarques, tarjas y liquidaciones.
// Solo agrega índices: no cambia ningún dato. Se salta la columna que no exista en la base.
return new class extends Migration
{
    private const COLUMNAS = [
        'inventory_movement_lines' => ['inventory_movement_id', 'supply_id'],
        'inventory_movements'      => ['movement_reason_id', 'third_party_id', 'purchase_order_id', 'destination_warehouse_id', 'linked_movement_id'],
        'purchase_order_lines'     => ['purchase_order_id', 'supply_id'],
        'purchase_orders'          => ['third_party_id', 'warehouse_id'],
        'supply_price_history'     => ['supply_id'],
        'supplies'                 => ['supply_category_id'],
        'customer_order_lines'     => ['customer_order_id', 'customer_id', 'customer_contract_id'],
        'producer_quotas'          => ['customer_order_id'],
        'producer_quota_lines'     => ['producer_quota_id', 'producer_id', 'customer_id'],
        'shipments'                => ['vessel_id'],
        'shipment_lines'           => ['customer_order_line_id', 'booking_id'],
        'shipment_bookings'        => ['customer_id'],
        'purchase_blocks'          => ['shipment_id', 'customer_id'],
        'tally_sheets'             => ['shipment_id'],
        'settlements'              => ['third_party_id'],
        'settlement_lines'         => ['settlement_id', 'producer_quota_line_id'],
        'settlement_materials'     => ['settlement_id'],
        'settlement_advances'      => ['settlement_id', 'producer_advance_id'],
        'shipment_expenses'        => ['customer_id'],
        'customer_brand'           => ['brand_id'],
        'customer_contract_prices' => ['brand_id'],
        'users'                    => ['profile_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    DB::statement("CREATE INDEX IF NOT EXISTS {$tabla}_{$columna}_index ON {$tabla} ({$columna})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                DB::statement("DROP INDEX IF EXISTS {$tabla}_{$columna}_index");
            }
        }
    }
};
