<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Persona;

/**
 * Comando de migración de datos: compradores -> personas.
 *
 * Copia cada registro de comercial_internacional.compradores hacia
 * terceros.personas con es_comprador = true, preservando todos los
 * datos posibles. No borra ni modifica la tabla compradores original
 * (queda intacta como respaldo hasta que se confirme que todo migró bien).
 *
 * Mapeo de columnas (columnas reales confirmadas en la BD):
 *   compradores.codigo            -> no tiene equivalente directo en personas
 *                                     (se guarda en observaciones internamente
 *                                     vía el campo nombre_comercial si aplica)
 *   compradores.nombre            -> personas.nombre
 *   compradores.pais              -> personas.pais
 *   compradores.ciudad            -> personas.ciudad
 *   compradores.contacto_nombre   -> no existe campo directo en personas;
 *                                     se concatena en direccion como referencia
 *                                     (ver nota abajo)
 *   compradores.contacto_email    -> personas.email
 *   compradores.contacto_telefono -> personas.telefono
 *   compradores.activo            -> personas.activo
 *
 * NOTA: personas no tiene tipo_identificacion/identificacion obligatorios
 * conocidos para estos registros (compradores no tenía RUC). Se usa
 * tipo_identificacion = 'PASAPORTE' e identificacion = codigo del comprador
 * como placeholder único, ya que personas.identificacion es NOT NULL + UNIQUE.
 * Esto debe revisarse y corregirse manualmente si se cuenta con el RUC/Tax ID
 * real de cada comprador internacional.
 *
 * Uso:
 *   php artisan migrar:compradores-a-personas          (modo prueba, no guarda)
 *   php artisan migrar:compradores-a-personas --commit  (ejecuta de verdad)
 */
class MigrarCompradoresAPersonas extends Command
{
    protected $signature = 'migrar:compradores-a-personas {--commit : Ejecuta la migración de verdad. Sin esto, solo simula.}';

    protected $description = 'Migra los registros de comercial_internacional.compradores hacia terceros.personas (es_comprador = true)';

    public function handle(): int
    {
        $compradores = DB::table('comercial_internacional.compradores')->get();

        if ($compradores->isEmpty()) {
            $this->info('No hay registros en compradores. Nada que migrar.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$compradores->count()} compradores para migrar.");

        $modoPrueba = !$this->option('commit');
        if ($modoPrueba) {
            $this->warn('MODO PRUEBA: no se guardará nada. Usa --commit para ejecutar de verdad.');
        }

        $creados = 0;
        $omitidos = 0;

        foreach ($compradores as $comprador) {
            // Evitar duplicados si ya existe una persona con el mismo "código"
            // usado como identificación placeholder
            $yaExiste = Persona::where('tipo_identificacion', 'PASAPORTE')
                ->where('identificacion', $comprador->codigo ?? "COMP-{$comprador->id}")
                ->exists();

            if ($yaExiste) {
                $this->line("Omitido (ya existe): {$comprador->nombre}");
                $omitidos++;
                continue;
            }

            $datos = [
                'tipo_identificacion' => 'PASAPORTE', // placeholder, ver nota en el comentario de clase
                'identificacion' => $comprador->codigo ?? "COMP-{$comprador->id}",
                'nombre' => $comprador->nombre,
                'nombre_comercial' => null,
                'direccion' => $comprador->contacto_nombre
                    ? "Contacto: {$comprador->contacto_nombre}"
                    : null,
                'email' => $comprador->contacto_email,
                'telefono' => $comprador->contacto_telefono,
                'celular' => null,
                'pais' => $comprador->pais,
                'provincia' => null,
                'ciudad' => $comprador->ciudad,
                'forma_pago' => 'CREDITO',
                'es_comprador' => true,
                'activo' => $comprador->activo ?? true,
            ];

            $this->line("Migrando: {$comprador->nombre} ({$comprador->pais})");

            if (!$modoPrueba) {
                Persona::create($datos);
            }

            $creados++;
        }

        $this->newLine();
        $this->info("Resumen: {$creados} para crear, {$omitidos} omitidos (ya existentes).");

        if ($modoPrueba) {
            $this->warn('Esto fue una simulación. Corre con --commit para aplicar de verdad.');
        } else {
            $this->info('Migración completada. La tabla compradores original no fue modificada.');
        }

        return self::SUCCESS;
    }
}