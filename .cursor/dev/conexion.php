<?php

/**
 * Shim de conexión SOLO para el entorno de desarrollo del Cloud Agent.
 *
 * En producción este archivo vive fuera del repositorio, en el htdocs
 * compartido (../conexion_grs/conexion.php), y apunta a la base de datos ERP
 * "Joya". Aquí lo reemplazamos por una conexión a la instancia local de
 * MariaDB creada durante el setup del entorno, replicando la misma interfaz
 * pública que usan el core y los módulos:
 *
 *   - función  conectar_joya_mysqli(): \mysqli|false
 *   - constantes DB_HOST_JOYA / DB_USER_JOYA / DB_PASSWORD_JOYA / DB_NAME_JOYA
 *
 * Las credenciales se leen de variables de entorno para no fijar secretos en
 * el código; el fallback apunta a la MariaDB local del entorno.
 */

if (!defined('DB_HOST_JOYA')) {
    define('DB_HOST_JOYA', getenv('JOYA_DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_USER_JOYA')) {
    define('DB_USER_JOYA', getenv('JOYA_DB_USER') ?: 'joya');
}
if (!defined('DB_PASSWORD_JOYA')) {
    define('DB_PASSWORD_JOYA', getenv('JOYA_DB_PASSWORD') ?: 'joya');
}
if (!defined('DB_NAME_JOYA')) {
    define('DB_NAME_JOYA', getenv('JOYA_DB_NAME') ?: 'joya');
}

if (!defined('DB_PORT_JOYA')) {
    define('DB_PORT_JOYA', (int) (getenv('JOYA_DB_PORT') ?: 3306));
}

// Token Bearer estático de la API móvil (en producción vive fuera del repo).
if (!defined('API_TOKEN')) {
    define('API_TOKEN', getenv('JOYA_API_TOKEN') ?: 'dev-token-local');
}

if (!function_exists('conectar_joya_mysqli')) {
    /**
     * Conexión mysqli a la BD Joya (instancia local en desarrollo).
     *
     * @return \mysqli|false
     */
    function conectar_joya_mysqli()
    {
        $link = mysqli_init();
        if (!$link) {
            return false;
        }
        mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        if (!@mysqli_real_connect($link, DB_HOST_JOYA, DB_USER_JOYA, DB_PASSWORD_JOYA, DB_NAME_JOYA, DB_PORT_JOYA)) {
            return false;
        }
        mysqli_set_charset($link, 'utf8mb4');

        return $link;
    }
}
