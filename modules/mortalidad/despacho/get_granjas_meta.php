<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado', 'granjas' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

include_once __DIR__ . '/../../../../conexion_grs/conexion.php';
if (file_exists(__DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php')) {
    require_once __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';
}

$conn = conectar_joya_mysqli();
$granjas = [];
if ($conn) {
    try {
        if (function_exists('hc_granjas_listar_para_selector')) {
            foreach (hc_granjas_listar_para_selector($conn) as $gz) {
                $granjas[] = [
                    'granja' => $gz['granja'] ?? '',
                    'nombre' => $gz['nombre'] ?? $gz['nombre_granja'] ?? '',
                    'zona' => $gz['zona'] ?? '',
                    'subzona' => $gz['subzona'] ?? '',
                ];
            }
        }
    } catch (\Throwable $e) {
        $granjas = [];
    }
    mysqli_close($conn);
}

echo json_encode(['success' => true, 'granjas' => $granjas], JSON_UNESCAPED_UNICODE);
