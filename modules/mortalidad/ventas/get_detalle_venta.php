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

require_once __DIR__ . '/mortalidad_ventas_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

$fecha = trim((string) ($_GET['fecha'] ?? ''));
$granja = trim((string) ($_GET['granja'] ?? ''));
$campania = trim((string) ($_GET['campania'] ?? ''));
$galpon = trim((string) ($_GET['galpon'] ?? ''));

$fechaOk = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha);
if (!$fechaOk || $granja === '' || $campania === '' || $galpon === '') {
    mysqli_close($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos para el detalle.']);
    exit;
}

$g3 = substr(str_pad($granja, 3, '0', STR_PAD_LEFT), 0, 3);
$c3 = substr(str_pad($campania, 3, '0', STR_PAD_LEFT), 0, 3);
$cenco = $g3 . $c3;

$cencoEsc = mysqli_real_escape_string($conn, $cenco);
$fechaEsc = mysqli_real_escape_string($conn, $fecha);
$galEsc = mysqli_real_escape_string($conn, $galpon);

// Optimización: predicados "sargables" sin TRIM()/DATE()/CAST() sobre las
// columnas del índice compuesto fci (tcencos, tcodint, tfectra). tfectra es
// DATE, así que la comparación es directa; tcencos/tcodint/tcodtra se guardan
// sin espacios.
// Criterios: venta = S700 de pollos (P0001001 = Macho / P0001002 = Hembra);
// mortalidad = TODOS los S808 de pollos del día (todo tcategoria/flujo:
// 'P' antiguos, 'Despacho' modernos, 'Produccion', 'Transporte', etc.).
//
// La etapa de cada registro de mortalidad (Incubación / Transporte / Crianza /
// Despacho) se deduce de su código de causa (tcod_mortgrs), igual que en
// sanidad/get_motivos_mortalidad.php.

// ── Venta del día: suma general por sexo (no se listan las ventas una a una) ──
$sqlVenta = "
    SELECT
        COALESCE(SUM(mz.tcantid), 0) AS venta_total,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS venta_macho,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS venta_hembra
    FROM movi_zonas mz
    WHERE mz.tcencos = '{$cencoEsc}'
      AND mz.tfectra = '{$fechaEsc}'
      AND mz.tcodint = '{$galEsc}'
      AND mz.tcodtra = 'S700'
      AND mz.tcantid > 0
      AND TRIM(mz.tcodigo) IN ('P0001001','P0001002')
";

$ventaTotal = 0;
$ventaMacho = 0;
$ventaHembra = 0;

$qVenta = mysqli_query($conn, $sqlVenta);
if (!$qVenta) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar la venta del día: ' . $err]);
    exit;
}
$rowVenta = mysqli_fetch_assoc($qVenta);
$ventaTotal = (int) ($rowVenta['venta_total'] ?? 0);
$ventaMacho = (int) ($rowVenta['venta_macho'] ?? 0);
$ventaHembra = (int) ($rowVenta['venta_hembra'] ?? 0);

// ── Mortalidad del día: TODOS los S808 de pollos del día, con su etapa
// (Incubación/Transporte/Crianza/Despacho) deducida del código de causa.
$sqlMort = "
    SELECT
        NULLIF(TRIM(CAST(mz.tnumfac AS CHAR)), '') AS numfac,
        NULLIF(TRIM(mz.tserie), '') AS serie,
        CASE
            WHEN mz.tcodigo = 'P0001001' THEN 'Macho'
            WHEN mz.tcodigo = 'P0001002' THEN 'Hembra'
            ELSE NULLIF(TRIM(mz.tcodigo), '')
        END AS tipo,
        mz.tcantid AS cantidad,
        NULLIF(TRIM(mz.tcod_mortgrs), '') AS codMort,
        NULLIF(TRIM(r.tnom_mort), '') AS nomMort,
        UPPER(TRIM(COALESCE(mz.tcategoria, ''))) AS tcategoria,
        UPPER(TRIM(COALESCE(mz.flujo, ''))) AS flujo
    FROM movi_zonas mz
    LEFT JOIN regmotivo_mortalidadgrs r ON r.tcod_mort = mz.tcod_mortgrs
    WHERE mz.tcencos = '{$cencoEsc}'
      AND mz.tfectra = '{$fechaEsc}'
      AND mz.tcodint = '{$galEsc}'
      AND mz.tcodtra = 'S808'
      AND mz.tcantid > 0
      AND TRIM(mz.tcodigo) IN ('P0001001','P0001002')
    ORDER BY mz.tnumfac ASC,
             mz.tcodigo ASC
";

// Clasificación de la etapa (Incubación/Transporte/Crianza/Despacho) según el
// código de la causa (mismo criterio que sanidad/get_motivos_mortalidad.php).
// Como respaldo, cuando la causa no clasifica se usa la tcategoria/flujo del
// movimiento (p. ej. registros de PlantaIncubacion suelen venir sin causa).
$etapaOrden = ['incubacion' => 0, 'transporte' => 1, 'crianza' => 2, 'despacho' => 3, '' => 4];

/** @return string clave de etapa: incubacion|transporte|crianza|despacho|'' */
function mort_ventas_etapa_de_fila(string $codMort, string $tcategoria, string $flujo): string
{
    $cod = str_pad(trim($codMort), 2, '0', STR_PAD_LEFT);
    $porCausa = [
        'transporte' => ['01', '02'],
        'despacho'   => ['05', '14', '15', '17', '18', '19'],
        'crianza'    => ['03', '04', '06', '07', '08', '09', '10', '11', '12', '13', '16'],
    ];
    foreach ($porCausa as $etapa => $cods) {
        if ($cod !== '' && in_array($cod, $cods, true)) {
            return $etapa;
        }
    }
    $tc = strtoupper(trim($tcategoria));
    $fl = strtoupper(trim($flujo));
    if ($tc === 'PLANTAINCUBACION' || $fl === 'PLANTAINCUBACION') {
        return 'incubacion';
    }
    if ($tc === 'TRANSPORTE' || $fl === 'TRANSPORTE') {
        return 'transporte';
    }
    if ($tc === 'PRODUCCION' || $fl === 'CRIANZA' || $tc === 'P') {
        return 'crianza';
    }
    if ($tc === 'DESPACHO' || $fl === 'DESPACHO') {
        return 'despacho';
    }

    return '';
}

$mortalidades = [];
$mortalidadTotal = 0;
$mortMacho = 0;
$mortHembra = 0;

$qMort = mysqli_query($conn, $sqlMort);
if (!$qMort) {
    $err = mysqli_error($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar la mortalidad del día: ' . $err]);
    exit;
}

while ($row = mysqli_fetch_assoc($qMort)) {
    $cant = (int) ($row['cantidad'] ?? 0);
    $mortalidadTotal += $cant;
    $esMacho = (trim((string) ($row['tipo'] ?? '')) === 'Macho');
    if ($esMacho) {
        $mortMacho += $cant;
    } else {
        $mortHembra += $cant;
    }
    $etapa = mort_ventas_etapa_de_fila(
        (string) ($row['codMort'] ?? ''),
        (string) ($row['tcategoria'] ?? ''),
        (string) ($row['flujo'] ?? '')
    );
    // Si ese sexo NO tuvo venta en el día, no puede haber mortalidad "de
    // despacho" (no hubo salida): esa mortalidad se muestra como "Crianza".
    $hayVentaDelSexo = $esMacho ? ($ventaMacho > 0) : ($ventaHembra > 0);
    if (!$hayVentaDelSexo && $etapa === 'despacho') {
        $etapa = 'crianza';
    }
    $mortalidades[] = [
        'numfac' => trim((string) ($row['numfac'] ?? '')),
        'serie' => trim((string) ($row['serie'] ?? '')),
        'tipo' => trim((string) ($row['tipo'] ?? '')),
        'cantidad' => $cant,
        'codMort' => trim((string) ($row['codMort'] ?? '')),
        'nomMort' => trim((string) ($row['nomMort'] ?? '')),
        'etapa' => $etapa,
    ];
}

// Orden: primero por sexo (Macho → Hembra) y luego por etapa
// (incubación → transporte → crianza → despacho).
usort($mortalidades, static function (array $a, array $b) use ($etapaOrden): int {
    $sexoA = ($a['tipo'] === 'Macho') ? 0 : 1;
    $sexoB = ($b['tipo'] === 'Macho') ? 0 : 1;
    if ($sexoA !== $sexoB) {
        return $sexoA <=> $sexoB;
    }
    $etA = $etapaOrden[$a['etapa']] ?? 4;
    $etB = $etapaOrden[$b['etapa']] ?? 4;
    if ($etA !== $etB) {
        return $etA <=> $etB;
    }

    return strcmp((string) ($a['numfac'] ?? ''), (string) ($b['numfac'] ?? ''));
});

// Nombre de granja (ccos con codigo = granja + '000').
$granjaNombre = '';
$qNom = mysqli_query($conn, "SELECT TRIM(cc.nombre) AS nombre FROM ccos cc WHERE cc.codigo = '{$g3}000' LIMIT 1");
if ($qNom && ($rowNom = mysqli_fetch_assoc($qNom))) {
    $granjaNombre = trim((string) ($rowNom['nombre'] ?? ''));
}

mysqli_close($conn);

echo json_encode([
    'success' => true,
    'fecha' => $fecha,
    'granja' => $g3,
    'granjaNombre' => $granjaNombre,
    'campania' => $c3,
    'galpon' => $galpon,
    'ventaTotal' => $ventaTotal,
    'ventaMacho' => $ventaMacho,
    'ventaHembra' => $ventaHembra,
    'mortalidadTotal' => $mortalidadTotal,
    'mortMacho' => $mortMacho,
    'mortHembra' => $mortHembra,
    'mortalidades' => $mortalidades,
], JSON_UNESCAPED_UNICODE);
