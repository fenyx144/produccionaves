<?php

/**
 * Configuración central del proyecto Producción Aves.
 */

if (!defined('PA_APP_NAME')) {
    define('PA_APP_NAME', 'Producción Aves');
}

if (!defined('PA_PROJECT_ROOT')) {
    define('PA_PROJECT_ROOT', dirname(__DIR__));
}


if (!defined('PA_DB_PATH')) {
    define('PA_DB_PATH', dirname(PA_PROJECT_ROOT) . DIRECTORY_SEPARATOR . 'conexion_grs' . DIRECTORY_SEPARATOR . 'conexion.php');
}

if (!defined('PA_UPLOADS_ROOT')) {
    define('PA_UPLOADS_ROOT', PA_PROJECT_ROOT);
}

if (!defined('PA_CORE')) {
    define('PA_CORE', PA_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'core');
}

if (!defined('PA_CORE_LIB')) {
    define('PA_CORE_LIB', PA_CORE . DIRECTORY_SEPARATOR . 'lib');
}

if (!defined('PA_ASSETS')) {
    define('PA_ASSETS', PA_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'assets');
}

/** Rutas web relativas a la raíz del proyecto (PA_PROJECT_ROOT). */
if (!defined('PA_INDEX')) {
    define('PA_INDEX', 'index.php');
}
if (!defined('PA_AUTH_LOGIN')) {
    define('PA_AUTH_LOGIN', 'core/auth/login.php');
}
if (!defined('PA_AUTH_LOGOUT')) {
    define('PA_AUTH_LOGOUT', 'core/auth/logout.php');
}
if (!defined('PA_AUTH_HANDLER')) {
    define('PA_AUTH_HANDLER', 'core/auth/login_handler.php');
}
if (!defined('PA_VER_UPLOAD')) {
    define('PA_VER_UPLOAD', 'core/ver_upload.php');
}

if (!function_exists('pa_web_rel')) {
    /** Ruta web relativa subiendo N niveles desde un archivo bajo modules/. */
    function pa_web_rel(int $levelsUp, string $webPath): string
    {
        return str_repeat('../', max(0, $levelsUp)) . ltrim(str_replace('\\', '/', $webPath), '/');
    }
}

if (!function_exists('pa_ver_upload_prefix')) {
    function pa_ver_upload_prefix(int $levelsUp = 3): string
    {
        return pa_web_rel($levelsUp, PA_VER_UPLOAD) . '?ruta=';
    }
}

if (!function_exists('pa_auth_login_url')) {
    function pa_auth_login_url(int $levelsUp = 3): string
    {
        return pa_web_rel($levelsUp, PA_AUTH_LOGIN);
    }
}
