<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * Devuelve JSON aunque haya fatal (timeout, memoria, etc.).
 */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode([
        'success' => false,
        'message' => 'Fatal: ' . ($err['message'] ?? 'error'),
        'file' => isset($err['file']) ? basename((string) $err['file']) : '',
        'line' => $err['line'] ?? 0,
    ], $flags);
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    @set_time_limit(300);

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
        throw new RuntimeException('No se encontró conexion_grs/conexion.php');
    }
    require_once $conexionPath;

    if (!function_exists('conectar_joya_mysqli')) {
        throw new RuntimeException('Falta la función conectar_joya_mysqli() en conexion.php');
    }

    $conn = conectar_joya_mysqli();
    if (!$conn) {
        throw new RuntimeException('Error de conexión a la base de datos');
    }

    mysqli_set_charset($conn, 'utf8');
    @mysqli_query($conn, "SET time_zone = 'America/Lima'");

    $filtros = mort_despacho_parse_filtros(array_merge($_GET, $_POST));
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        mysqli_close($conn);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Indique un periodo o rango de fechas válido.']);
        exit;
    }

    $bloque = strtolower(trim((string) ($_GET['bloque'] ?? $_POST['bloque'] ?? 'completo')));
    if ($bloque === 'principal') {
        $data = mort_despacho_analisis_bloque_principal($conn, $filtros);
    } elseif ($bloque === 'etapas') {
        $data = mort_despacho_analisis_bloque_etapas($conn, $filtros);
    } else {
        $data = mort_despacho_analisis_completo($conn, $filtros);
    }
    mysqli_close($conn);

    $payload = [
        'success' => true,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'bloque' => $bloque,
        'filtros' => $filtros,
        'rango' => $data['rango'],
    ];
    if (array_key_exists('causas', $data)) {
        $payload['causas'] = $data['causas'];
    }
    if (array_key_exists('etapas', $data)) {
        $payload['etapas'] = $data['etapas'];
    }
    if (array_key_exists('resumenGranjas', $data)) {
        $payload['resumenGranjas'] = $data['resumenGranjas'];
    }

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar JSON: ' . json_last_error_msg());
    }

    echo $json;
} catch (\Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @mysqli_close($conn);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
}
