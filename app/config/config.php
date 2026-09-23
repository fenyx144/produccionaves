<?php

declare(strict_types=1);

/** Raíz de la aplicación MVC (carpeta app). */
define('APP_ROOT', dirname(__DIR__));
/** Raíz del proyecto produccionaves. */
define('APP_PROJECT_ROOT', dirname(APP_ROOT));
/** Raíz de htdocs (conexion_grs compartida). */
define('APP_HTDOCS_ROOT', dirname(APP_ROOT, 2));

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/uploads_config.php';
require_once APP_HTDOCS_ROOT . '/conexion_grs/conexion.php';
