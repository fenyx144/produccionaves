<?php

/**
 * Funciones auxiliares globales de Producción Aves.
 */

if (!function_exists('pa_asset_url')) {
    function pa_asset_url(string $webPath): string
    {
        return sip_asset_url($webPath);
    }
}

if (!function_exists('pa_e')) {
    /** Escapar texto para HTML. */
    function pa_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pa_module_url')) {
    /** URL absoluta (desde la raíz web) hacia un archivo del proyecto. */
    function pa_module_url(string $relative): string
    {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $base . '/' . ltrim($relative, '/');
    }
}
