<?php
declare(strict_types=1);

/**
 * Galpones para el filtro de gráficas (granja + campaña opcional).
 * Mismo patrón que get_galpones_informe_contable.php / get_galpones_auditoria.php.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

include_once __DIR__ . '/../../../../conexion_grs/conexion.php';
$conn = conectar_joya_mysqli();
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Error de conexion']);
    exit;
}

mysqli_set_charset($conn, 'latin1');

$granja = trim((string) ($_GET['granja'] ?? ''));
$campania = trim((string) ($_GET['campania'] ?? ''));
$galpones = [];

if ($granja !== '') {
    $g3 = substr(str_pad(preg_replace('/\D/', '', $granja) ?? '', 3, '0', STR_PAD_LEFT), 0, 3);
    if ($g3 === '') {
        $g3 = $granja;
    }
    $gEsc = mysqli_real_escape_string($conn, $g3);

    // Consultar ccos + regcencosgalpones (mismo patrón que flutter/necropsias).
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

    // Fallback: si no hay galpones en ccos, consultar directamente en movi_zonas.
    if (empty($galpones)) {
        $cEsc = $campania !== '' ? mysqli_real_escape_string($conn, $campania) : '';
        $sql2 = "
            SELECT DISTINCT TRIM(mz.tcodint) AS galpon
            FROM movi_zonas mz
            WHERE LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}'
              AND TRIM(mz.tcodtra) IN ('S808','S700')
              AND TRIM(mz.tcodint) <> ''
        ";
        if ($cEsc !== '') {
            $sql2 .= " AND CHAR_LENGTH(TRIM(mz.tcencos)) >= 3 AND RIGHT(TRIM(mz.tcencos), 3) = '{$cEsc}' ";
        }
        $sql2 .= ' ORDER BY ABS(mz.tcodint) ASC';
        $q2 = mysqli_query($conn, $sql2);
        if ($q2) {
            while ($r2 = mysqli_fetch_assoc($q2)) {
                $galpones[] = trim((string) $r2['galpon']);
            }
        }
    }

    $galpones = array_values(array_unique($galpones));
}

mysqli_close($conn);
echo json_encode(['success' => true, 'galpones' => $galpones], JSON_UNESCAPED_UNICODE);
