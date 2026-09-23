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

require_once __DIR__ . '/informe_contable_lib.php';
require_once __DIR__ . '/../../../../conexion_grs/conexion.php';
include_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

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

$filtros = inf_ctb_parse_filtros($_POST);
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';
$dtSearch = mort_listado_extract_search_input($_POST);
if ($dtSearch !== '') {
    $filtros['search'] = $dtSearch;
}

$where = inf_ctb_where_filtros($conn, $filtros);
$fromCount = inf_ctb_from_count($filtros);

// Total filtrados (FROM mínimo según filtros)
$totalFiltrados = 0;
$qFilt = mysqli_query($conn, "SELECT COUNT(*) AS total {$fromCount} {$where}");
if ($qFilt && ($r = $qFilt->fetch_assoc())) {
    $totalFiltrados = (int) ($r['total'] ?? 0);
}

// recordsTotal: solo recalcular si hay búsqueda global (mensaje "filtrado de X");
// en paginación normal evita un segundo COUNT pesado.
$totalSinFiltros = $totalFiltrados;
$searchVal = trim((string) ($filtros['search'] ?? ''));
if ($searchVal !== '') {
    $filtrosBase = $filtros;
    $filtrosBase['search'] = '';
    $whereBase = inf_ctb_where_filtros($conn, $filtrosBase);
    $fromBase = inf_ctb_from_count($filtrosBase);
    $qTotal = mysqli_query($conn, "SELECT COUNT(*) AS total {$fromBase} {$whereBase}");
    if ($qTotal && ($r = $qTotal->fetch_assoc())) {
        $totalSinFiltros = (int) ($r['total'] ?? 0);
    }
}

$joinCabe = inf_ctb_join_cabe_zonas();

// Datos paginados (sin subquery correlacionada de evidencia)
$sqlData = "
    SELECT
        u.nombre AS usuario,
        b.tdate AS fecha,
        b.ttime AS hora,
        b.external_id AS cabExternalId,
        a.tfectra AS fechaCont,
        a.tcencos AS cencos,
        c.nombre AS nomCencos,
        TRIM(a.tcodint) AS galpon,
        a.tedad AS edad,
        a.tcodigo AS codigo,
        i.descri AS nomProd,
        a.tcodtra AS codTra,
        l.descri AS transaccion,
        IF(a.ttipo='015','Macho',IF(a.ttipo='016','Hembra','-')) AS sexo,
        TRIM(a.flujo) AS flujo,
        TRIM(a.tcategoria) AS tcategoria,
        a.tnumfac AS numFac,
        a.idmovi,
        IF(LEFT(a.tcodtra,1)='E',a.tcantid,-1*a.tcantid) AS cantidad,
        IF(a.tcodtra='S808',a.tcod_mortgrs,'') AS codMort,
        IF(a.tcodtra='S808',m.tnom_mort,'') AS nomMort,
        IF(a.tcodtra='S808',a.tcantid,'') AS cantidadMort
    FROM movi_zonas AS a
    {$joinCabe}
    LEFT JOIN ccos AS c ON a.tcencos = c.codigo
    LEFT JOIN coal AS l ON a.tcodtra = l.codtra
    LEFT JOIN regmotivo_mortalidadgrs AS m ON a.tcod_mortgrs = m.tcod_mort
    LEFT JOIN usuario AS u ON b.tuser = u.codigo
    LEFT JOIN mitm AS i ON a.tcodigo = i.codigo
    {$where}
    ORDER BY a.tcencos, a.tcodint, a.tcodigo, a.tfectra, a.tcodtra, a.tnumfac
    LIMIT {$start}, {$length}
";

$qData = mysqli_query($conn, $sqlData);
$data = [];
$cabIds = [];
if ($qData) {
    while ($row = mysqli_fetch_assoc($qData)) {
        $catVal = trim((string) ($row['tcategoria'] ?? ''));
        $fluVal = trim((string) ($row['flujo'] ?? ''));
        if ($catVal === 'P' || $catVal === 'Produccion') {
            $row['tcategoria'] = 'Produccion';
            if ($fluVal === '' || $fluVal === 'P' || $fluVal === 'Crianza') {
                $row['flujo'] = 'Crianza';
            }
        } elseif ($catVal === 'PlantaIncubacion') {
            $row['tcategoria'] = 'Planta Incubacion';
        }
        if ($fluVal === 'PlantaIncubacion') {
            $row['flujo'] = 'Planta Incubacion';
        }

        $cabId = trim((string) ($row['cabExternalId'] ?? ''));
        if ($cabId !== '') {
            $cabIds[$cabId] = true;
        }
        $row['evidencia'] = '';
        $row['evidenciaUrls'] = [];
        unset($row['cabExternalId']);
        $data[] = $row;
        // Guardar cabId paralelo para mapear evidencia
        $data[count($data) - 1]['_cabId'] = $cabId;
    }
} else {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalSinFiltros,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Error en consulta: ' . $err,
    ]);
    exit;
}

// Evidencias en un solo query para los cabId de la página
$evidPorCab = [];
if ($cabIds !== []) {
    $idsEsc = [];
    foreach (array_keys($cabIds) as $cid) {
        $idsEsc[] = "'" . mysqli_real_escape_string($conn, $cid) . "'";
    }
    $inList = implode(',', $idsEsc);
    $qEv = mysqli_query($conn, "
        SELECT cz.external_id AS cabId, GROUP_CONCAT(mz.evidencia SEPARATOR ',') AS evidencia
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id IN ({$inList})
          AND mz.evidencia IS NOT NULL
          AND TRIM(mz.evidencia) <> ''
        GROUP BY cz.external_id
    ");
    if ($qEv) {
        while ($ev = mysqli_fetch_assoc($qEv)) {
            $evidPorCab[(string) ($ev['cabId'] ?? '')] = (string) ($ev['evidencia'] ?? '');
        }
    }
}

foreach ($data as &$row) {
    $cabId = (string) ($row['_cabId'] ?? '');
    unset($row['_cabId']);
    $evRaw = $evidPorCab[$cabId] ?? '';
    $row['evidencia'] = $evRaw;
    $row['evidenciaUrls'] = $evRaw !== ''
        ? mort_listado_evidencia_urls($evRaw)
        : [];
}
unset($row);

mysqli_close($conn);

$flags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $totalSinFiltros,
    'recordsFiltered' => $totalFiltrados,
    'data' => $data,
], $flags);
