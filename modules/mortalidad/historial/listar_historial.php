<?php

declare(strict_types=1);

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

require_once __DIR__ . '/mortalidad_historial_lib.php';
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['draw' => $drawReq, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Error de conexión']);
    exit;
}

if (!mort_hist_tabla_existe($conn)) {
    mysqli_close($conn);
    echo json_encode([
        'draw' => $drawReq > 0 ? $drawReq : 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'resumen' => ['total' => 0, 'ediciones' => 0, 'eliminaciones' => 0],
        'warning' => 'La tabla san_dim_historial_acciones no existe.',
    ]);
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

$filtros = mort_hist_parse_filtros($_POST);
$dtSearch = mort_listado_extract_search_input($_POST);
if ($dtSearch !== '') {
    $filtros['search'] = $dtSearch;
}

$where = mort_hist_where_filtros($conn, $filtros);

$totalFiltrados = 0;
$qFiltrado = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM san_dim_historial_acciones h ' . $where);
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

$totalSinFiltros = $totalFiltrados;
$searchVal = trim((string) ($filtros['search'] ?? ''));
$accionFiltro = trim((string) ($filtros['accion'] ?? ''));
if ($searchVal !== '' || $accionFiltro !== '') {
    $filtrosBase = $filtros;
    $filtrosBase['search'] = '';
    $filtrosBase['accion'] = '';
    $whereBase = mort_hist_where_filtros($conn, $filtrosBase);
    $qTotal = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM san_dim_historial_acciones h ' . $whereBase);
    if ($qTotal) {
        $totalSinFiltros = (int) (mysqli_fetch_assoc($qTotal)['total'] ?? 0);
    }
}

$resumen = ['total' => $totalFiltrados, 'ediciones' => 0, 'eliminaciones' => 0];
$qRes = mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN h.accion = 'EDICION_MORTALIDAD' THEN 1 ELSE 0 END) AS ediciones,
        SUM(CASE WHEN h.accion IN ('ELIMINACION_MORTALIDAD_COMPLETA','ELIMINACION_MORTALIDAD_DETALLE') THEN 1 ELSE 0 END) AS eliminaciones
    FROM san_dim_historial_acciones h
    {$where}
");
if ($qRes && ($rRes = mysqli_fetch_assoc($qRes))) {
    $resumen['ediciones'] = (int) ($rRes['ediciones'] ?? 0);
    $resumen['eliminaciones'] = (int) ($rRes['eliminaciones'] ?? 0);
}

$sqlData = "
    SELECT
        h.id,
        h.cod_usuario,
        h.nom_usuario,
        h.accion,
        h.tabla_afectada,
        h.registro_id,
        h.descripcion,
        h.fechaHora,
        h.datos_previos,
        h.datos_nuevos
    FROM san_dim_historial_acciones AS h
    {$where}
    ORDER BY h.fechaHora DESC, h.id DESC
    LIMIT {$start}, {$length}
";

$qData = mysqli_query($conn, $sqlData);
$data = [];
$metaAcciones = mort_hist_acciones_meta();

if ($qData) {
    while ($row = mysqli_fetch_assoc($qData)) {
        $accion = (string) ($row['accion'] ?? '');
        $meta = $metaAcciones[$accion] ?? [
            'label' => $accion,
            'icon' => 'fas fa-info-circle',
            'color' => 'text-gray-600',
            'badge' => 'bg-gray-50 text-gray-700 border-gray-200',
        ];

        $snapSrc = $row['datos_nuevos'] ?: $row['datos_previos'];
        $resumenSnap = mort_hist_resumen_snapshot($snapSrc);

        $data[] = [
            'id' => (int) ($row['id'] ?? 0),
            'cod_usuario' => (string) ($row['cod_usuario'] ?? ''),
            'nom_usuario' => (string) ($row['nom_usuario'] ?? ''),
            'accion' => $accion,
            'accionLabel' => mort_hist_label_accion_dinamica($accion, $snapSrc),
            'accionIcon' => $meta['icon'],
            'accionColor' => $meta['color'],
            'accionBadge' => $meta['badge'],
            'tabla_afectada' => (string) ($row['tabla_afectada'] ?? ''),
            'registro_id' => (string) ($row['registro_id'] ?? ''),
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'fechaHora' => (string) ($row['fechaHora'] ?? ''),
            'granja' => $resumenSnap['granja'],
            'campania' => $resumenSnap['campania'],
            'galpon' => $resumenSnap['galpon'],
            'tipo' => $resumenSnap['tipo'],
            'tipoLabel' => $resumenSnap['tipoLabel'],
            'documento' => $resumenSnap['documento'],
            'totalAves' => $resumenSnap['totalAves'],
            'lineas' => $resumenSnap['lineas'],
        ];
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

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $totalSinFiltros,
    'recordsFiltered' => $totalFiltrados,
    'data' => $data,
    'resumen' => $resumen,
], JSON_UNESCAPED_UNICODE);
