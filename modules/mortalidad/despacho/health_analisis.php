<?php

/**
 * Diagnóstico del análisis (requiere sesión activa como el dashboard).
 * GET .../health_analisis.php?periodoTipo=POR_FECHA&fechaUnica=2026-09-24
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'No autorizado (inicie sesión y abra desde el mismo dominio)']);
    exit;
}

$steps = [];
$fail = static function (string $step, \Throwable $e) use (&$steps): void {
    $steps[] = ['step' => $step, 'ok' => false, 'error' => $e->getMessage()];
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'php' => PHP_VERSION,
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    require_once __DIR__ . '/mortalidad_despacho_lib.php';
    $steps[] = ['step' => 'load_lib', 'ok' => true];

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
    $steps[] = ['step' => 'conexion_file', 'ok' => true, 'path' => basename(dirname($conexionPath)) . '/conexion.php'];

    $conn = conectar_joya_mysqli();
    if (!$conn) {
        throw new RuntimeException('conectar_joya_mysqli() devolvió false');
    }
    mysqli_set_charset($conn, 'utf8');
    $steps[] = ['step' => 'db_connect', 'ok' => true];

    $filtros = mort_despacho_parse_filtros(array_merge($_GET, $_POST));
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido');
    }
    $steps[] = ['step' => 'rango', 'ok' => true, 'rango' => $rango];

    $causasRows = mort_despacho_causas_agregadas_sql($conn, $filtros);
    $causas = mort_despacho_agregar_causas($causasRows);
    $steps[] = ['step' => 'causas', 'ok' => true, 'total' => $causas['total'] ?? 0];

    $etapas = mort_despacho_consultar_etapas($conn, $filtros);
    $steps[] = ['step' => 'etapas', 'ok' => true, 'total' => $etapas['total'] ?? 0];

    $filasVenta = mort_despacho_ventas_agrupada_filas($conn, $filtros);
    $steps[] = ['step' => 'ventas_agrupada', 'ok' => true, 'filas' => count($filasVenta)];

    $stats = mort_despacho_resumen_stats_map($conn, $filtros);
    $steps[] = ['step' => 'resumen_stats', 'ok' => true, 'keys' => count($stats)];

    $resumen = mort_despacho_consultar_resumen_granjas($conn, $filtros);
    $steps[] = ['step' => 'resumen_build', 'ok' => true, 'filas' => count($resumen)];

    mysqli_close($conn);

    echo json_encode([
        'ok' => true,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'php' => PHP_VERSION,
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    $fail('exception', $e);
}
