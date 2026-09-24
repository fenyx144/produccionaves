<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se encontró conexion_grs/conexion.php']);
    exit;
}
require_once $conexionPath;

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

mysqli_set_charset($conn, 'utf8');
mysqli_query($conn, "SET time_zone = 'America/Lima'");

$filtros = mort_despacho_parse_filtros(array_merge($_GET, $_POST));
$rango = mort_ventas_rango($filtros);
if ($rango === null) {
    mysqli_close($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Indique un periodo o rango de fechas válido.']);
    exit;
}

try {
    $data = mort_despacho_analisis_completo($conn, $filtros);
} catch (\Throwable $e) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar: ' . $e->getMessage()]);
    exit;
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
    'filtros' => $filtros,
    'rango' => $data['rango'],
    'causas' => $data['causas'],
    'etapas' => $data['etapas'],
    'resumenGranjas' => $data['resumenGranjas'],
], JSON_UNESCAPED_UNICODE);
