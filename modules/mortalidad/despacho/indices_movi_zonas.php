<?php

declare(strict_types=1);

/**
 * Índices de movi_zonas (MySQL 5.7+).
 * GET .../modules/mortalidad/despacho/indices_movi_zonas.php
 * Requiere sesión activa (mismo criterio que sql_preview / health_analisis).
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado (inicie sesión en el ERP)'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/mortalidad_despacho_lib.php';

/**
 * @param list<array<string, mixed>> $filas SHOW INDEX
 * @return list<array{nombre: string, unico: bool, tipo: string, columnas: list<string>}>
 */
function mort_despacho_indices_agrupar_show_index(array $filas): array
{
    $map = [];
    foreach ($filas as $row) {
        $nombre = (string) ($row['Key_name'] ?? '');
        if ($nombre === '') {
            continue;
        }
        if (!isset($map[$nombre])) {
            $map[$nombre] = [
                'nombre' => $nombre,
                'unico' => ((int) ($row['Non_unique'] ?? 1)) === 0,
                'tipo' => (string) ($row['Index_type'] ?? 'BTREE'),
                'columnas' => [],
            ];
        }
        $seq = (int) ($row['Seq_in_index'] ?? 0);
        $col = (string) ($row['Column_name'] ?? '');
        if ($seq > 0 && $col !== '') {
            $map[$nombre]['columnas'][$seq] = $col;
        }
    }

    $out = [];
    foreach ($map as $item) {
        $cols = $item['columnas'];
        ksort($cols);
        $item['columnas'] = array_values($cols);
        $out[] = $item;
    }

    usort($out, static function (array $a, array $b): int {
        if ($a['nombre'] === 'PRIMARY') {
            return -1;
        }
        if ($b['nombre'] === 'PRIMARY') {
            return 1;
        }

        return strcmp($a['nombre'], $b['nombre']);
    });

    return $out;
}

try {
    $conexionPath = null;
    foreach ([
        __DIR__ . '/../../../../conexion_grs/conexion.php',
        __DIR__ . '/../../../conexion_grs/conexion.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            $conexionPath = $candidate;
            break;
        }
    }
    if ($conexionPath === null) {
        throw new RuntimeException('conexion_grs/conexion.php no encontrado');
    }
    require_once $conexionPath;

    $conn = conectar_joya_mysqli();
    if (!$conn) {
        throw new RuntimeException('conectar_joya_mysqli() devolvió false');
    }
    mysqli_set_charset($conn, 'utf8');

    $tabla = 'movi_zonas';
    $tablaEsc = '`' . str_replace('`', '``', $tabla) . '`';

    $dbRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT DATABASE() AS db'));
    $database = (string) ($dbRow['db'] ?? '');

    $existsRes = mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = '" . mysqli_real_escape_string($conn, $tabla) . "'
         LIMIT 1"
    );
    $tablaExiste = $existsRes && mysqli_fetch_assoc($existsRes);

    $filasShow = [];
    $indices = [];
    if ($tablaExiste) {
        $resIdx = mysqli_query($conn, "SHOW INDEX FROM {$tablaEsc}");
        if (!$resIdx) {
            throw new RuntimeException('SHOW INDEX: ' . mysqli_error($conn));
        }
        while ($row = mysqli_fetch_assoc($resIdx)) {
            $filasShow[] = $row;
        }
        $indices = mort_despacho_indices_agrupar_show_index($filasShow);
    }

    $tieneIndices = count($indices) > 0;
    $soloPrimary = $tieneIndices && count($indices) === 1 && ($indices[0]['nombre'] ?? '') === 'PRIMARY';

    $status = null;
    if ($tablaExiste) {
        $resStatus = mysqli_query($conn, "SHOW TABLE STATUS LIKE '" . mysqli_real_escape_string($conn, $tabla) . "'");
        if ($resStatus && ($st = mysqli_fetch_assoc($resStatus))) {
            $status = [
                'engine' => (string) ($st['Engine'] ?? ''),
                'rows_estimadas' => isset($st['Rows']) ? (int) $st['Rows'] : null,
                'data_length' => isset($st['Data_length']) ? (int) $st['Data_length'] : null,
                'index_length' => isset($st['Index_length']) ? (int) $st['Index_length'] : null,
            ];
        }
    }

    mysqli_close($conn);

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    echo json_encode([
        'success' => true,
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'php' => PHP_VERSION,
        'database' => $database,
        'tabla' => $tabla,
        'tabla_existe' => (bool) $tablaExiste,
        'tiene_indices' => $tieneIndices,
        'solo_indice_primary' => $soloPrimary,
        'cantidad_indices' => count($indices),
        'cantidad_filas_show_index' => count($filasShow),
        'indices' => $indices,
        'table_status' => $status,
        'nota' => $tieneIndices
            ? ($soloPrimary
                ? 'Solo PRIMARY: conviene valorar índices en tcodtra, tfectra, tcencos para consultas despacho.'
                : 'Hay índices además de (o incluyendo) PRIMARY.')
            : 'Sin índices en SHOW INDEX (tabla inexistente o sin índices declarados).',
    ], $jsonFlags);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @mysqli_close($conn);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
