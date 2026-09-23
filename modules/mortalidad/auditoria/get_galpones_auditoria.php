<?php
session_start();
if (empty($_SESSION['active'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/../../../../conexion_grs/conexion.php';
$conn = conectar_joya_mysqli();
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Error de conexion']);
    exit;
}

mysqli_set_charset($conn, 'latin1');

$granja = trim((string) ($_GET['granja'] ?? ''));
$galpones = [];

if ($granja !== '') {
    $g3 = substr(trim($granja), 0, 3);
    if ($g3 === '') { $g3 = $granja; }
    $gEsc = mysqli_real_escape_string($conn, $g3);
    // Consultar ccos + regcencosgalpones (mismo patrón que flutter/necropcias)
    $q = mysqli_query($conn, "
        SELECT DISTINCT TRIM(g.tcodint) AS galpon
        FROM ccos c
        INNER JOIN regcencosgalpones g ON LEFT(c.codigo, 3) = g.tcencos
        WHERE c.codigo LIKE '{$gEsc}%'
          AND c.swac = 'A'
          AND CHAR_LENGTH(c.codigo) = 6
          AND RIGHT(c.codigo, 3) <> '000'
          AND TRIM(g.tcodint) <> ''
        ORDER BY ABS(g.tcodint) ASC
    ");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $galpones[] = trim((string) $row['galpon']);
        }
    }
    // Fallback: si no hay galpones en ccos, consultar directamente en movi_zonas
    if (empty($galpones)) {
        $q2 = mysqli_query($conn, "
            SELECT DISTINCT TRIM(mz.tcodint) AS galpon
            FROM movi_zonas mz
            WHERE LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}'
              AND mz.mark IN ('JI1', 'JT2', 'JP3', 'JD4')
              AND TRIM(mz.tcodint) <> ''
            ORDER BY ABS(mz.tcodint) ASC
        ");
        if ($q2) {
            while ($r2 = mysqli_fetch_assoc($q2)) {
                $galpones[] = trim((string) $r2['galpon']);
            }
        }
    }
}

mysqli_close($conn);
echo json_encode(['success' => true, 'galpones' => $galpones], JSON_UNESCAPED_UNICODE);
