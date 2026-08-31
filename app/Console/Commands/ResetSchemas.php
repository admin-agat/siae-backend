<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetSchemas extends Command
{
    protected $signature = 'db:reset-schemas';
    protected $description = 'Borra y recrea todos los schemas custom del proyecto SIAE, luego corre migrate:fresh';

    /**
     * Lista única y centralizada de schemas del proyecto.
     * Si agregan un schema nuevo en el futuro, solo se edita esta lista.
     */
    protected array $schemas = [
        'terceros',
        'contratos',
        'inventario',
        'comercial_internacional',
        'bodega',
        'comercial',
        'liquidacion',
        'auditoria',
    ];

    public function handle(): int
    {
        $this->info('Borrando schemas existentes...');
        foreach ($this->schemas as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
            $this->line("  - DROP {$schema}");
        }

        $this->info('Creando schemas limpios...');
        foreach ($this->schemas as $schema) {
            DB::statement("CREATE SCHEMA {$schema}");
            $this->line("  - CREATE {$schema}");
        }

        $this->info('Schemas listos. Ejecutando migrate:fresh...');
        $this->call('migrate:fresh');

        $this->info('Listo. Base de datos reseteada con todos los schemas sincronizados.');
        return self::SUCCESS;
    }
}