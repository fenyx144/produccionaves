<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'data' => [], 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/graficas_horizontales_lib.php';
require_once __DIR__ . '/sip_grafica_catalog_lib.php';

$result = ['success' => true, 'data' => graficas_horizontales_tipos_visibles()];

ob_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
