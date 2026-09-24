<?php

declare(strict_types=1);

/** Revisión desplegable (health / JSON libRev). Compatible PHP >= 7.2. */
if (!defined('MORT_DESPACHO_LIB_REV')) {
    define('MORT_DESPACHO_LIB_REV', '20260924c');
}

/**
 * Análisis de mortalidad en el proceso de despacho (causas, etapas y resumen por granja).
 *
 * Criterio de mortalidad de despacho: mismo que Ventas (tcategoria/flujo DESPACHO o
 * tcod_mortgrs en 05, 14, 15, 17, 18, 19), con la regla de negocio de que solo
 * cuenta si hubo venta (S700) del mismo sexo ese día en el mismo cenco/galpón.
 */

require_once __DIR__ . '/../ventas/mortalidad_ventas_lib.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_fact_aux_lib.php';
$hcRepoPath = __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';
if (is_file($hcRepoPath)) {
    require_once $hcRepoPath;
}

if (!function_exists('mort_despacho_filtros_defecto')) {
    /**
     * @return array<string, mixed>
     */
    function mort_despacho_filtros_defecto(): array
    {
        return [
            'periodoTipo' => 'POR_FECHA',
            'fechaUnica' => date('Y-m-d'),
            'fechaInicio' => '',
            'fechaFin' => '',
            'mesUnico' => '',
            'mesInicio' => date('Y-01'),
            'mesFin' => date('Y-12'),
            'granja' => '',
            'campania' => '',
            'cencos_list' => [],
        ];
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mort_despacho_parse_filtros(array $input): array
{
    $f = mort_despacho_filtros_defecto();
    foreach (array_keys($f) as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $f[$k] = trim((string) $input[$k]);
    }
    $gDigits = preg_replace('/\D/', '', $f['granja']) ?? '';
    $f['granja'] = $gDigits !== '' ? substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    $cencosRaw = trim((string) ($input['cencos'] ?? ''));
    $f['cencos_list'] = mort_despacho_normalizar_lista_cencos($cencosRaw);

    return $f;
}

/**
 * @return list<string> cencos de 6 dígitos
 */
function mort_despacho_normalizar_lista_cencos(string $raw): array
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) as $part) {
        $digits = preg_replace('/\D/', '', (string) $part) ?? '';
        if (strlen($digits) >= 6) {
            $c = substr($digits, 0, 3) . substr($digits, -3);
            $out[$c] = true;
        }
    }

    return array_keys($out);
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_despacho_append_filtro_cencos(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $lista = $filtros['cencos_list'] ?? [];
    if (!is_array($lista) || $lista === []) {
        return;
    }
    $ins = [];
    foreach ($lista as $c) {
        $c = trim((string) $c);
        if (preg_match('/^\d{6}$/', $c)) {
            $ins[] = "'" . mysqli_real_escape_string($conn, $c) . "'";
        }
    }
    if ($ins !== []) {
        $conds[] = "TRIM({$a}.tcencos) IN (" . implode(',', $ins) . ')';
    }
}

/**
 * Ranuras fijas de causas de despacho (orden del reporte operativo).
 *
 * @return list<array{key: string, codigos: list<string>, label: string}>
 */
function mort_despacho_causas_slots(): array
{
    return [
        ['key' => 'asfixia', 'codigos' => ['05'], 'label' => 'Asfixia'],
        ['key' => 'muerte_subita', 'codigos' => ['14'], 'label' => 'Muerte súbita'],
        ['key' => 'degollamiento', 'codigos' => ['15'], 'label' => 'Degollamiento'],
        ['key' => 'situacion_especial', 'codigos' => ['17', '19'], 'label' => 'Situación especial'],
        ['key' => 'aplastamiento', 'codigos' => ['18'], 'label' => 'Aplastamiento'],
    ];
}

/**
 * Etapas del proceso de despacho (orden solicitado en el reporte).
 *
 * @return list<array{col: string, label: string}>
 */
function mort_despacho_etapas_display(): array
{
    return [
        ['col' => 'procesoPreparar', 'label' => 'Preparar'],
        ['col' => 'procesoAcorralar', 'label' => 'Acorralar'],
        ['col' => 'procesoSeleccionar', 'label' => 'Seleccionar'],
        ['col' => 'procesoEstibar', 'label' => 'Estibar'],
        ['col' => 'procesoEnjabar', 'label' => 'Enjabar'],
        ['col' => 'procesoPesar', 'label' => 'Pesar'],
    ];
}

/** Códigos de causa considerados despacho (alineado con mortalidad_ventas_lib). */
function mort_despacho_codigos_causa_sql_in(): string
{
    return "('05','14','15','17','18','19')";
}

/**
 * Condición SQL: fila movi_zonas es mortalidad de despacho.
 */
function mort_despacho_sql_es_despacho(string $alias = 'mz'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $in = mort_despacho_codigos_causa_sql_in();

    return "(UPPER(TRIM(COALESCE({$a}.tcategoria, ''))) = 'DESPACHO'
        OR UPPER(TRIM(COALESCE({$a}.flujo, ''))) = 'DESPACHO'
        OR LPAD(TRIM(COALESCE({$a}.tcod_mortgrs, '')), 2, '0') IN {$in})";
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_despacho_where_base_movimientos(mysqli $conn, array $filtros, string $alias = 'mz'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $conds = [
        "TRIM({$a}.tcodigo) IN ('P0001001','P0001002')",
        "TRIM({$a}.tcodtra) IN ('S700','S808')",
    ];

    $rango = mort_ventas_rango($filtros);
    if ($rango !== null) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $conds[] = "DATE({$a}.tfectra) BETWEEN '{$desde}' AND '{$hasta}'";
    }

    $lista = $filtros['cencos_list'] ?? [];
    if (is_array($lista) && $lista !== []) {
        mort_despacho_append_filtro_cencos($conn, $filtros, $a, $conds);
    } else {
        $granja = trim((string) ($filtros['granja'] ?? ''));
        if ($granja !== '') {
            $gEsc = mysqli_real_escape_string($conn, $granja);
            $conds[] = "LEFT(TRIM({$a}.tcencos), 3) = '{$gEsc}'";
        }

        $campania = trim((string) ($filtros['campania'] ?? ''));
        if ($campania !== '') {
            $cEsc = mysqli_real_escape_string($conn, $campania);
            $conds[] = "RIGHT(TRIM({$a}.tcencos), 3) = '{$cEsc}'";
        }
    }

    return implode(' AND ', $conds);
}

/**
 * Subconsulta: venta diaria por cenco, galpón y sexo (M/H).
 */
function mort_despacho_sql_venta_dia_galpon(mysqli $conn, array $filtros): string
{
    $where = mort_despacho_where_base_movimientos($conn, $filtros, 'vz');

    return "
    SELECT
        DATE(vz.tfectra) AS fecha,
        TRIM(vz.tcencos) AS cencos,
        TRIM(CAST(vz.tcodint AS CHAR)) AS galpon,
        SUM(CASE WHEN TRIM(vz.tcodigo) = 'P0001001' THEN vz.tcantid ELSE 0 END) AS venta_m,
        SUM(CASE WHEN TRIM(vz.tcodigo) = 'P0001002' THEN vz.tcantid ELSE 0 END) AS venta_h
    FROM movi_zonas vz
    WHERE {$where}
      AND TRIM(vz.tcodtra) = 'S700'
    GROUP BY DATE(vz.tfectra), TRIM(vz.tcencos), TRIM(CAST(vz.tcodint AS CHAR))
    ";
}

/**
 * SQL agregado: cantidades por código de causa (mortalidad despacho válida).
 *
 * @return list<array{cod_mort: string, cantidad: int}>
 */
function mort_despacho_causas_agregadas_sql(mysqli $conn, array $filtros): array
{
    $where = mort_despacho_where_base_movimientos($conn, $filtros, 'mz');
    $sqlDesp = mort_despacho_sql_es_despacho('mz');
    $subVenta = mort_despacho_sql_venta_dia_galpon($conn, $filtros);

    $sql = "
    SELECT
        LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') AS cod_mort,
        COALESCE(SUM(mz.tcantid), 0) AS cantidad
    FROM movi_zonas mz
    INNER JOIN ({$subVenta}) v
        ON v.fecha = DATE(mz.tfectra)
       AND v.cencos = TRIM(mz.tcencos)
       AND v.galpon = TRIM(CAST(mz.tcodint AS CHAR))
    WHERE {$where}
      AND TRIM(mz.tcodtra) = 'S808'
      AND mz.tcantid > 0
      AND {$sqlDesp}
      AND (
            (TRIM(mz.tcodigo) = 'P0001001' AND v.venta_m > 0)
         OR (TRIM(mz.tcodigo) = 'P0001002' AND v.venta_h > 0)
      )
    GROUP BY LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0')
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = [
            'cod_mort' => (string) ($row['cod_mort'] ?? ''),
            'cantidad' => (int) ($row['cantidad'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $filas
 */
function mort_despacho_slot_por_codigo(string $cod): ?string
{
    $cod = str_pad(trim($cod), 2, '0', STR_PAD_LEFT);
    foreach (mort_despacho_causas_slots() as $slot) {
        if (in_array($cod, $slot['codigos'], true)) {
            return $slot['key'];
        }
    }

    return null;
}

/**
 * @param list<array{cod_mort: string, cantidad: int}> $porCodigo
 * @return array{filas: list<array<string, mixed>>, total: int}
 */
function mort_despacho_agregar_causas(array $porCodigo): array
{
    $counts = [];
    foreach (mort_despacho_causas_slots() as $slot) {
        $counts[$slot['key']] = 0;
    }
    $total = 0;
    foreach ($porCodigo as $f) {
        $cant = (int) ($f['cantidad'] ?? 0);
        $total += $cant;
        $key = mort_despacho_slot_por_codigo((string) ($f['cod_mort'] ?? ''));
        if ($key !== null) {
            $counts[$key] += $cant;
        }
    }

    $out = [];
    foreach (mort_despacho_causas_slots() as $slot) {
        $cant = (int) ($counts[$slot['key']] ?? 0);
        $pct = $total > 0 ? round($cant * 100 / $total, 0) : 0;
        $out[] = [
            'key' => $slot['key'],
            'causa' => $slot['label'],
            'cantidad' => $cant,
            'porcentaje' => $pct,
        ];
    }

    return ['filas' => $out, 'total' => $total];
}

/**
 * @param array<string, mixed> $filtros
 * @return array{filas: list<array<string, mixed>>, total: int}
 */
function mort_despacho_consultar_etapas(mysqli $conn, array $filtros): array
{
    $colsDisp = mort_despacho_etapas_display();
    $colsFact = mort_fact_det_columnas_etapas($conn);
    $totales = [];
    foreach ($colsDisp as $d) {
        $totales[$d['col']] = 0;
    }

    if ($colsFact === [] || !mort_fact_tablas_disponibles($conn)) {
        return mort_despacho_armar_etapas_respuesta($totales);
    }

    $rango = mort_ventas_rango($filtros);
    $conds = ["c.tipoMortalidad = 'despacho'"];
    if ($rango !== null) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $conds[] = "c.fechaRegistro BETWEEN '{$desde}' AND '{$hasta}'";
    }
    $granja = trim((string) ($filtros['granja'] ?? ''));
    if ($granja !== '') {
        $gEsc = mysqli_real_escape_string($conn, $granja);
        $conds[] = "c.granja = '{$gEsc}'";
    }
    $campania = trim((string) ($filtros['campania'] ?? ''));
    if ($campania !== '') {
        $cEsc = mysqli_real_escape_string($conn, $campania);
        $conds[] = "c.campania = '{$cEsc}'";
    }

    $selectSum = [];
    foreach ($colsDisp as $d) {
        $col = $d['col'];
        if (in_array($col, $colsFact, true)) {
            $selectSum[] = 'COALESCE(SUM(d.' . $col . '), 0) AS ' . $col;
        }
    }
    if ($selectSum === []) {
        return mort_despacho_armar_etapas_respuesta($totales);
    }

    $subVenta = mort_despacho_sql_venta_dia_galpon($conn, $filtros);
    $where = implode(' AND ', $conds);

    $sql = '
    SELECT ' . implode(', ', $selectSum) . "
    FROM san_fact_mortalidad_det d
    INNER JOIN san_fact_mortalidad_cab c ON c.id = d.cabId
    INNER JOIN ({$subVenta}) v
        ON v.fecha = c.fechaRegistro
       AND v.cencos = CONCAT(c.granja, c.campania)
       AND v.galpon = TRIM(c.galpon)
    WHERE {$where}
      AND (
            (d.sexo = 'M' AND v.venta_m > 0)
         OR (d.sexo = 'H' AND v.venta_h > 0)
      )
    ";

    $res = mysqli_query($conn, $sql);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        foreach ($colsDisp as $d) {
            $col = $d['col'];
            if (array_key_exists($col, $row)) {
                $totales[$col] = (int) $row[$col];
            }
        }
    }

    return mort_despacho_armar_etapas_respuesta($totales);
}

/**
 * @param array<string, int> $totales
 * @return array{filas: list<array<string, mixed>>, total: int}
 */
function mort_despacho_armar_etapas_respuesta(array $totales): array
{
    $total = 0;
    foreach ($totales as $v) {
        $total += (int) $v;
    }
    $filas = [];
    foreach (mort_despacho_etapas_display() as $d) {
        $cant = (int) ($totales[$d['col']] ?? 0);
        $pct = $total > 0 ? round($cant * 100 / $total, 0) : 0;
        $filas[] = [
            'etapa' => $d['label'],
            'col' => $d['col'],
            'cantidad' => $cant,
            'porcentaje' => $pct,
        ];
    }

    return ['filas' => $filas, 'total' => $total];
}

/**
 * @return list<string> fechas Y-m-d en el rango (inclusive)
 */
function mort_despacho_fechas_en_rango(?array $rango): array
{
    if ($rango === null || empty($rango['desde']) || empty($rango['hasta'])) {
        return [];
    }
    $fechas = [];
    try {
        $d = new DateTime($rango['desde']);
        $fin = new DateTime($rango['hasta']);
    } catch (Exception $e) {
        return [];
    }
    while ($d <= $fin) {
        $fechas[] = $d->format('Y-m-d');
        $d->modify('+1 day');
    }

    return $fechas;
}

/**
 * @return list<array{granja: string, nombre: string, zona: string, subzona: string}>
 */
function mort_despacho_granjas_hc_cached(mysqli $conn): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = function_exists('hc_granjas_listar_para_selector')
            ? hc_granjas_listar_para_selector($conn)
            : [];
    }

    return $cache;
}

/**
 * Catálogo explícito desde filtro multi (cencos).
 *
 * @return array<int, array<string, string>>
 */
function mort_despacho_catalogo_desde_lista_cencos(array $filtros): array
{
    $lista = $filtros['cencos_list'] ?? [];
    if (!is_array($lista) || $lista === []) {
        return [];
    }
    $out = [];
    foreach ($lista as $c) {
        $c = trim((string) $c);
        if (!preg_match('/^\d{6}$/', $c)) {
            continue;
        }
        $out[] = [
            'cencos' => $c,
            'granja' => substr($c, 0, 3),
            'campania' => substr($c, 3, 3),
        ];
    }
    usort($out, static function ($a, $b) {
        return strcmp($a['cencos'], $b['cencos']);
    });

    return $out;
}

/**
 * Catálogo completo HC + campañas (solo para consulta de un día: todas las granjas con 0 % posible).
 *
 * @return list<array{cencos: string, granja: string, campania: string}>
 */
function mort_despacho_catalogo_cencos_dia_completo(mysqli $conn, array $filtros): array
{
    $granjaFiltro = trim((string) ($filtros['granja'] ?? ''));
    $campaniaFiltro = trim((string) ($filtros['campania'] ?? ''));
    $granjasHc = mort_despacho_granjas_hc_cached($conn);
    $granjasPermitidas = [];
    foreach ($granjasHc as $row) {
        $g3 = trim((string) ($row['granja'] ?? ''));
        if ($g3 === '') {
            continue;
        }
        if ($granjaFiltro !== '' && $g3 !== $granjaFiltro) {
            continue;
        }
        $granjasPermitidas[$g3] = true;
    }
    if ($granjasPermitidas === []) {
        return [];
    }

    $inGranjas = [];
    foreach (array_keys($granjasPermitidas) as $g3) {
        $inGranjas[] = "'" . mysqli_real_escape_string($conn, $g3) . "'";
    }

    $conds = [
        "TRIM(g.tcencos) <> ''",
        "LEFT(TRIM(g.tcencos), 1) = '6'",
        "CHAR_LENGTH(TRIM(g.tcencos)) >= 6",
        'LEFT(TRIM(g.tcencos), 3) IN (' . implode(',', $inGranjas) . ')',
    ];
    if ($campaniaFiltro !== '') {
        $cEsc = mysqli_real_escape_string($conn, $campaniaFiltro);
        $conds[] = "RIGHT(TRIM(g.tcencos), 3) = '{$cEsc}'";
    }

    $sql = '
    SELECT DISTINCT
        TRIM(g.tcencos) AS cencos,
        LEFT(TRIM(g.tcencos), 3) AS granja,
        RIGHT(TRIM(g.tcencos), 3) AS campania
    FROM regcencosgalpones g
    WHERE ' . implode(' AND ', $conds) . '
    ORDER BY cencos ASC';

    $res = mysqli_query($conn, $sql);
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cencos = trim((string) ($row['cencos'] ?? ''));
            if ($cencos === '' || strlen($cencos) < 6) {
                continue;
            }
            $c6 = substr($cencos, 0, 3) . substr($cencos, -3);
            $out[] = [
                'cencos' => $c6,
                'granja' => trim((string) ($row['granja'] ?? substr($c6, 0, 3))),
                'campania' => trim((string) ($row['campania'] ?? substr($c6, 3, 3))),
            ];
        }
    }

    return $out;
}

/**
 * Cencos con saca (S700) en el periodo ya filtrado — evita cruzar todo el catálogo en rangos largos.
 *
 * @return list<array{cencos: string, granja: string, campania: string}>
 */
function mort_despacho_catalogo_cencos_con_saca_periodo(mysqli $conn, array $filtros): array
{
    $where = mort_despacho_where_base_movimientos($conn, $filtros, 'mz');
    $where .= " AND TRIM(mz.tcodtra) = 'S700' AND mz.tcantid > 0";

    $sql = "
    SELECT DISTINCT
        TRIM(mz.tcencos) AS cencos,
        LEFT(TRIM(mz.tcencos), 3) AS granja,
        RIGHT(TRIM(mz.tcencos), 3) AS campania
    FROM movi_zonas mz
    WHERE {$where}
    ORDER BY cencos ASC
    ";

    $res = mysqli_query($conn, $sql);
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cencos = trim((string) ($row['cencos'] ?? ''));
            if ($cencos === '' || strlen($cencos) < 6) {
                continue;
            }
            $c6 = substr($cencos, 0, 3) . substr($cencos, -3);
            $out[] = [
                'cencos' => $c6,
                'granja' => trim((string) ($row['granja'] ?? substr($c6, 0, 3))),
                'campania' => trim((string) ($row['campania'] ?? substr($c6, 3, 3))),
            ];
        }
    }

    return $out;
}

/**
 * Catálogo de cencos según periodo: un día = todas las granjas; rango = solo con despacho en el periodo.
 *
 * @return list<array{cencos: string, granja: string, campania: string}>
 */
function mort_despacho_catalogo_cencos(mysqli $conn, array $filtros, ?array $rango = null): array
{
    $explicit = mort_despacho_catalogo_desde_lista_cencos($filtros);
    if ($explicit !== []) {
        return $explicit;
    }

    $rango = $rango ?? mort_ventas_rango($filtros);
    $fechas = mort_despacho_fechas_en_rango($rango);
    if (count($fechas) === 1) {
        return mort_despacho_catalogo_cencos_dia_completo($conn, $filtros);
    }

    return mort_despacho_catalogo_cencos_con_saca_periodo($conn, $filtros);
}

/**
 * Estadísticas despacho por fecha + cenco (sin filtrar filas en cero).
 *
 * @return array<string, array{cantidad: float, muertos: int}>
 */
function mort_despacho_resumen_stats_map(mysqli $conn, array $filtros): array
{
    $where = mort_despacho_where_base_movimientos($conn, $filtros, 'mz');
    $sqlDesp = mort_despacho_sql_es_despacho('mz');
    $subVenta = mort_despacho_sql_venta_dia_galpon($conn, $filtros);

    $sql = "
    SELECT
        DATE(mz.tfectra) AS fecha,
        TRIM(mz.tcencos) AS cencos,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = 'S700' THEN mz.tcantid ELSE 0 END), 0) AS cantidad_despachada,
        COALESCE(SUM(
            CASE
                WHEN TRIM(mz.tcodtra) = 'S808'
                     AND {$sqlDesp}
                     AND (
                           (TRIM(mz.tcodigo) = 'P0001001' AND COALESCE(v.venta_m, 0) > 0)
                        OR (TRIM(mz.tcodigo) = 'P0001002' AND COALESCE(v.venta_h, 0) > 0)
                     )
                THEN mz.tcantid
                ELSE 0
            END
        ), 0) AS muertos
    FROM movi_zonas mz
    LEFT JOIN ({$subVenta}) v
        ON v.fecha = DATE(mz.tfectra)
       AND v.cencos = TRIM(mz.tcencos)
       AND v.galpon = TRIM(CAST(mz.tcodint AS CHAR))
    WHERE {$where}
    GROUP BY DATE(mz.tfectra), TRIM(mz.tcencos)
    ";

    $map = [];
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $fecha = (string) ($row['fecha'] ?? '');
            $cencos = trim((string) ($row['cencos'] ?? ''));
            if ($fecha === '' || $cencos === '') {
                continue;
            }
            $c6 = strlen($cencos) >= 6 ? substr($cencos, 0, 3) . substr($cencos, -3) : $cencos;
            $map[$fecha . '|' . $c6] = [
                'cantidad' => (float) ($row['cantidad_despachada'] ?? 0),
                'muertos' => (int) ($row['muertos'] ?? 0),
            ];
        }
    }

    return $map;
}

/**
 * Resumen por fecha y cenco: todas las granjas/campañas del catálogo; 0 % si no hubo mort. despacho.
 *
 * @return list<array<string, mixed>>
 */
function mort_despacho_consultar_resumen_granjas(mysqli $conn, array $filtros): array
{
    $rango = mort_ventas_rango($filtros);
    $fechas = mort_despacho_fechas_en_rango($rango);
    if ($fechas === []) {
        return [];
    }

    $stats = mort_despacho_resumen_stats_map($conn, $filtros);
    $catalogo = mort_despacho_catalogo_cencos($conn, $filtros, $rango);
    if ($catalogo === []) {
        return [];
    }
    $nombres = mort_despacho_nombres_granja($conn);
    $out = [];
    $n = 0;

    foreach ($fechas as $fecha) {
        foreach ($catalogo as $cat) {
            $cencos = $cat['cencos'];
            $granja = $cat['granja'];
            $campania = $cat['campania'];
            $key = $fecha . '|' . $cencos;
            $st = $stats[$key] ?? ['cantidad' => 0.0, 'muertos' => 0];
            $cant = (float) $st['cantidad'];
            $muertos = (int) $st['muertos'];
            $pct = $cant > 0 ? round($muertos * 100 / $cant, 2) : 0.0;
            $nomBase = $nombres[$granja] ?? '';
            $granjaLabel = $nomBase !== ''
                ? $nomBase . ' C=' . $campania
                : 'Granja ' . $granja . ' C=' . $campania;
            $n++;
            $out[] = [
                'numero' => $n,
                'fecha' => $fecha,
                'cencos' => $cencos,
                'granja' => $granjaLabel,
                'granjaCod' => $granja,
                'campania' => $campania,
                'cantidad' => $cant,
                'muertos' => $muertos,
                'porcentaje' => $pct,
            ];
        }
    }

    return $out;
}

/**
 * @return array<string, string> granja3 => nombre
 */
function mort_despacho_nombres_granja(mysqli $conn): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $q = mysqli_query($conn, "SELECT codigo, TRIM(nombre) AS nombre FROM ccos WHERE codigo LIKE '%000'");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $cod = trim((string) ($row['codigo'] ?? ''));
            $g3 = strlen($cod) >= 3 ? substr($cod, 0, 3) : $cod;
            if ($g3 !== '') {
                $cache[$g3] = trim((string) ($row['nombre'] ?? ''));
            }
        }
    }

    return $cache;
}

/**
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_analisis_completo(mysqli $conn, array $filtros): array
{
    $causas = mort_despacho_agregar_causas(mort_despacho_causas_agregadas_sql($conn, $filtros));
    $etapas = mort_despacho_consultar_etapas($conn, $filtros);
    $resumen = mort_despacho_consultar_resumen_granjas($conn, $filtros);

    $rango = mort_ventas_rango($filtros);

    return [
        'rango' => $rango,
        'causas' => $causas,
        'etapas' => $etapas,
        'resumenGranjas' => $resumen,
    ];
}
