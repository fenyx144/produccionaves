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

require_once __DIR__ . '/mortalidad_listado_lib.php';
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

$filtros = mort_listado_parse_filtros($_POST);
$dtSearch = mort_listado_extract_search_input($_POST);
if ($dtSearch !== '') {
    $filtros['search'] = $dtSearch;
}

$tiposPerm = mort_listado_tipos_reporte_keys();
$where = mort_listado_where_filtros($conn, $tiposPerm, $filtros);
$baseFromCab = mort_listado_sql_from_cab($conn);

$totalFiltrados = 0;
$qFiltrado = mysqli_query($conn, 'SELECT COUNT(*) AS total ' . $baseFromCab . $where);
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

// recordsTotal: solo segundo COUNT si hay búsqueda global
$totalSinFiltros = $totalFiltrados;
$searchVal = trim((string) ($filtros['search'] ?? ''));
if ($searchVal !== '') {
    $filtrosBase = $filtros;
    $filtrosBase['search'] = '';
    $whereBase = mort_listado_where_filtros($conn, $tiposPerm, $filtrosBase);
    $qTotal = mysqli_query($conn, 'SELECT COUNT(*) AS total ' . $baseFromCab . $whereBase);
    if ($qTotal) {
        $totalSinFiltros = (int) (mysqli_fetch_assoc($qTotal)['total'] ?? 0);
    }
}

// Consulta liviana: sin subconsultas correlacionadas (numFac/edad/totales se cargan por página).
$sqlData = "
    SELECT
        c.id,
        c.doc,
        c.serie,
        c.numero,
        c.tipoMortalidad,
        c.subtipoTransporte,
        c.subtipoProduccion,
        c.granja,
        c.campania,
        c.granjaNombre,
        c.galpon,
        c.fechaRegistro,
        c.fechaLlegada,
        c.usuarioRegistro,
        u.nombre AS nombreUsuario,
        c.fechaHoraRegistro,
        c.observaciones
    {$baseFromCab}
    LEFT JOIN usuario u ON c.usuarioRegistro = u.codigo
    {$where}
    ORDER BY c.fechaHoraRegistro DESC, c.serie DESC, c.numero DESC
    LIMIT {$start}, {$length}
";

$qData = mysqli_query($conn, $sqlData);
$data = [];
if ($qData) {
    while ($row = mysqli_fetch_assoc($qData)) {
        $row['tipoLabel'] = mort_listado_label_tipo((string) ($row['tipoMortalidad'] ?? ''));
        $row['subtipoLabel'] = mort_listado_label_subtipo(
            (string) ($row['tipoMortalidad'] ?? ''),
            $row['subtipoTransporte'] ?? null,
            $row['subtipoProduccion'] ?? null
        );
        $serieNum = trim((string) ($row['serie'] ?? ''));
        $numeroNum = trim((string) ($row['numero'] ?? ''));
        $row['documento'] = $serieNum !== '' && $numeroNum !== ''
            ? $serieNum . '-' . $numeroNum
            : '';
        $row['cenco'] = trim((string) ($row['granja'] ?? '') . (string) ($row['campania'] ?? ''));
        $row['nombreUsuario'] = mort_listado_format_usuario_display(
            (string) ($row['nombreUsuario'] ?? ''),
            (string) ($row['usuarioRegistro'] ?? '')
        );
        $data[] = $row;
    }
    $data = mort_listado_enrich_page_rows($conn, $data);
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

$resumen = null;
if ($start === 0) {
    $resumen = mort_listado_fetch_resumen($conn, $tiposPerm, $filtros);
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
if ($resumen !== null) {
    $payload['resumen'] = $resumen;
}

echo json_encode($payload, $flags);
