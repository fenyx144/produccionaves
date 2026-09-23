<?php

declare(strict_types=1);

@set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$drawReq = intval($_POST['draw'] ?? 0);
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['draw' => $drawReq, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/mortalidad_ventas_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['draw' => $drawReq, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Error de conexión']);
    exit;
}

$draw = $drawReq > 0 ? $drawReq : 1;
$start = max(0, intval($_POST['start'] ?? 0));
$length = intval($_POST['length'] ?? 25);
if ($length < 1) {
    $length = 25;
}
if ($length > 200) {
    $length = 200;
}

$filtros = mort_ventas_parse_filtros($_POST);
$dtSearch = mort_ventas_extract_search_input($_POST);
if ($dtSearch !== '') {
    $filtros['search'] = $dtSearch;
}

$fromV = mort_ventas_from_v($conn, $filtros);
$whereExt = mort_ventas_where_externo($conn, $filtros);

// recordsFiltered: COUNT con búsqueda actual.
$totalFiltrados = 0;
$qFiltrado = mysqli_query($conn, 'SELECT COUNT(*) AS total ' . ' FROM ' . $fromV . $whereExt);
if (!$qFiltrado) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Error en consulta: ' . $err,
    ]);
    exit;
}
$totalFiltrados = (int) (mysqli_fetch_assoc($qFiltrado)['total'] ?? 0);

// recordsTotal: COUNT sin la búsqueda global (solo si hay búsqueda activa).
$totalSinFiltros = $totalFiltrados;
$searchVal = trim((string) ($filtros['search'] ?? ''));
if ($searchVal !== '') {
    $filtrosBase = $filtros;
    $filtrosBase['search'] = '';
    $qTotal = mysqli_query($conn, 'SELECT COUNT(*) AS total ' . ' FROM ' . mort_ventas_from_v($conn, $filtrosBase));
    if ($qTotal) {
        $totalSinFiltros = (int) (mysqli_fetch_assoc($qTotal)['total'] ?? 0);
    }
}

// Datos paginados.
$sqlData = "
    SELECT
        v.fecha,
        v.granja,
        v.campania,
        v.galpon,
        v.venta,
        v.mortalidad,
        v.venta_macho,
        v.venta_hembra,
        v.mort_macho,
        v.mort_hembra,
        v.mort_desp_macho,
        v.mort_desp_hembra,
        v.tiene_s808_macho,
        v.tiene_s808_hembra,
        COALESCE(NULLIF(TRIM(cc.nombre), ''), v.granja) AS granjaNombre
    FROM {$fromV}
    {$whereExt}
    ORDER BY v.fecha DESC, v.granja ASC, v.campania ASC, v.galpon ASC
    LIMIT {$start}, {$length}
";

$data = [];
$qData = mysqli_query($conn, $sqlData);
if ($qData) {
    while ($row = mysqli_fetch_assoc($qData)) {
        $data[] = $row;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalSinFiltros,
        'recordsFiltered' => $totalFiltrados,
        'data' => [],
        'error' => 'Error en consulta de datos: ' . $err,
    ]);
    exit;
}

mysqli_close($conn);

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

$payload = [
    'draw' => $draw,
    'recordsTotal' => $totalSinFiltros,
    'recordsFiltered' => $totalFiltrados,
    'data' => $data,
];

echo json_encode($payload, $flags);
