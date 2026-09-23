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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

$filtros = mort_listado_parse_filtros($_GET);
$granja = trim((string) ($filtros['granja'] ?? ''));
$campania = trim((string) ($filtros['campania'] ?? ''));

$tipos = mort_admin_tipos_mortalidad();

$granjas = mort_listado_opciones_granjas_hc($conn);
$campanias = $granja !== '' ? mort_listado_opciones_campanias_hc($conn, $granja, $filtros) : [];
if ($granja !== '') {
    if ($campania !== '') {
        $galpones = mort_listado_opciones_galpones($conn, $granja, $campania);
    } else {
        // Sin campaña: cargar galpones desde ccos + regcencosgalpones (mismo patrón que flutter/necropcias)
        $g3 = substr(trim($granja), 0, 3);
        if ($g3 !== '') {
            $gEsc = mysqli_real_escape_string($conn, $g3);
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
            $galpones = [];
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
        } else {
            $galpones = [];
        }
    }
} else {
    $galpones = [];
}

mysqli_close($conn);

mort_admin_json_ok([
    'granjas' => $granjas,
    'campanias' => $campanias,
    'galpones' => $galpones,
    'tipos' => $tipos,
    'subtiposTransporte' => mort_listado_subtipos_transporte(),
    'subtiposProduccion' => mort_listado_subtipos_produccion(),
]);
