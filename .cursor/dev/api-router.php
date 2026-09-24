<?php

/**
 * Router del servidor embebido de PHP para la API móvil en desarrollo.
 *
 * El front controller (app/public/index.php) calcula la ruta a partir de
 * dirname(SCRIPT_NAME). En un despliegue real (Apache + rewrite) SCRIPT_NAME
 * apunta al front controller, pero el servidor embebido lo fija a la ruta
 * solicitada, rompiendo el cálculo. Aquí normalizamos SCRIPT_NAME para que la
 * lógica de enrutamiento reciba el path completo del REQUEST_URI.
 */

$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/../../app/public/index.php';
