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

require_once __DIR__ . '/mortalidad_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    mort_admin_json_error('Error de conexion', 500);
}

mysqli_set_charset($conn, 'latin1');

$codigosPorTipo = [
    'transporte' => ['01', '02'],
    'produccion' => ['03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '15', '16'],
    'despacho' => function_exists('mort_listado_codigos_motivo_despacho')
        ? mort_listado_codigos_motivo_despacho()
        : ['14', '17', '18', '19'],
];

$sql = 'SELECT tcod_mort, tnom_mort FROM regmotivo_mortalidadgrs ORDER BY tnom_mort';
$result = mysqli_query($conn, $sql);
if (!$result) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    mort_admin_json_error('Error SQL: ' . $err, 500);
}

$motivosPorTipo = [
    'transporte' => [],
    'produccion' => [],
    'despacho' => [],
    'incubacion' => [],
];
$motivos = [];
$seenUnion = [];

while ($row = mysqli_fetch_assoc($result)) {
    $rawCode = (string) ($row['tcod_mort'] ?? '');
    $code = str_pad(trim($rawCode), 2, '0', STR_PAD_LEFT);
    $nombre = (string) ($row['tnom_mort'] ?? '');
    if (function_exists('mb_convert_encoding')) {
        $nombre = mb_convert_encoding($nombre, 'UTF-8', 'ISO-8859-1');
    }
    $item = [
        'tcod_mort' => $code,
        'tnom_mort' => $nombre,
    ];

    foreach ($codigosPorTipo as $tipo => $codigos) {
        if (in_array($code, $codigos, true)) {
            $motivosPorTipo[$tipo][] = $item;
        }
    }

    if (!isset($seenUnion[$code])) {
        $seenUnion[$code] = true;
        $motivos[] = $item;
    }
}

mysqli_close($conn);

mort_admin_json_ok([
    'motivos_por_tipo' => $motivosPorTipo,
    'motivos' => $motivos,
    'total' => count($motivos),
]);
