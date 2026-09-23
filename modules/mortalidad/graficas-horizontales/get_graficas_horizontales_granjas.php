<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado', 'granjas' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../../../conexion_grs/conexion.php';
require_once __DIR__ . '/sip_grafica_catalog_lib.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexion', 'granjas' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = sip_grafica_catalog_granjas_campanias_galpones(
    $conn,
    array_merge($_GET, sip_grafica_catalog_periodo_params($_GET))
);

$conn->close();
ob_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
