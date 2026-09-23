<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Http\Router;
use App\Middleware\Cors;

// Errores PHP respondidos como JSON (contrato de la app).
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
set_error_handler(static function (int $errno, string $errstr): void {
    if (headers_sent()) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 500,
        'success' => false,
        'message' => 'Error del servidor: ' . $errstr,
        'errorCode' => 'PHP_ERROR',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 500,
        'success' => false,
        'message' => 'Error fatal del servidor',
        'errorCode' => 'FATAL_ERROR',
        'debug' => ($error['message'] ?? '?') . ' in ' . ($error['file'] ?? '?') . ':' . ($error['line'] ?? '?'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

Cors::aplicar();

$router = new Router();
$rutas = require APP_ROOT . '/routes/api.php';
foreach ($rutas as $metodo => $mapa) {
    foreach ($mapa as $ruta => $handler) {
        if ($metodo === 'GET') {
            $router->get($ruta, $handler);
        } else {
            $router->post($ruta, $handler);
        }
    }
}

// Ruta relativa al directorio público (p. ej. /login.php, /mortalidad/listar_...).
$base = rtrim(str_replace('\\', '/', (string) dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/';
if ($base === '' || $base === '/') {
    $path = $uri !== '' ? $uri : '/';
} elseif (strpos($uri, $base) === 0) {
    $path = substr($uri, strlen($base)) ?: '/';
}

$router->despachar($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

while (ob_get_level() > 0) {
    ob_end_clean();
}
