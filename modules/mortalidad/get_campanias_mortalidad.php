<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'by_granja' => [], 'message' => 'No autorizado']);
    exit;
}

$granjasBulk = trim((string)($_GET['granjas'] ?? ''));
if ($granjasBulk === '') {
    echo json_encode(['success' => true, 'by_granja' => []]);
    exit;
}

require_once __DIR__ . '/../../../conexion_grs/conexion.php';
require_once __DIR__ . '/../../core/lib/filtro_periodo_util.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    echo json_encode(['success' => false, 'by_granja' => [], 'message' => 'Error de conexion']);
    exit;
}

// Normalizar granjas a 3 digitos
$keysMap = [];
foreach (preg_split('/\s*,\s*/', $granjasBulk) as $p) {
    $p = trim((string)$p);
    if ($p === '') continue;
    $g3 = substr(str_pad($p, 3, '0', STR_PAD_LEFT), 0, 3);
    $keysMap[$g3] = true;
}
$keys = array_keys($keysMap);
sort($keys);
$byGranja = [];
foreach ($keys as $k) {
    $byGranja[$k] = [];
}

// Resolver rango de fechas
$periodoTipo = trim((string)($_GET['periodoTipo'] ?? 'TODOS'));
$rango = periodo_a_rango([
    'periodoTipo' => $periodoTipo,
    'fechaUnica' => trim((string)($_GET['fechaUnica'] ?? '')),
    'fechaInicio' => trim((string)($_GET['fechaInicio'] ?? '')),
    'fechaFin' => trim((string)($_GET['fechaFin'] ?? '')),
    'mesUnico' => trim((string)($_GET['mesUnico'] ?? '')),
    'mesInicio' => trim((string)($_GET['mesInicio'] ?? '')),
    'mesFin' => trim((string)($_GET['mesFin'] ?? '')),
]);

if ($periodoTipo !== 'TODOS' && $rango === null) {
    $conn->close();
    echo json_encode(['success' => true, 'by_granja' => $byGranja, 'galpones_por_granja' => []]);
    exit;
}

$desde = $rango ? $rango['desde'] : '1900-01-01';
$hasta = $rango ? $rango['hasta'] : '9999-12-31';

$desdeEsc = mysqli_real_escape_string($conn, $desde);
$hastaEsc = mysqli_real_escape_string($conn, $hasta);

// Helper: agrega campania a byGranja si no existe
$agregarCampania = function ($g3, $c3, $fuente) use (&$byGranja) {
    $c3 = substr(str_pad(trim((string)$c3), 3, '0', STR_PAD_LEFT), 0, 3);
    if (!$c3 || $c3 === '000') return;
    foreach ($byGranja[$g3] as $existing) {
        if ($existing['campania'] === $c3) return;
    }
    $byGranja[$g3][] = ['codigo' => $g3 . $c3, 'campania' => $c3, 'fuente' => $fuente];
};

// Construir IN clause con todas las granjas (una sola vez)
$inParts = [];
foreach ($keys as $g3) {
    $inParts[] = "'" . mysqli_real_escape_string($conn, $g3) . "'";
}
$inClause3 = implode(',', $inParts);
$tieneFecha = $periodoTipo !== 'TODOS' && $rango !== null;

// ===== FUENTE 1: maes_zonas (una sola consulta masiva) =====
$sqlMaes = "SELECT DISTINCT LEFT(TRIM(tcencos), 3) AS g3, RIGHT(TRIM(tcencos), 3) AS c3
            FROM maes_zonas
            WHERE tcodigo IN ('P0001001','P0001002')
              AND LEFT(TRIM(tcencos), 3) IN ({$inClause3})
              AND CHAR_LENGTH(TRIM(tcencos)) = 6
              AND RIGHT(TRIM(tcencos), 3) <> '000'
              AND fec_ing IS NOT NULL AND fec_ing > '1900-01-01'";
if ($tieneFecha) {
    $sqlMaes .= " AND DATE(fec_ing) >= '{$desdeEsc}' AND DATE(fec_ing) <= '{$hastaEsc}'";
}
$q = mysqli_query($conn, $sqlMaes);
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $g3 = trim((string)($row['g3'] ?? ''));
        $c3 = trim((string)($row['c3'] ?? ''));
        if ($g3 !== '' && $c3 !== '') {
            $agregarCampania($g3, $c3, 'maes_zonas');
        }
    }
} else {
    error_log("MORT-CAMP: maes_zonas error: " . mysqli_error($conn));
}

// ===== FUENTE 2: movi_zonas (una sola consulta masiva) =====
$sqlMovi = "SELECT DISTINCT LEFT(TRIM(a.tcencos), 3) AS g3, RIGHT(TRIM(a.tcencos), 3) AS c3
            FROM movi_zonas a
            WHERE TRIM(a.tcencos) IS NOT NULL
              AND TRIM(a.tcencos) <> ''
              AND CHAR_LENGTH(TRIM(a.tcencos)) >= 6
              AND LEFT(TRIM(a.tcencos), 3) IN ({$inClause3})
              AND RIGHT(TRIM(a.tcencos), 3) <> '000'";
if ($tieneFecha) {
    $sqlMovi .= " AND DATE(a.tfectra) >= '{$desdeEsc}' AND DATE(a.tfectra) <= '{$hastaEsc}'";
}
$q = mysqli_query($conn, $sqlMovi);
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $g3 = trim((string)($row['g3'] ?? ''));
        $c3 = trim((string)($row['c3'] ?? ''));
        if ($g3 !== '' && $c3 !== '') {
            $agregarCampania($g3, $c3, 'movi_zonas');
        }
    }
} else {
    error_log("MORT-CAMP: movi_zonas error: " . mysqli_error($conn));
}

// ===== FALLBACK: solo para granjas que quedaron sin datos =====
$keysSinDatos = array_keys(array_filter($byGranja, function ($v) { return empty($v); }));
if (!empty($keysSinDatos)) {
    $inFbParts = [];
    foreach ($keysSinDatos as $g3) {
        $inFbParts[] = "'" . mysqli_real_escape_string($conn, $g3) . "'";
    }
    $inFbClause = implode(',', $inFbParts);

    // Fallback 1: maes_zonas sin fecha
    $sqlFb1 = "SELECT DISTINCT LEFT(TRIM(tcencos), 3) AS g3, RIGHT(TRIM(tcencos), 3) AS c3
               FROM maes_zonas
               WHERE tcodigo IN ('P0001001','P0001002')
                 AND LEFT(TRIM(tcencos), 3) IN ({$inFbClause})
                 AND CHAR_LENGTH(TRIM(tcencos)) = 6
                 AND RIGHT(TRIM(tcencos), 3) <> '000'
                 AND fec_ing IS NOT NULL AND fec_ing > '1900-01-01'";
    $qFb1 = mysqli_query($conn, $sqlFb1);
    if ($qFb1) {
        while ($row = mysqli_fetch_assoc($qFb1)) {
            $g3 = trim((string)($row['g3'] ?? ''));
            $c3 = trim((string)($row['c3'] ?? ''));
            if ($g3 !== '' && $c3 !== '') {
                $agregarCampania($g3, $c3, 'maes_zonas_fb');
            }
        }
    } else {
        error_log("MORT-CAMP: maes_zonas_fb error: " . mysqli_error($conn));
    }

    // Fallback 2: movi_zonas (ultima campaña sin fecha)
    $keysAunSin = array_keys(array_filter($byGranja, function ($v) { return empty($v); }));
    if (!empty($keysAunSin)) {
        $inFb2Parts = [];
        foreach ($keysAunSin as $g3) {
            $inFb2Parts[] = "'" . mysqli_real_escape_string($conn, $g3) . "'";
        }
        $inFb2Clause = implode(',', $inFb2Parts);

        $sqlFb2 = "SELECT DISTINCT LEFT(TRIM(a.tcencos), 3) AS g3, RIGHT(TRIM(a.tcencos), 3) AS c3
                   FROM movi_zonas a
                   WHERE TRIM(a.tcencos) IS NOT NULL AND TRIM(a.tcencos) <> ''
                     AND CHAR_LENGTH(TRIM(a.tcencos)) >= 6
                     AND LEFT(TRIM(a.tcencos), 3) IN ({$inFb2Clause})
                     AND RIGHT(TRIM(a.tcencos), 3) <> '000'";
        $qFb2 = mysqli_query($conn, $sqlFb2);
        if ($qFb2) {
            while ($row = mysqli_fetch_assoc($qFb2)) {
                $g3 = trim((string)($row['g3'] ?? ''));
                $c3 = trim((string)($row['c3'] ?? ''));
                if ($g3 !== '' && $c3 !== '') {
                    $agregarCampania($g3, $c3, 'movi_zonas_fb');
                }
            }
        } else {
            error_log("MORT-CAMP: movi_zonas_fb error: " . mysqli_error($conn));
        }
    }
}

// ===== GALPONES POR GRANJA (para el modal multi) =====
$galponesPorGranja = [];
foreach ($keys as $g3) {
    $galponesPorGranja[$g3] = [];
}
if (!empty($keys)) {
    $inGpParts = [];
    foreach ($keys as $g3) {
        $inGpParts[] = "'" . mysqli_real_escape_string($conn, $g3) . "'";
    }
    $inGpClause = implode(',', $inGpParts);

    // Consultar ccos + regcencosgalpones (mismo patrón que get_galpones_graficas.php).
    $qGp = mysqli_query($conn, "
        SELECT DISTINCT LEFT(c.codigo, 3) AS g3, TRIM(g.tcodint) AS galpon
        FROM ccos c
        INNER JOIN regcencosgalpones g ON LEFT(c.codigo, 3) = g.tcencos
        WHERE LEFT(c.codigo, 3) IN ({$inGpClause})
          AND c.swac = 'A'
          AND CHAR_LENGTH(c.codigo) = 6
          AND RIGHT(c.codigo, 3) <> '000'
          AND TRIM(g.tcodint) <> ''
    ");
    if ($qGp) {
        while ($row = mysqli_fetch_assoc($qGp)) {
            $g3 = trim((string) ($row['g3'] ?? ''));
            $galpon = trim((string) ($row['galpon'] ?? ''));
            if ($g3 !== '' && $galpon !== '' && isset($galponesPorGranja[$g3])) {
                $galponesPorGranja[$g3][] = $galpon;
            }
        }
    }

    // Fallback: galpones desde movi_zonas para granjas sin datos en ccos.
    $keysSinGp = array_keys(array_filter($galponesPorGranja, function ($v) { return empty($v); }));
    if (!empty($keysSinGp)) {
        $inFbParts = [];
        foreach ($keysSinGp as $g3) {
            $inFbParts[] = "'" . mysqli_real_escape_string($conn, $g3) . "'";
        }
        $inFbClause = implode(',', $inFbParts);
        $qFb = mysqli_query($conn, "
            SELECT DISTINCT LEFT(TRIM(mz.tcencos), 3) AS g3, TRIM(mz.tcodint) AS galpon
            FROM movi_zonas mz
            WHERE LEFT(TRIM(mz.tcencos), 3) IN ({$inFbClause})
              AND TRIM(mz.tcodtra) IN ('S808','S700')
              AND TRIM(mz.tcodint) <> ''
        ");
        if ($qFb) {
            while ($row = mysqli_fetch_assoc($qFb)) {
                $g3 = trim((string) ($row['g3'] ?? ''));
                $galpon = trim((string) ($row['galpon'] ?? ''));
                if ($g3 !== '' && $galpon !== '' && isset($galponesPorGranja[$g3])) {
                    $galponesPorGranja[$g3][] = $galpon;
                }
            }
        }
    }

    foreach ($galponesPorGranja as $g3 => &$glist) {
        $glist = array_values(array_unique($glist));
        sort($glist, SORT_NUMERIC);
    }
    unset($glist);
}

$conn->close();

echo json_encode([
    'success' => true,
    'by_granja' => $byGranja,
    'galpones_por_granja' => $galponesPorGranja,
]);
