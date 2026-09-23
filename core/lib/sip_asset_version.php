<?php

declare(strict_types=1);

if (!defined('SIP_ASVER')) {
    $sipAsverRoot = dirname(dirname(__DIR__));
    $sipAsverCandidates = [
        $sipAsverRoot . DIRECTORY_SEPARATOR . 'index.php',
        $sipAsverRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'login.php',
        $sipAsverRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'output.css',
        $sipAsverRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'dashboard-config.css',
        $sipAsverRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'dashboard-vista-tabla-iconos.css',
        $sipAsverRoot . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'sip_asset_version.php',
    ];
    $sipAsverMax = 0;
    foreach ($sipAsverCandidates as $sipAsverFile) {
        if (is_file($sipAsverFile)) {
            $sipAsverM = @filemtime($sipAsverFile);
            if ($sipAsverM !== false && $sipAsverM > $sipAsverMax) {
                $sipAsverMax = $sipAsverM;
            }
        }
    }
    define('SIP_ASVER', $sipAsverMax > 0 ? (string) $sipAsverMax : (string) time());
}

if (!function_exists('sip_asset_url')) {
    function sip_asset_url(string $webPath): string
    {
        $webPath = str_replace('\\', '/', $webPath);
        $webPath = trim($webPath);
        if ($webPath === '') {
            return '';
        }
        $sep = strpos($webPath, '?') !== false ? '&' : '?';

        return $webPath . $sep . 'v=' . rawurlencode((string) SIP_ASVER);
    }
}

if (!function_exists('sip_send_app_no_cache_headers')) {
    function sip_send_app_no_cache_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    }
}
