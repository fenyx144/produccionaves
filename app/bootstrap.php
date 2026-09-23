<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

// Libs de dominio de mortalidad copiadas a Services/Gri.
require_once __DIR__ . '/Services/Gri/mortalidad_doc_lib.php';
require_once __DIR__ . '/Services/Gri/mortalidad_zonas_lib.php';
require_once __DIR__ . '/Services/Gri/mortalidad_fact_aux_lib.php';
require_once __DIR__ . '/Services/Gri/mortalidad_auditoria_lib.php';
require_once __DIR__ . '/Services/Gri/cantidad_pollos_lib.php';

// Autoload de clases App\ (Http, Middleware, Controllers, Models).
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'App\\';
    if (strpos($clase, $prefijo) !== 0) {
        return;
    }
    $rel = substr($clase, strlen($prefijo));
    $archivo = APP_ROOT . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($archivo)) {
        require_once $archivo;
    }
});
