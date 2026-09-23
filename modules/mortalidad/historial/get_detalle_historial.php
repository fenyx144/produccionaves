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

require_once __DIR__ . '/mortalidad_historial_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

if (!mort_hist_tabla_existe($conn)) {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Tabla de historial no disponible']);
    exit;
}

$acciones = mort_hist_acciones_validas();
$inParts = [];
foreach ($acciones as $a) {
    $inParts[] = "'" . mysqli_real_escape_string($conn, $a) . "'";
}
$inList = implode(',', $inParts);

$sql = "
    SELECT
        h.id,
        h.cod_usuario,
        h.nom_usuario,
        h.accion,
        h.tabla_afectada,
        h.registro_id,
        h.descripcion,
        h.fechaHora,
        h.datos_previos,
        h.datos_nuevos
    FROM san_dim_historial_acciones h
    WHERE h.id = {$id}
      AND h.accion IN ({$inList})
    LIMIT 1
";

$q = mysqli_query($conn, $sql);
if (!$q || !($row = mysqli_fetch_assoc($q))) {
    mysqli_close($conn);
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Registro no encontrado']);
    exit;
}
mysqli_close($conn);

$accion = (string) ($row['accion'] ?? '');
$meta = mort_hist_acciones_meta()[$accion] ?? [
    'label' => $accion,
    'icon' => 'fas fa-info-circle',
    'color' => 'text-gray-600',
    'badge' => 'bg-gray-50 text-gray-700 border-gray-200',
];

$previos = null;
$nuevos = null;
if (!empty($row['datos_previos'])) {
    $previos = json_decode((string) $row['datos_previos'], true);
}
if (!empty($row['datos_nuevos'])) {
    $nuevos = json_decode((string) $row['datos_nuevos'], true);
}
if (is_array($previos)) {
    $previos = mort_hist_enrich_snapshot_for_ui($previos);
}
if (is_array($nuevos)) {
    $nuevos = mort_hist_enrich_snapshot_for_ui($nuevos);
}

$snapSrc = $nuevos ?: $previos;
$resumenSnap = mort_hist_resumen_snapshot($snapSrc);
$descCorta = mort_hist_descripcion_amigable($accion, $snapSrc);

echo json_encode([
    'success' => true,
    'registro' => [
        'id' => (int) $row['id'],
        'cod_usuario' => (string) ($row['cod_usuario'] ?? ''),
        'nom_usuario' => (string) ($row['nom_usuario'] ?? ''),
        'accion' => $accion,
        'accionLabel' => mort_hist_label_accion_dinamica($accion, $snapSrc),
        'accionIcon' => $meta['icon'],
        'accionColor' => $meta['color'],
        'accionBadge' => $meta['badge'],
        'tabla_afectada' => (string) ($row['tabla_afectada'] ?? ''),
        'registro_id' => (string) ($row['registro_id'] ?? ''),
        'descripcion' => $descCorta !== '' ? $descCorta : (string) ($row['descripcion'] ?? ''),
        'fechaHora' => (string) ($row['fechaHora'] ?? ''),
        'resumen' => $resumenSnap,
        'datos_previos' => is_array($previos) ? $previos : null,
        'datos_nuevos' => is_array($nuevos) ? $nuevos : null,
    ],
], JSON_UNESCAPED_UNICODE);
