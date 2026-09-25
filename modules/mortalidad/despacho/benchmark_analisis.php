<?php

declare(strict_types=1);

/**
 * Mide tiempos del análisis despacho e indica el paso más pesado.
 *
 * GET/POST mismos filtros que get_analisis_despacho (periodo, granja, cencos…).
 * GET extendido=1 incluye ventas_agrupada y S808 sin S700 (solo diagnóstico).
 *
 * .../modules/mortalidad/despacho/benchmark_analisis.php?periodoTipo=POR_FECHA&fechaUnica=2026-09-24
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

    $mdpTimeSec = defined('MORT_DESPACHO_QUERY_TIME_SEC') ? (int) MORT_DESPACHO_QUERY_TIME_SEC : 90;
    @set_time_limit($mdpTimeSec + 60);
    @ini_set('max_execution_time', (string) ($mdpTimeSec + 60));

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
    mort_despacho_aplicar_limites_sesion_db($conn);

    $filtros = mort_despacho_parse_filtros(array_merge($_GET, $_POST));
    mort_despacho_validar_politica_carga($filtros);

    $extendido = filter_var($_GET['extendido'] ?? $_POST['extendido'] ?? '0', FILTER_VALIDATE_BOOLEAN);

    $t0 = microtime(true);
    $informe = mort_despacho_benchmark_analisis($conn, $filtros, $extendido);
    $informe['ms_benchmark_total'] = round((microtime(true) - $t0) * 1000, 2);

    mysqli_close($conn);

    echo json_encode(array_merge(['success' => true], $informe), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @mysqli_close($conn);
    }
    $code = 500;
    $msg = $e->getMessage();
    if (stripos($msg, 'periodo') !== false || stripos($msg, 'granjas') !== false) {
        $code = 400;
    }
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
}
