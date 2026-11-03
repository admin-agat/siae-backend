<?php

namespace App\Support;

// 03/11/2026: revisa que una cédula (10 dígitos) o un RUC (13) de Ecuador esté bien formado.
// Persona natural: dígito verificador de la cédula (módulo 10), confiable. Sociedades y sector
// público: solo la estructura (provincia, tercer dígito, establecimiento), porque el SRI emite
// RUC nuevos que no cumplen el módulo 11 y no se debe rechazar un RUC real.
// Detecta errores de tipeo como 7042007323001 (provincia 70) o un dígito cambiado en una cédula.
class IdentificacionEcuador
{
    /** Devuelve el problema encontrado, o null si está bien. */
    public static function error(?string $numero): ?string
    {
        $n = preg_replace('/\s+/', '', (string) $numero);
        if (!preg_match('/^\d{10}(\d{3})?$/', $n)) {
            return 'La cédula debe tener 10 dígitos y el RUC 13 (solo números).';
        }
        $provincia = (int) substr($n, 0, 2);
        if (!(($provincia >= 1 && $provincia <= 24) || $provincia === 30)) {
            return "Los dos primeros dígitos ({$provincia}) no son una provincia válida.";
        }
        $tercero = (int) $n[2];
        if ($tercero <= 5) {
            if (!self::cedulaValida(substr($n, 0, 10))) {
                return 'El dígito verificador de la cédula no coincide: revisa el número.';
            }
            if (strlen($n) === 13 && substr($n, 10) === '000') {
                return 'El RUC de persona natural termina en 001 (o en el número de su establecimiento).';
            }
            return null;
        }
        if (strlen($n) === 10) {
            return 'Ese número es de una sociedad o institución: debe ser el RUC de 13 dígitos.';
        }
        if ($tercero === 6 || $tercero === 9) {
            return substr($n, -3) === '000' ? 'El RUC no puede terminar en 000.' : null;
        }

        return "El tercer dígito ({$tercero}) no corresponde a una cédula ni a un RUC.";
    }

    private static function cedulaValida(string $c): bool
    {
        $suma = 0;
        for ($i = 0; $i < 9; $i++) {
            $v = (int) $c[$i] * ($i % 2 === 0 ? 2 : 1);
            $suma += $v > 9 ? $v - 9 : $v;
        }
        $verificador = (10 - $suma % 10) % 10;

        return $verificador === (int) $c[9];
    }
}
