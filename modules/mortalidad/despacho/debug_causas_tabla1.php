<?php

declare(strict_types=1);

/**
 * Debug tabla 1 (causas): líneas JD4 usadas vs cabeceras del listado.
 *
 * GET/POST: mismos filtros que el dashboard (POR_FECHA + fechaUnica recomendado).
 * .../modules/mortalidad/despacho/debug_causas_tabla1.php?periodoTipo=POR_FECHA&fechaUnica=2026-09-22
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/mortalidad_despacho_lib.php';

    $conexionPath = null;
    foreach ([
        __DIR__ . '/../../../../conexion_grs/conexion.php',
        __DIR__ . '/../../../conexion_grs/conexion.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            $conexionPath = $candidate;
            break;
        }
    }
    if ($conexionPath === null) {
        throw new RuntimeException('conexion_grs/conexion.php no encontrado');
    }
    require_once $conexionPath;

    $conn = conectar_joya_mysqli();
    if (!$conn) {
        throw new RuntimeException('Sin conexión BD');
    }
    mysqli_set_charset($conn, 'utf8');

    $filtros = mort_despacho_parse_filtros(array_merge($_GET, $_POST));
    mort_despacho_validar_politica_carga($filtros);

    $payload = mort_despacho_debug_tabla_causas($conn, $filtros);
    mysqli_close($conn);

    echo json_encode(array_merge([
        'success' => true,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
    ], $payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @mysqli_close($conn);
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
