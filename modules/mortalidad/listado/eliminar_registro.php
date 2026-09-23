<?php
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

$id = trim((string) ($_POST['id'] ?? ''));
if ($id === '') {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'ID no valido']);
    exit;
}

$idEsc = mysqli_real_escape_string($conn, $id);

$codUsuario = (string) ($_SESSION['usuario'] ?? 'unknown');
$nomUsuario = (string) ($_SESSION['nombre'] ?? $_SESSION['nombre_completo'] ?? $_SESSION['usuario'] ?? 'Usuario desconocido');

mysqli_begin_transaction($conn);

try {
    $qCabVal = mysqli_query($conn, "SELECT tfecrem AS fechaRegistro FROM cabe_zonas WHERE external_id = '{$idEsc}' LIMIT 1");
    if ($qCabVal && ($rowCabVal = mysqli_fetch_assoc($qCabVal))) {
        mort_listado_validar_fecha_cierre_para_eliminacion($conn, (string) ($rowCabVal['fechaRegistro'] ?? ''), $codUsuario);
    } else {
        $qFechaFact = mysqli_query($conn, "SELECT fechaRegistro FROM san_fact_mortalidad_cab WHERE id = '{$idEsc}' LIMIT 1");
        if ($qFechaFact && ($rowFechaFact = mysqli_fetch_assoc($qFechaFact))) {
            mort_listado_validar_fecha_cierre_para_eliminacion($conn, (string) ($rowFechaFact['fechaRegistro'] ?? ''), $codUsuario);
        }
    }

    $cabElim = mort_listado_cabecera_desde_zonas($conn, $id);
    if ($cabElim) {
        mort_listado_validar_cenco_activo_para_eliminacion(
            $conn,
            (string) ($cabElim['granja'] ?? ''),
            (string) ($cabElim['campania'] ?? ''),
            $codUsuario
        );
    }

    $datosPrevios = mort_listado_eliminar_registro_completo($conn, $id);
    $datosPreviosJson = json_encode($datosPrevios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    mysqli_commit($conn);
    mysqli_close($conn);

    // Historial de acciones
    try {
        require_once __DIR__ . '/../historial/mortalidad_historial_lib.php';
        $desc = mort_hist_descripcion_amigable('ELIMINACION_MORTALIDAD_COMPLETA', $datosPrevios);
        registrarAccionCRUD(
            'ELIMINACION_MORTALIDAD_COMPLETA',
            $codUsuario,
            $nomUsuario,
            'san_fact_mortalidad_cab',
            $id,
            $datosPreviosJson,
            null,
            $desc
        );
    } catch (Throwable $eHist) {
        error_log('Error al registrar historial de eliminación mortalidad: ' . $eHist->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Registro eliminado correctamente.',
        'historial_guardado' => true,
    ]);

} catch (Throwable $e) {
    mysqli_rollback($conn);
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
