<?php
if (!defined('PA_AUTH_LOADED')) {
    define('PA_AUTH_LOADED', true);

    require_once __DIR__ . '/config.php';
    require_once PA_CORE_LIB . '/sip_asset_version.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['active'])) {
        $loginUrl = PA_AUTH_LOGIN;
        if (headers_sent()) {
            echo '<script>window.top.location.href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '";</script>';
            exit;
        }
        header('Location: ' . $loginUrl);
        exit;
    }
}

if (!function_exists('pa_asset_url')) {
    function pa_asset_url(string $webPath): string
    {
        return sip_asset_url($webPath);
    }
}

if (!function_exists('pa_session_usuario')) {
    function pa_session_usuario(): string
    {
        return (string) ($_SESSION['usuario'] ?? '');
    }
}

if (!function_exists('pa_session_nombre')) {
    function pa_session_nombre(): string
    {
        return (string) ($_SESSION['nombre'] ?? pa_session_usuario());
    }
}
