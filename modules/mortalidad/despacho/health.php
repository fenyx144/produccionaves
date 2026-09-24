<?php

/**
 * Comprueba que mortalidad_despacho_lib.php carga en PHP 7.2+ (sin consultar BD).
 * GET .../despacho/health.php → {"ok":true,"php":"7.2.0","libRev":"20260924d"}
 */
header('Content-Type: application/json; charset=utf-8');

$lib = __DIR__ . '/mortalidad_despacho_lib.php';
if (!is_file($lib)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Falta mortalidad_despacho_lib.php']);
    exit;
}

require_once $lib;

echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : 'unknown',
], JSON_UNESCAPED_UNICODE);
