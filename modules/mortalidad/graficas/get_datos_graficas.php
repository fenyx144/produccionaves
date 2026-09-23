<?php
declare(strict_types=1);

/**
 * Endpoint JSON de gráficas de mortalidad.
 *
 * Recibe la lista de LOTES seleccionados en el modal (granja + campaña + galpones)
 * y devuelve una serie diaria por lote, desde el día 1 (día de campaña).
 *
 * Parámetros:
 *  - lotes     : JSON con [{granja, campania, galpones: []|[...]}, ...]
 *  - periodoTipo / fecha* / mes* : filtros de periodo
 *  - operacion : todos | mortalidad | venta
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/graficas_lib.php';
require_once __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

mysqli_set_charset($conn, 'utf8');
mysqli_query($conn, "SET time_zone = 'America/Lima'");

$filtros = mort_graficas_parse_filtros($_GET);
$lotes = mort_graficas_parse_lotes($_GET);
$rango = mort_graficas_rango_desde_filtros($filtros);
$operacion = (string) ($filtros['operacion'] ?? 'todos');

$respLotes = [];
foreach ($lotes as $lote) {
    $serie = mort_graficas_fetch_serie_lote($conn, $lote, $rango, $operacion);
    $galpones = (array) ($lote['galpones'] ?? []);
    $respLotes[] = [
        'granja' => (string) $lote['granja'],
        'campania' => (string) $lote['campania'],
        'galpones' => $galpones,
        'sexo' => (string) ($lote['sexo'] ?? 'todos'),
        'clave' => (string) $lote['granja'] . '-' . (string) $lote['campania'] . (count($galpones) === 1 ? '-' . (string) $galpones[0] : (count($galpones) > 1 ? '-G' . implode('-', $galpones) : '-TODOS')),
        'fechaInicio' => $serie['fechaInicio'],
        'puntos' => $serie['puntos'],
    ];
}

// Nombres de granja para el texto de contexto.
$nombresGranja = [];
try {
    if (function_exists('hc_granjas_listar_para_selector')) {
        foreach (hc_granjas_listar_para_selector($conn) as $g) {
            $nombresGranja[(string) ($g['granja'] ?? '')] = (string) ($g['nombre_granja'] ?? $g['nombre'] ?? '');
        }
    }
} catch (\Throwable $e) {
    $nombresGranja = [];
}

$resp = [
    'success' => true,
    'lotes' => $respLotes,
    'operacion' => $operacion,
    'rango' => $rango,
    'textoFiltros' => mort_graficas_texto_filtros_aplicados($filtros, $lotes, $nombresGranja),
];

$conn->close();

echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
