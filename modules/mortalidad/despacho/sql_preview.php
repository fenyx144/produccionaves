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
    echo json_encode(['success' => false, 'message' => 'Sin conexion.php']);
    exit;
}
require_once $conexionPath;

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sin conexión BD']);
    exit;
}

$filtros = mort_despacho_parse_filtros(array_merge($_GET, $_POST));
$preview = mort_despacho_export_sql_preview($conn, $filtros);
mysqli_close($conn);

echo json_encode(array_merge(['success' => true], $preview), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
