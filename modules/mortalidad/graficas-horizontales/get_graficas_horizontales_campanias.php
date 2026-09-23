<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'by_granja' => [], 'galpones_por_granja' => [], 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../../../conexion_grs/conexion.php';
require_once __DIR__ . '/../../../core/lib/filtro_periodo_util.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'by_granja' => [], 'galpones_por_granja' => [], 'message' => 'Error de conexion']);
    exit;
}

$granjasBulk = trim((string) ($_GET['granjas'] ?? ''));
if ($granjasBulk === '') {
    echo json_encode(['success' => true, 'by_granja' => [], 'galpones_por_granja' => []]);
    $conn->close();
    exit;
}

// Normalizar granjas a 3 dígitos.
$keysMap = [];
foreach (preg_split('/\s*,\s*/', $granjasBulk) as $p) {
    $p = trim((string) $p);
    if ($p === '') {
        continue;
    }
    $g3 = substr(str_pad($p, 3, '0', STR_PAD_LEFT), 0, 3);
    $keysMap[$g3] = true;
}
$keys = array_keys($keysMap);
sort($keys);
$byGranja = [];
$galponesPorGranja = [];
foreach ($keys as $k) {
    $byGranja[$k] = [];
    $galponesPorGranja[$k] = [];
}

// Resolver rango de fechas del modal (vigencia de campañas).
$periodoTipo = trim((string) ($_GET['periodoTipo'] ?? 'TODOS'));
$rango = periodo_a_rango([
    'periodoTipo' => $periodoTipo,
    'fechaUnica' => trim((string) ($_GET['fechaUnica'] ?? '')),
    'fechaInicio' => trim((string) ($_GET['fechaInicio'] ?? '')),
    'fechaFin' => trim((string) ($_GET['fechaFin'] ?? '')),
    'mesUnico' => trim((string) ($_GET['mesUnico'] ?? '')),
    'mesInicio' => trim((string) ($_GET['mesInicio'] ?? '')),
    'mesFin' => trim((string) ($_GET['mesFin'] ?? '')),
]);

if ($periodoTipo !== 'TODOS' && $rango === null) {
    $conn->close();
    echo json_encode(['success' => true, 'by_granja' => $byGranja, 'galpones_por_granja' => $galponesPorGranja]);
    exit;
}

$desde = $rango ? $rango['desde'] : '1900-01-01';
$hasta = $rango ? $rango['hasta'] : '9999-12-31';
$tieneFecha = $rango !== null;

$inParts = [];
foreach ($keys as $g3) {
    $g3s = (string) $g3;
    $inParts[] = "'" . mysqli_real_escape_string($conn, $g3s) . "'";
}
$inClause3 = implode(',', $inParts);

// Solape de ciclo con el periodo: fecha_inicio <= hasta y (ciclo abierto o fecha_fin >= desde).
$sqlFecha = $tieneFecha
    ? " AND DATE(c.fecha_inicio) <= '" . mysqli_real_escape_string($conn, $hasta) . "'
      AND (c.fecha_fin IS NULL OR TRIM(c.fecha_fin) = '' OR c.fecha_fin = '0000-00-00'
           OR DATE(c.fecha_fin) >= '" . mysqli_real_escape_string($conn, $desde) . "')"
    : '';

// Campañas por granja desde san_fact_historia_clinica_cab.
$sqlCamp = "SELECT LPAD(LEFT(TRIM(c.granja), 3), 3, '0') AS granja3,
                   TRIM(c.campania) AS campania
            FROM san_fact_historia_clinica_cab c
            WHERE LPAD(LEFT(TRIM(c.granja), 3), 3, '0') IN ({$inClause3})
              AND c.campania IS NOT NULL AND TRIM(c.campania) <> '' AND TRIM(c.campania) <> '000'
              AND c.fecha_inicio IS NOT NULL AND TRIM(c.fecha_inicio) <> '' AND c.fecha_inicio <> '0000-00-00'
              {$sqlFecha}
            GROUP BY granja3, TRIM(c.campania)";
$q = mysqli_query($conn, $sqlCamp);
if ($q) {
    while ($row = mysqli_fetch_assoc($q)) {
        $g3 = trim((string) ($row['granja3'] ?? ''));
        $c3 = substr(str_pad(trim((string) ($row['campania'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
        if ($g3 === '' || $c3 === '' || $c3 === '000' || !isset($byGranja[$g3])) {
            continue;
        }
        $byGranja[$g3][] = ['codigo' => $g3 . $c3, 'campania' => $c3, 'fuente' => 'san_fact'];
    }
} else {
    error_log('GRAF-HZ-CAMP: san_fact_historia_clinica_cab error: ' . mysqli_error($conn));
}

// Galpones por granja desde san_fact_historia_clinica_cab (mismo solape de ciclo).
$sqlGalp = "SELECT LPAD(LEFT(TRIM(c.granja), 3), 3, '0') AS granja3,
                   TRIM(CAST(c.galpon AS CHAR)) AS galpon
            FROM san_fact_historia_clinica_cab c
            WHERE LPAD(LEFT(TRIM(c.granja), 3), 3, '0') IN ({$inClause3})
              AND c.galpon IS NOT NULL AND TRIM(CAST(c.galpon AS CHAR)) <> '' AND TRIM(CAST(c.galpon AS CHAR)) <> '0'
              AND c.fecha_inicio IS NOT NULL AND TRIM(c.fecha_inicio) <> '' AND c.fecha_inicio <> '0000-00-00'
              {$sqlFecha}
            GROUP BY granja3, TRIM(CAST(c.galpon AS CHAR))";
$q2 = mysqli_query($conn, $sqlGalp);
if ($q2) {
    while ($row = mysqli_fetch_assoc($q2)) {
        $g3 = trim((string) ($row['granja3'] ?? ''));
        $galpon = trim((string) ($row['galpon'] ?? ''));
        if ($g3 !== '' && $galpon !== '' && isset($galponesPorGranja[$g3])) {
            $galponesPorGranja[$g3][] = $galpon;
        }
    }
} else {
    error_log('GRAF-HZ-GALP: san_fact_historia_clinica_cab error: ' . mysqli_error($conn));
}

foreach ($galponesPorGranja as $g3 => &$glist) {
    $glist = array_values(array_unique($glist));
    usort($glist, static function ($a, $b): int {
        if (ctype_digit((string) $a) && ctype_digit((string) $b)) {
            return (int) $a <=> (int) $b;
        }

        return strnatcasecmp((string) $a, (string) $b);
    });
}
unset($glist);

$conn->close();

echo json_encode([
    'success' => true,
    'by_granja' => $byGranja,
    'galpones_por_granja' => $galponesPorGranja,
]);
