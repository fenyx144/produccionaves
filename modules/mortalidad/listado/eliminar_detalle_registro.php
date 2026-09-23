<?php

declare(strict_types=1);

session_start();
if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/../../../../conexion_grs/conexion.php';
require_once __DIR__ . '/../../../core/lib/historial_acciones.php';
require_once __DIR__ . '/mortalidad_listado_lib.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexion']);
    exit;
}

mort_listado_require_editar_eliminar($conn);

$detId = trim((string) ($_POST['detId'] ?? ''));
$cabId = trim((string) ($_POST['cabId'] ?? ''));
if ($detId === '' || $cabId === '') {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Parametros invalidos']);
    exit;
}

$detIdEsc = mysqli_real_escape_string($conn, $detId);
$cabIdEsc = mysqli_real_escape_string($conn, $cabId);

$codUsuario = (string) ($_SESSION['usuario'] ?? 'unknown');
$nomUsuario = (string) ($_SESSION['nombre'] ?? $_SESSION['nombre_completo'] ?? $_SESSION['usuario'] ?? 'Usuario desconocido');

// El detId viene como {uuid}-{posicion}-{M|H}
if (!preg_match('/^(.*)-(\d{1,4})-([MH])$/i', $detId, $m)) {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Identificador de detalle invalido']);
    exit;
}
$posicion = (int) $m[2];
$sexoDet = strtoupper($m[3]);
if ($posicion <= 0) {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Posicion invalida']);
    exit;
}
$tcodigoSexo = $sexoDet === 'H' ? 'P0001002' : 'P0001001';
$tcodigoEsc = mysqli_real_escape_string($conn, $tcodigoSexo);

// Cabecera (mapeada desde zonas) para fecha y snapshot.
$cabMapped = mort_listado_cabecera_desde_zonas($conn, $cabId);
if (!$cabMapped) {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Registro no encontrado']);
    exit;
}

// Línea movi_zonas a eliminar (para validar existencia y snapshot).
$detRows = [];
$qDet = mysqli_query($conn, "
    SELECT mz.* FROM movi_zonas mz
    INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
        AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
    WHERE cz.external_id = '{$cabIdEsc}'
      AND mz.idmovi = {$posicion}
      AND mz.tcodigo = '{$tcodigoEsc}'
");
if ($qDet) {
    while ($row = mysqli_fetch_assoc($qDet)) {
        $detRows[] = $row;
    }
}
if ($detRows === []) {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Detalle no encontrado']);
    exit;
}

// Total de líneas movi_zonas del registro.
$nDetalles = 0;
$qCuenta = mysqli_query($conn, "
    SELECT COUNT(*) AS n FROM movi_zonas mz
    INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
        AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
    WHERE cz.external_id = '{$cabIdEsc}'
");
if ($qCuenta && ($rCuenta = mysqli_fetch_assoc($qCuenta))) {
    $nDetalles = (int) ($rCuenta['n'] ?? 0);
}

if ($nDetalles <= 1) {
    mysqli_begin_transaction($conn);
    try {
        mort_listado_validar_fecha_cierre_para_eliminacion($conn, (string) ($cabMapped['fechaRegistro'] ?? ''), $codUsuario);
        mort_listado_validar_cenco_activo_para_eliminacion(
            $conn,
            (string) ($cabMapped['granja'] ?? ''),
            (string) ($cabMapped['campania'] ?? ''),
            $codUsuario
        );
        $datosPrevios = mort_listado_eliminar_registro_completo($conn, $cabId);
        $datosPreviosJson = json_encode($datosPrevios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        mysqli_commit($conn);
        mysqli_close($conn);

        try {
            require_once __DIR__ . '/../historial/mortalidad_historial_lib.php';
            $desc = mort_hist_descripcion_amigable('ELIMINACION_MORTALIDAD_COMPLETA', $datosPrevios);
            registrarAccionCRUD(
                'ELIMINACION_MORTALIDAD_COMPLETA',
                $codUsuario,
                $nomUsuario,
                'san_fact_mortalidad_cab',
                $cabId,
                $datosPreviosJson,
                null,
                $desc
            );
        } catch (Throwable $eHist) {
            error_log('Error al registrar historial de eliminación mortalidad: ' . $eHist->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => 'Se elimino el registro completo al no quedar lineas de detalle.',
            'registro_eliminado' => true,
            'historial_guardado' => true,
        ]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        mysqli_close($conn);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Detalle mapeado (contrato san_fact_mortalidad_det) para snapshot/historial.
$detalleMapped = mort_listado_detalle_desde_zonas($conn, $cabId);
$detMapped = null;
foreach ($detalleMapped as $d) {
    if ((int) ($d['posicion'] ?? 0) === $posicion && strtoupper(substr(trim((string) ($d['sexo'] ?? 'M')), 0, 1)) === $sexoDet) {
        $detMapped = $d;
        break;
    }
}

$datosPrevios = [
    'san_fact_mortalidad_cab' => $cabMapped,
    'san_fact_mortalidad_det' => $detMapped !== null ? [$detMapped] : [],
    'cabe_zonas' => [],
    'movi_zonas' => $detRows,
    'san_mortalidad_auditoria' => [],
];

$audIdsUnicos = [];
foreach ($detRows as $row) {
    $audRef = trim((string) ($row['internal_auditoria'] ?? ''));
    if ($audRef !== '') {
        $audIdsUnicos[$audRef] = true;
    }
}
foreach (array_keys($audIdsUnicos) as $audIdSnap) {
    $audIdEscSnap = mysqli_real_escape_string($conn, $audIdSnap);
    $qAudSnap = mysqli_query($conn, "SELECT * FROM san_mortalidad_auditoria WHERE id = '{$audIdEscSnap}' LIMIT 1");
    if ($qAudSnap && ($audSnap = mysqli_fetch_assoc($qAudSnap))) {
        $datosPrevios['san_mortalidad_auditoria'][] = $audSnap;
    }
}
$datosPreviosJson = json_encode($datosPrevios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

mysqli_begin_transaction($conn);

try {
    mort_listado_validar_fecha_cierre_para_eliminacion($conn, (string) ($cabMapped['fechaRegistro'] ?? ''), $codUsuario);

    mort_listado_validar_cenco_activo_para_eliminacion(
        $conn,
        (string) ($cabMapped['granja'] ?? ''),
        (string) ($cabMapped['campania'] ?? ''),
        $codUsuario
    );

    // Eliminar la línea en movi_zonas.
    $okMz = mysqli_query($conn, "
        DELETE mz FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id = '{$cabIdEsc}'
          AND mz.idmovi = {$posicion}
          AND mz.tcodigo = '{$tcodigoEsc}'
    ");
    if (!$okMz) {
        throw new RuntimeException('Error al eliminar movi_zonas: ' . mysqli_error($conn));
    }

    // Recalcular tcanttot en cabe_zonas.
    $nuevoTot = 0;
    $qSum = mysqli_query($conn, "
        SELECT COALESCE(SUM(mz.tcantid), 0) AS total FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id = '{$cabIdEsc}'
    ");
    if ($qSum && ($rSum = mysqli_fetch_assoc($qSum))) {
        $nuevoTot = (int) ($rSum['total'] ?? 0);
    }
    mysqli_query($conn, "UPDATE cabe_zonas SET tcanttot = {$nuevoTot} WHERE external_id = '{$cabIdEsc}'");

    mysqli_commit($conn);
    mysqli_close($conn);

    try {
        require_once __DIR__ . '/../historial/mortalidad_historial_lib.php';
        $desc = mort_hist_descripcion_amigable('ELIMINACION_MORTALIDAD_DETALLE', $datosPrevios);
        registrarAccionCRUD(
            'ELIMINACION_MORTALIDAD_DETALLE',
            $codUsuario,
            $nomUsuario,
            'san_fact_mortalidad_det',
            $detId,
            $datosPreviosJson,
            null,
            $desc
        );
    } catch (Throwable $eHist) {
        error_log('Error al registrar historial de eliminación detalle mortalidad: ' . $eHist->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Linea de detalle eliminada correctamente.',
        'posicion' => $posicion,
        'historial_guardado' => true,
    ]);

} catch (Throwable $e) {
    mysqli_rollback($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
