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
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

// Solo usuarios con rol Sistemas (o KAREN) pueden eliminar registros del historial.
mort_listado_require_editar_eliminar($conn);

if (!mort_hist_tabla_existe($conn)) {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'La tabla san_dim_historial_acciones no existe.']);
    exit;
}

$id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    mysqli_close($conn);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit;
}

// Mismo criterio del listado: solo acciones de mortalidad y de usuarios sin rol Sistemas.
$acciones = mort_hist_acciones_validas();
$inParts = [];
foreach ($acciones as $a) {
    $inParts[] = "'" . mysqli_real_escape_string($conn, $a) . "'";
}
$inList = implode(',', $inParts);

$sql = "DELETE h FROM san_dim_historial_acciones h
        WHERE h.id = ?
          AND h.accion IN ({$inList})";
$excluirSistemas = mort_hist_sql_excluir_rol_sistemas($conn);
if ($excluirSistemas !== '') {
    $sql .= ' AND ' . $excluirSistemas;
}

$st = $conn->prepare($sql);
if (!$st) {
    mysqli_close($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al preparar la eliminación']);
    exit;
}
$st->bind_param('i', $id);
$ok = $st->execute();
$err = $st->error;
$affected = $st->affected_rows;
$st->close();
mysqli_close($conn);

if (!$ok) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar' . ($err !== '' ? ': ' . $err : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($affected < 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Registro no encontrado o no autorizado para eliminar'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Registro de historial eliminado'], JSON_UNESCAPED_UNICODE);
