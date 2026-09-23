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

require_once __DIR__ . '/mortalidad_auditoria_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['draw' => $drawReq, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'Error de conexión']);
    exit;
}

if (!mort_aud_listado_tabla_existe($conn)) {
    mysqli_close($conn);
    echo json_encode([
        'draw' => $drawReq > 0 ? $drawReq : 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'resumen' => ['sesiones' => 0, 'totalRegistros' => 0, 'totalAves' => 0, 'grupos' => 0],
        'warning' => 'La tabla san_mortalidad_auditoria no existe. Ejecute la migración.',
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

$filtros = mort_aud_listado_parse_filtros($_POST);
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';
$dtSearch = mort_listado_extract_search_input($_POST);
if ($dtSearch !== '') {
    $filtros['search'] = $dtSearch;
}

$where = mort_aud_listado_where_filtros($conn, $filtros);

$totalFiltrados = 0;
$qFiltrado = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM san_mortalidad_auditoria a ' . $where);
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
    $whereBase = mort_aud_listado_where_filtros($conn, $filtrosBase);
    $qTotal = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM san_mortalidad_auditoria a ' . $whereBase);
    if ($qTotal) {
        $totalSinFiltros = (int) (mysqli_fetch_assoc($qTotal)['total'] ?? 0);
    }
}

// Página liviana: sin JOIN a movi_zonas ni JSON registros (detalle se carga aparte)
$sqlData = "
    SELECT
        a.id,
        a.usuarioRegistro,
        a.totalRegistros,
        a.totalAves,
        a.observaciones,
        a.evidencia,
        a.fecha,
        a.fechaHoraRegistro,
        a.granja AS codGranja,
        a.campania,
        a.galpon
    FROM san_mortalidad_auditoria AS a
    {$where}
    ORDER BY a.fechaHoraRegistro DESC, a.fecha DESC
    LIMIT {$start}, {$length}
";

$qData = mysqli_query($conn, $sqlData);
$data = [];
$idsNeedEnrich = [];
if ($qData) {
    while ($row = mysqli_fetch_assoc($qData)) {
        $id = (string) ($row['id'] ?? '');
        $codGranja = trim((string) ($row['codGranja'] ?? ''));
        $campania = trim((string) ($row['campania'] ?? ''));
        $galpon = trim((string) ($row['galpon'] ?? ''));
        $row['codGranja'] = $codGranja;
        $row['campania'] = $campania;
        $row['galpon'] = $galpon;
        $row['edad'] = '';
        $row['nombreAuditor'] = mort_aud_nombre_auditor($conn, (string) ($row['usuarioRegistro'] ?? ''));
        $row['nombreGranja'] = $codGranja !== '' ? mort_aud_nombre_granja($conn, $codGranja) : '';
        $data[] = $row;
        // Enriquecer solo filas sin granja/campania/galpon (datos legacy)
        if ($id !== '' && ($codGranja === '' || $campania === '' || $galpon === '')) {
            $idsNeedEnrich[$id] = true;
        }
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

// Enrichment puntual (solo legacy sin columnas pobladas)
if ($idsNeedEnrich !== []) {
    $idsEsc = [];
    foreach (array_keys($idsNeedEnrich) as $aid) {
        $idsEsc[] = "'" . mysqli_real_escape_string($conn, $aid) . "'";
    }
    $inList = implode(',', $idsEsc);
    $qEnrich = mysqli_query($conn, "
        SELECT
            mz.internal_auditoria AS audId,
            MAX(LEFT(TRIM(mz.tcencos), 3)) AS grj,
            MAX(RIGHT(TRIM(mz.tcencos), 3)) AS cmp,
            MAX(NULLIF(TRIM(mz.tcodint), '')) AS glp,
            MAX(mz.tedad) AS tedad
        FROM movi_zonas mz
        WHERE mz.internal_auditoria IN ({$inList})
        GROUP BY mz.internal_auditoria
    ");
    $enrichMap = [];
    if ($qEnrich) {
        while ($er = mysqli_fetch_assoc($qEnrich)) {
            $enrichMap[(string) ($er['audId'] ?? '')] = $er;
        }
    }
    foreach ($data as &$row) {
        $id = (string) ($row['id'] ?? '');
        if (!isset($enrichMap[$id])) {
            continue;
        }
        $er = $enrichMap[$id];
        if (trim((string) ($row['codGranja'] ?? '')) === '') {
            $row['codGranja'] = trim((string) ($er['grj'] ?? ''));
            if ($row['codGranja'] !== '') {
                $row['nombreGranja'] = mort_aud_nombre_granja($conn, $row['codGranja']);
            }
        }
        if (trim((string) ($row['campania'] ?? '')) === '') {
            $row['campania'] = trim((string) ($er['cmp'] ?? ''));
        }
        if (trim((string) ($row['galpon'] ?? '')) === '') {
            $row['galpon'] = trim((string) ($er['glp'] ?? ''));
        }
        if ((string) ($row['edad'] ?? '') === '' || (string) $row['edad'] === '0') {
            $tedad = $er['tedad'] ?? null;
            $row['edad'] = $tedad !== null && $tedad !== '' ? (string) $tedad : '';
        }
    }
    unset($row);
}

// Edad para filas que sí tienen granja pero aún sin edad: batch liviano solo por ids de la página
$pageIds = [];
foreach ($data as $row) {
    $id = (string) ($row['id'] ?? '');
    if ($id !== '' && ((string) ($row['edad'] ?? '') === '' || (string) $row['edad'] === '0')) {
        $pageIds[$id] = true;
    }
}
if ($pageIds !== []) {
    $idsEsc = [];
    foreach (array_keys($pageIds) as $aid) {
        $idsEsc[] = "'" . mysqli_real_escape_string($conn, $aid) . "'";
    }
    $inList = implode(',', $idsEsc);
    $qEdad = mysqli_query($conn, "
        SELECT mz.internal_auditoria AS audId, MAX(mz.tedad) AS tedad
        FROM movi_zonas mz
        WHERE mz.internal_auditoria IN ({$inList})
          AND mz.tedad IS NOT NULL
        GROUP BY mz.internal_auditoria
    ");
    $edadMap = [];
    if ($qEdad) {
        while ($er = mysqli_fetch_assoc($qEdad)) {
            $edadMap[(string) ($er['audId'] ?? '')] = (string) ($er['tedad'] ?? '');
        }
    }
    foreach ($data as &$row) {
        $id = (string) ($row['id'] ?? '');
        if (isset($edadMap[$id]) && $edadMap[$id] !== '') {
            $row['edad'] = $edadMap[$id];
        }
    }
    unset($row);
}

// Resumen solo en primera página / cambio de filtros (start=0)
$payload = [
    'draw' => $draw,
    'recordsTotal' => $totalSinFiltros,
    'recordsFiltered' => $totalFiltrados,
    'data' => $data,
];
if ($start === 0) {
    $payload['resumen'] = mort_aud_listado_fetch_resumen($conn, $filtros);
}

mysqli_close($conn);

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

echo json_encode($payload, $flags);
