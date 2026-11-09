<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 09/11/2026 (revisión de seguridad, etapa 2): pulir la base.
// - Índice en cada llave foránea que no tenía (Postgres no los crea solo; sin ellos, filtrar o borrar
//   por esa columna recorre toda la tabla). Las de las tablas viejas que se eliminan no van aquí.
// - Se quitan 2 índices repetidos (los cubre otro índice que empieza por las mismas columnas).
// - CHECK en columnas que solo admiten una lista cerrada de valores (la misma lista que valida el código).
return new class extends Migration
{
    private const INDICES = [
        'bank_payment_batches'          => ['created_by', 'paid_by'],
        'brands'                        => ['box_type_id'],
        'contracts'                     => ['updated_by'],
        'customer_contracts'            => ['created_by'],
        'customer_order_lines'          => ['brand_id', 'departure_port_id'],
        'customer_orders'               => ['created_by'],
        'inventory_movements'           => ['created_by_user_id'],
        'official_box_prices'           => ['created_by'],
        'port_tariff_item_price_history' => ['changed_by', 'port_tariff_item_id'],
        'port_tariff_items'             => ['port_id'],
        'producer_advances'             => ['created_by'],
        'producer_quota_lines'          => ['brand_id', 'contract_id', 'delivery_port_id', 'farm_id', 'pickup_warehouse_id'],
        'producer_quotas'               => ['created_by'],
        'purchase_blocks'               => ['brand_id', 'departure_port_id'],
        'purchase_orders'               => ['approved_by', 'bank_payment_batch_id', 'created_by'],
        'settlement_materials'          => ['supply_id'],
        'settlement_payments'           => ['paid_by'],
        'settlement_week_parameters'    => ['created_by', 'updated_by'],
        'settlements'                   => ['created_by', 'documents_by'],
        'shipment_bookings'             => ['brand_id', 'created_by'],
        'shipment_costs'                => ['updated_by'],
        'shipment_expenses'             => ['created_by', 'supplier_id'],
        'shipment_lines'                => ['destination_port_id'],
        'shipments'                     => ['created_by', 'departure_port_id', 'destination_port_id', 'shipping_line_id'],
        'supply_price_history'          => ['changed_by'],
        'tally_sheets'                  => ['created_by'],
        'third_party_bank_accounts'     => ['bank_id', 'updated_by'],
        'third_party_supplies'          => ['supply_id'],
        'user_warehouses'               => ['warehouse_id'],
        'users'                         => ['boss_id'],
        'vessels'                       => ['shipping_line_id'],
        'warehouses'                    => ['responsible_user_id'],
    ];

    // índice repetido => [tabla, columnas] (para recrearlo en down)
    private const REPETIDOS = [
        'third_parties_type_index'         => ['third_parties', ['type']],
        'shipment_lines_shipment_id_index' => ['shipment_lines', ['shipment_id']],
    ];

    private function checks(): array
    {
        $lista = fn (array $v) => "'" . implode("','", $v) . "'";

        return [
            'profiles_base_role_check'                  => ['profiles', 'base_role IN (' . $lista(\App\Models\Profile::TIPOS_BASE) . ')'],
            'inventory_movements_transfer_status_check' => ['inventory_movements', "transfer_status IS NULL OR transfer_status IN ('PENDIENTE','CONFIRMADA','CANCELADA')"],
            'customers_negotiation_type_check'          => ['customers', "negotiation_type IS NULL OR negotiation_type IN ('CONTRATO','SPOT')"],
            'shipment_lines_negotiation_type_check'     => ['shipment_lines', "negotiation_type IS NULL OR negotiation_type IN ('CONTRATO','SPOT')"],
            'settlement_lines_negotiation_type_check'   => ['settlement_lines', "negotiation_type IS NULL OR negotiation_type IN ('CONTRATO','SPOT')"],
        ];
    }

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $columnas) {
            foreach ($columnas as $col) {
                if (Schema::hasColumn($tabla, $col) && !Schema::hasIndex($tabla, [$col])) {
                    Schema::table($tabla, fn ($t) => $t->index($col));
                }
            }
        }

        foreach (array_keys(self::REPETIDOS) as $indice) {
            DB::statement("DROP INDEX IF EXISTS $indice");
        }

        foreach ($this->checks() as $nombre => [$tabla, $condicion]) {
            DB::statement("ALTER TABLE $tabla DROP CONSTRAINT IF EXISTS $nombre");
            DB::statement("ALTER TABLE $tabla ADD CONSTRAINT $nombre CHECK ($condicion)");
        }
    }

    public function down(): void
    {
        foreach ($this->checks() as $nombre => [$tabla]) {
            DB::statement("ALTER TABLE $tabla DROP CONSTRAINT IF EXISTS $nombre");
        }

        foreach (self::REPETIDOS as $indice => [$tabla, $columnas]) {
            DB::statement("CREATE INDEX IF NOT EXISTS $indice ON $tabla (" . implode(', ', $columnas) . ')');
        }

        foreach (self::INDICES as $tabla => $columnas) {
            foreach ($columnas as $col) {
                DB::statement('DROP INDEX IF EXISTS ' . "{$tabla}_{$col}_index");
            }
        }
    }
};
