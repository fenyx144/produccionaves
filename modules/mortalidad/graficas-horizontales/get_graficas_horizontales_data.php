<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../../../conexion_grs/conexion.php';
require_once __DIR__ . '/sip_grafica_acumulado_semanal_lib.php';
require_once __DIR__ . '/sip_grafica_pesaje_lib.php';
require_once __DIR__ . '/sip_grafica_liquidacion_lib.php';
require_once __DIR__ . '/graficas_horizontales_lib.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexion']);
    exit;
}

$tipo = trim((string) ($_GET['tipo'] ?? 'mortalidad'));
$fechaInicio = trim((string) ($_GET['fechaInicio'] ?? ''));
$fechaFin = trim((string) ($_GET['fechaFin'] ?? ''));
$semana = (int) ($_GET['semana'] ?? 0);
$dia = (int) ($_GET['dia'] ?? 1);
$tedad = sip_grafica_pesaje_dia_api_a_tedad($dia);

$diasParam = $_GET['dias'] ?? $_GET['dias[]'] ?? null;
$dias = [];
if (is_array($diasParam)) {
    foreach ($diasParam as $dv) {
        $dias[] = (int) $dv;
    }
} elseif (is_string($diasParam) && trim($diasParam) !== '') {
    foreach (explode(',', $diasParam) as $dv) {
        $dias[] = (int) $dv;
    }
}

if ($fechaInicio === '' || $fechaFin === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan parametros: fechaInicio, fechaFin']);
    $conn->close();
    exit;
}

$tipoNorm = strtolower(trim($tipo));
if (in_array($tipoNorm, graficas_horizontales_tipos_liquidacion(), true)) {
    $result = sip_grafica_liquidacion_horizontal_build($conn, [
        'tipo' => $tipoNorm,
        'fechaInicio' => $fechaInicio,
        'fechaFin' => $fechaFin,
    ]);
} else {
    $result = sip_grafica_acumulado_semanal_build($conn, [
        'tipo' => $tipo,
        'fechaInicio' => $fechaInicio,
        'fechaFin' => $fechaFin,
        'semana' => $semana,
        'dia' => $dia,
        'tedad' => $tedad,
        'dias' => $dias,
    ]);
}

$conn->close();
ob_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
