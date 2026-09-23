<?php

declare(strict_types=1);

/**
 * Hojas de estilo de listados (tabla/iconos) con cache-bust ?v=SIP_ASVER.
 * Uso en <head> de dashboards con data-vista-tabla-iconos.
 */
require_once __DIR__ . '/sip_asset_version.php';

if (!function_exists('sip_listado_css_href')) {
    function sip_listado_css_href(string $cssFile): string
    {
        $sn = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
        $idx = stripos($sn, '/modules/');
        if ($idx === false) {
            return sip_asset_url('assets/css/' . ltrim($cssFile, '/'));
        }
        $rest = substr($sn, $idx + strlen('/modules/'));
        $parts = array_values(array_filter(explode('/', $rest), static function ($p) {
            return $p !== '';
        }));
        if ($parts !== []) {
            array_pop($parts);
        }
        $ups = count($parts) + 1;
        $prefix = str_repeat('../', max(1, $ups));

        return sip_asset_url($prefix . 'assets/css/' . ltrim($cssFile, '/'));
    }
}

if (!function_exists('sip_echo_listado_stylesheet_links')) {
    function sip_echo_listado_stylesheet_links(): void
    {
        $files = [
            'dashboard-vista-tabla-iconos.css',
            'dashboard-responsive.css',
            'dashboard-config.css',
        ];
        foreach ($files as $file) {
            $href = htmlspecialchars(sip_listado_css_href($file), ENT_QUOTES, 'UTF-8');
            echo '<link rel="stylesheet" href="' . $href . '">' . "\n    ";
        }
    }
}
