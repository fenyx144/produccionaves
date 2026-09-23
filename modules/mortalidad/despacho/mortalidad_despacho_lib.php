<?php

declare(strict_types=1);

/**
 * Análisis de mortalidad en el proceso de despacho (causas, etapas y resumen por granja).
 *
 * Criterio de mortalidad de despacho: mismo que Ventas (tcategoria/flujo DESPACHO o
 * tcod_mortgrs en 05, 14, 15, 17, 18, 19), con la regla de negocio de que solo
 * cuenta si hubo venta (S700) del mismo sexo ese día en el mismo cenco/galpón.
 */

require_once __DIR__ . '/../ventas/mortalidad_ventas_lib.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_fact_aux_lib.php';

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

    return $f;
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
 * Resumen por fecha y cenco (granja + campaña): saca, muertos y %.
 *
 * @return list<array<string, mixed>>
 */
function mort_despacho_consultar_resumen_granjas(mysqli $conn, array $filtros): array
{
    $where = mort_despacho_where_base_movimientos($conn, $filtros, 'mz');
    $sqlDesp = mort_despacho_sql_es_despacho('mz');
    $subVenta = mort_despacho_sql_venta_dia_galpon($conn, $filtros);

    $sql = "
    SELECT
        x.fecha,
        x.cencos,
        x.granja,
        x.campania,
        x.cantidad_despachada,
        x.muertos
    FROM (
        SELECT
            DATE(mz.tfectra) AS fecha,
            TRIM(mz.tcencos) AS cencos,
            LEFT(TRIM(mz.tcencos), 3) AS granja,
            RIGHT(TRIM(mz.tcencos), 3) AS campania,
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
        GROUP BY DATE(mz.tfectra), TRIM(mz.tcencos), LEFT(TRIM(mz.tcencos), 3), RIGHT(TRIM(mz.tcencos), 3)
        HAVING cantidad_despachada > 0
    ) x
    ORDER BY x.fecha DESC, x.cencos ASC
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }

    $nombres = mort_despacho_nombres_granja($conn);
    $out = [];
    $n = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $n++;
        $granja = trim((string) ($row['granja'] ?? ''));
        $campania = trim((string) ($row['campania'] ?? ''));
        $cencos = trim((string) ($row['cencos'] ?? ''));
        $cant = (float) ($row['cantidad_despachada'] ?? 0);
        $muertos = (int) ($row['muertos'] ?? 0);
        $pct = $cant > 0 ? round($muertos * 100 / $cant, 2) : 0.0;
        $nomBase = $nombres[$granja] ?? '';
        $granjaLabel = $nomBase !== ''
            ? $nomBase . ' C=' . $campania
            : 'Granja ' . $granja . ' C=' . $campania;

        $out[] = [
            'numero' => $n,
            'fecha' => (string) ($row['fecha'] ?? ''),
            'cencos' => $cencos,
            'granja' => $granjaLabel,
            'granjaCod' => $granja,
            'campania' => $campania,
            'cantidad' => $cant,
            'muertos' => $muertos,
            'porcentaje' => $pct,
        ];
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
