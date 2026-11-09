<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

// Bitácora automática (30/10/2026): todo modelo con este trait deja en audit_logs quién lo creó,
// editó (campo por campo: antes → después), desactivó/reactivó o borró, con fecha y hora.
// OJO: las escrituras masivas (Modelo::where(...)->update/delete) NO disparan eventos de Eloquent;
// en los controladores se hacen registro por registro (->get()->each->delete()) para que queden aquí.
trait Auditable
{
    // Campos que no se registran (o que se registran sin mostrar su valor)
    protected static array $auditarIgnorar = ['created_at', 'updated_at', 'remember_token'];
    protected static array $auditarOcultar = ['password'];
    // 09/11/2026 (revisión de seguridad): se registran solo con los últimos 4 dígitos (****1234)
    protected static array $auditarEnmascarar = ['account_number'];

    public static function bootAuditable(): void
    {
        static::created(function (Model $m) {
            AuditLog::registrar('CREAR', $m->getTable(), $m->getKey(), static::auditarValores($m->getAttributes(), $m));
        });

        static::updated(function (Model $m) {
            $cambios = [];
            foreach ($m->getChanges() as $campo => $nuevo) {
                if (in_array($campo, static::$auditarIgnorar, true)) {
                    continue;
                }
                $cambios[$campo] = match (true) {
                    in_array($campo, static::$auditarOcultar, true)    => ['***', '*** (CAMBIADA)'],
                    in_array($campo, static::$auditarEnmascarar, true) => [AuditLog::enmascarar($m->getRawOriginal($campo)), AuditLog::enmascarar($nuevo)],
                    default => [static::auditarJson($m, $campo, $m->getRawOriginal($campo)), static::auditarJson($m, $campo, $nuevo)],
                };
            }
            if (!$cambios) {
                return;
            }
            // Solo cambió el estado: es una desactivación o reactivación
            $accion = 'EDITAR';
            if (array_keys($cambios) === ['status']) {
                $accion = filter_var($cambios['status'][1], FILTER_VALIDATE_BOOLEAN) ? 'REACTIVAR' : 'DESACTIVAR';
            }
            AuditLog::registrar($accion, $m->getTable(), $m->getKey(), $cambios);
        });

        static::deleted(function (Model $m) {
            AuditLog::registrar('BORRAR', $m->getTable(), $m->getKey(), static::auditarValores($m->getRawOriginal(), $m));
        });
    }

    private static function auditarValores(array $valores, Model $m): array
    {
        $salida = [];
        foreach ($valores as $campo => $valor) {
            if (in_array($campo, static::$auditarIgnorar, true) || $valor === null || $valor === '') {
                continue;
            }
            $salida[$campo] = match (true) {
                in_array($campo, static::$auditarOcultar, true)    => '***',
                in_array($campo, static::$auditarEnmascarar, true) => AuditLog::enmascarar($valor),
                default => static::auditarJson($m, $campo, $valor),
            };
        }

        return $salida;
    }

    // Los campos JSON (ej. pantallas de un perfil) llegan como texto: se guardan como lista para leerlos en la bitácora
    private static function auditarJson(Model $m, string $campo, $valor)
    {
        return is_string($valor) && $m->hasCast($campo, ['array', 'json', 'object', 'collection'])
            ? json_decode($valor, true)
            : $valor;
    }
}
