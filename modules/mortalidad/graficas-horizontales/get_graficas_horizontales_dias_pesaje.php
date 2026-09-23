<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'dias' => [], 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../../../conexion_grs/conexion.php';
require_once __DIR__ . '/sip_grafica_pesaje_lib.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'dias' => [], 'message' => 'Error de conexion']);
    exit;
}

$tipo = trim((string) ($_GET['tipo'] ?? 'pesaje_pollo'));

$result = sip_grafica_pesaje_dias_build($tipo);

$conn->close();
ob_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
