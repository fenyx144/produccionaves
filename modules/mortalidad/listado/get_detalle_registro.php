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

$id = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
if ($id === '') {
    mort_admin_json_error('ID no válido.');
}

$codigoUsuario = trim((string) ($_SESSION['usuario'] ?? ''));

$cab = mort_listado_cabecera_desde_zonas($conn, $id);
if (!$cab) {
    mysqli_close($conn);
    mort_admin_json_error('Registro no encontrado.', 404);
}

$det = mort_listado_detalle_desde_zonas($conn, $id);

// Bloquear edición de registros cuyo periodo contable (año/mes/día) está cerrado.
$cab['fechaCerrada'] = false;
$cab['motivoCierre'] = '';
$fechaRegistroCab = trim((string) ($cab['fechaRegistro'] ?? ''));
if ($fechaRegistroCab !== '') {
    try {
        mort_listado_validar_fecha_cierre($conn, $fechaRegistroCab);
    } catch (Throwable $e) {
        $cab['fechaCerrada'] = true;
        $cab['motivoCierre'] = $e->getMessage();
    }
}

// Bloquear edición si la campaña (cenco granja+campaña) ya está cerrada en ccos.
$cab['campaniaCerrada'] = false;
$cab['motivoCampaniaCerrada'] = '';
$g3Cab = trim((string) ($cab['granja'] ?? ''));
$c3Cab = trim((string) ($cab['campania'] ?? ''));
if ($g3Cab !== '' && $c3Cab !== '') {
    if (!mort_listado_cenco_activo($conn, $g3Cab, $c3Cab)) {
        $cab['campaniaCerrada'] = true;
        $cab['motivoCampaniaCerrada'] = 'La campaña ' . $g3Cab . '-' . $c3Cab . ' ya no está activa (catálogo de centros).';
    }
}

mysqli_close($conn);

$granja3 = trim((string) ($cab['granja'] ?? ''));
$nombreGranja = trim((string) ($cab['granjaNombre'] ?? ''));
// Si la campaña ya no está activa, el nombre de ccos trae la campaña actual
// embebida (ej. "GRANJA X C=195"). Mostrar solo el nombre limpio de granja.
if (!empty($cab['campaniaCerrada'])) {
    $nombreGranja = mort_listado_limpiar_nombre_granja($nombreGranja);
}

$cab['nombreUsuario'] = mort_listado_format_usuario_display(
    (string) ($cab['nombreUsuario'] ?? ''),
    (string) ($cab['usuarioRegistro'] ?? '')
);
$cab['tipoLabel'] = mort_listado_label_tipo((string) ($cab['tipoMortalidad'] ?? ''));
$cab['subtipoLabel'] = mort_listado_label_subtipo(
    (string) ($cab['tipoMortalidad'] ?? ''),
    $cab['subtipoTransporte'] ?? null,
    $cab['subtipoProduccion'] ?? null
);
$cab['nombreGranja'] = $nombreGranja !== '' ? $nombreGranja : ($cab['granjaNombre'] ?? '');
$cab['codigoGranja'] = $granja3;
$cab['codigoCampania'] = trim((string) ($cab['campania'] ?? ''));
$cab['muestraCausa'] = mort_listado_muestra_causa(
    (string) ($cab['tipoMortalidad'] ?? ''),
    $cab['subtipoTransporte'] ?? null,
    $cab['subtipoProduccion'] ?? null
);

mort_admin_json_ok([
    'cabecera' => $cab,
    'detalle' => $det,
    'detalleAgrupado' => mort_listado_agrupar_detalle($det),
]);
