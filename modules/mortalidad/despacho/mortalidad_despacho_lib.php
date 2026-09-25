<?php

declare(strict_types=1);

/** Revisión desplegable (health / JSON libRev). Compatible PHP >= 7.2. */
if (!defined('MORT_DESPACHO_LIB_REV')) {
    define('MORT_DESPACHO_LIB_REV', '20260925y');
}

if (!defined('MORT_DESPACHO_MAX_KEYS_VENTA')) {
    define('MORT_DESPACHO_MAX_KEYS_VENTA', 40000);
}

require_once __DIR__ . '/../ventas/mortalidad_ventas_lib.php';

/** Tiempo máximo PHP/BD por petición de análisis (evita bloquear el servidor compartido). */
if (!defined('MORT_DESPACHO_QUERY_TIME_SEC')) {
    define('MORT_DESPACHO_QUERY_TIME_SEC', 90);
}

if (!defined('MORT_DESPACHO_MAX_DIAS_ABSOLUTO')) {
    define('MORT_DESPACHO_MAX_DIAS_ABSOLUTO', 366);
}

if (!defined('MORT_DESPACHO_RESUMEN_PAGE_SIZE_DEFAULT')) {
    define('MORT_DESPACHO_RESUMEN_PAGE_SIZE_DEFAULT', 50);
}

if (!defined('MORT_DESPACHO_RESUMEN_PAGE_SIZE_MAX')) {
    define('MORT_DESPACHO_RESUMEN_PAGE_SIZE_MAX', 200);
}

/** Consultas de análisis en paralelo por sesión (causas + etapas + resumen paginado). */
if (!defined('MORT_DESPACHO_MAX_CONCURRENT_PER_SESSION')) {
    define('MORT_DESPACHO_MAX_CONCURRENT_PER_SESSION', 3);
}

/**
 * Límites de tiempo en la sesión MySQL (SELECT pesados no pueden exceder QUERY_TIME_SEC).
 */
function mort_despacho_aplicar_limites_sesion_db(mysqli $conn): void
{
    $sec = (int) MORT_DESPACHO_QUERY_TIME_SEC;
    if ($sec < 1) {
        return;
    }
    $ms = $sec * 1000;
    if ($ms > 0) {
        @mysqli_query($conn, 'SET SESSION max_execution_time = ' . (int) $ms);
    }
}

/**
 * @param array{desde: string, hasta: string}|null $rango
 */
function mort_despacho_dias_en_rango(?array $rango): int
{
    if ($rango === null || empty($rango['desde']) || empty($rango['hasta'])) {
        return 0;
    }
    try {
        $d1 = new DateTime($rango['desde']);
        $d2 = new DateTime($rango['hasta']);
    } catch (Exception $e) {
        return 0;
    }
    if ($d2 < $d1) {
        return 0;
    }

    return (int) $d1->diff($d2)->days + 1;
}

/**
 * Evita consultas desproporcionadas que saturan MySQL (resto del ERP / otros proyectos).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_validar_politica_carga(array $filtros): void
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }
    $dias = mort_despacho_dias_en_rango($rango);
    if ($dias <= 0) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }
    if ($dias > MORT_DESPACHO_MAX_DIAS_ABSOLUTO) {
        throw new RuntimeException('El periodo máximo permitido es de un año. Reduzca el rango de fechas.');
    }
}

/**
 * Cupo de consultas pesadas concurrentes por sesión de usuario.
 */
function mort_despacho_slot_adquirir(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $now = time();
    $last = (int) ($_SESSION['mort_dsp_query_ts'] ?? 0);
    $n = (int) ($_SESSION['mort_dsp_query_n'] ?? 0);
    if ($last > 0 && ($now - $last) > (MORT_DESPACHO_QUERY_TIME_SEC + 30)) {
        $n = 0;
    }
    if ($n >= MORT_DESPACHO_MAX_CONCURRENT_PER_SESSION) {
        return false;
    }
    $_SESSION['mort_dsp_query_n'] = $n + 1;
    $_SESSION['mort_dsp_query_ts'] = $now;

    return true;
}

function mort_despacho_slot_liberar(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $n = (int) ($_SESSION['mort_dsp_query_n'] ?? 0);
    $_SESSION['mort_dsp_query_n'] = max(0, $n - 1);
    if ((int) $_SESSION['mort_dsp_query_n'] === 0) {
        unset($_SESSION['mort_dsp_query_ts']);
    }
}

/** Escapa valor SQL (PHP 7.2: mysqli_real_escape_string exige string; claves numéricas de granja pueden ser int). */
function mort_despacho_sql_esc(mysqli $conn, $value): string
{
    return mysqli_real_escape_string($conn, (string) $value);
}

/**
 * Rango sobre tfectra sin DATE() en columna (mejor uso de índice).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_append_rango_tfectra(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        return;
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $desde = mort_despacho_sql_esc($conn, $rango['desde'] . ' 00:00:00');
    $hasta = mort_despacho_sql_esc($conn, $rango['hasta']);
    $conds[] = "{$a}.tfectra >= '{$desde}' AND {$a}.tfectra < DATE_ADD('{$hasta}', INTERVAL 1 DAY)";
}

/**
 * Periodo como mortalidad listado (fechaRegistro = cabe_zonas.tfecrem).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_append_rango_fecha_registro_cz(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        return;
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'cz';
    $desde = mort_despacho_sql_esc($conn, $rango['desde']);
    $hasta = mort_despacho_sql_esc($conn, $rango['hasta']);
    $conds[] = "{$a}.tfecrem BETWEEN '{$desde}' AND '{$hasta}'";
}

function mort_despacho_sql_join_cabe_mov_zonas(string $aliasCz = 'cz', string $aliasMz = 'mz'): string
{
    $cz = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasCz) ?: 'cz';
    $mz = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';

    return "INNER JOIN cabe_zonas {$cz} ON {$cz}.mark = {$mz}.mark AND {$cz}.treg = {$mz}.treg
        AND {$cz}.tdoc = {$mz}.tdoc AND {$cz}.tserie = {$mz}.tserie AND {$cz}.tnumfac = {$mz}.tnumfac";
}

/**
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_filtros_clave_cache(array $filtros): array
{
    return [
        'periodoTipo' => (string) ($filtros['periodoTipo'] ?? ''),
        'fechaUnica' => (string) ($filtros['fechaUnica'] ?? ''),
        'fechaInicio' => (string) ($filtros['fechaInicio'] ?? ''),
        'fechaFin' => (string) ($filtros['fechaFin'] ?? ''),
        'mesUnico' => (string) ($filtros['mesUnico'] ?? ''),
        'mesInicio' => (string) ($filtros['mesInicio'] ?? ''),
        'mesFin' => (string) ($filtros['mesFin'] ?? ''),
        'granja' => (string) ($filtros['granja'] ?? ''),
        'campania' => (string) ($filtros['campania'] ?? ''),
        'cencos_list' => $filtros['cencos_list'] ?? [],
    ];
}

/**
 * Filtros de granja/cenco/periodo sobre movi_zonas (Despacho).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_append_filtros_cenco_granja(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $conds[] = "LEFT(TRIM({$a}.tcencos), 1) = '6'";

    $lista = $filtros['cencos_list'] ?? [];
    if (is_array($lista) && $lista !== []) {
        mort_despacho_append_filtro_cencos($conn, $filtros, $a, $conds);
    } else {
        $granja = trim((string) ($filtros['granja'] ?? ''));
        if ($granja !== '') {
            $conds[] = "LEFT(TRIM({$a}.tcencos), 3) = '" . mort_despacho_sql_esc($conn, $granja) . "'";
        }
        $campania = trim((string) ($filtros['campania'] ?? ''));
        if ($campania !== '') {
            $conds[] = "RIGHT(TRIM({$a}.tcencos), 3) = '" . mort_despacho_sql_esc($conn, $campania) . "'";
        }
    }
}

/**
 * WHERE index-friendly: rango tfectra → tcodtra S700 → pollos (sin TRIM en columnas clave).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_append_where_s700_index(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    mort_despacho_append_rango_tfectra($conn, $filtros, $a, $conds);
    $conds[] = "{$a}.tcodtra = 'S700'";
    $conds[] = "{$a}.tcodigo IN ('P0001001','P0001002')";
    $conds[] = "{$a}.tcantid > 0";
    mort_despacho_append_filtros_cenco_granja($conn, $filtros, $a, $conds);
}

/** @param array<string, mixed> $filtros */
function mort_despacho_append_filtros_movimiento(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    mort_despacho_append_where_s700_index($conn, $filtros, $alias, $conds);
}

/**
 * @param array{desde: string, hasta: string}|null $rango
 */
function mort_despacho_rango_es_un_dia(?array $rango): bool
{
    return $rango !== null
        && ($rango['desde'] ?? '') !== ''
        && $rango['desde'] === ($rango['hasta'] ?? '');
}

/**
 * Subconsulta S700 agrupada (sin tabla temporal): día + cenco + galpón + venta por sexo.
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_subquery_claves_s700(mysqli $conn, array $filtros): string
{
    static $cache = [];
    $cacheId = md5(json_encode(mort_despacho_filtros_clave_cache($filtros)));
    if (isset($cache[$cacheId])) {
        return $cache[$cacheId];
    }

    $rango = mort_ventas_rango($filtros);
    $unDia = mort_despacho_rango_es_un_dia($rango);
    if ($unDia && $rango !== null) {
        $fechaEsc = mort_despacho_sql_esc($conn, $rango['desde']);
        $fechaSelect = "CAST('{$fechaEsc}' AS DATE) AS fecha";
        $groupBy = "
        CONCAT(LPAD(LEFT(TRIM(mz.tcencos), 3), 3, '0'), LPAD(RIGHT(TRIM(mz.tcencos), 3), 3, '0')),
        TRIM(mz.tcencos),
        TRIM(CAST(mz.tcodint AS CHAR))";
    } else {
        $fechaSelect = 'DATE(mz.tfectra) AS fecha';
        $groupBy = "
        DATE(mz.tfectra),
        CONCAT(LPAD(LEFT(TRIM(mz.tcencos), 3), 3, '0'), LPAD(RIGHT(TRIM(mz.tcencos), 3), 3, '0')),
        TRIM(mz.tcencos),
        TRIM(CAST(mz.tcodint AS CHAR))";
    }

    $conds = [];
    mort_despacho_append_where_s700_index($conn, $filtros, 'mz', $conds);

    $sql = trim("
    SELECT
        {$fechaSelect},
        CONCAT(
            LPAD(LEFT(TRIM(mz.tcencos), 3), 3, '0'),
            LPAD(RIGHT(TRIM(mz.tcencos), 3), 3, '0')
        ) AS cenco6,
        TRIM(mz.tcencos) AS tcencos,
        TRIM(CAST(mz.tcodint AS CHAR)) AS galpon,
        COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS venta_macho,
        COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS venta_hembra
    FROM movi_zonas mz
    WHERE " . implode(' AND ', $conds) . "
    GROUP BY {$groupBy}
    HAVING (COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001001' THEN mz.tcantid ELSE 0 END), 0)
          + COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0)) > 0");

    $cache[$cacheId] = $sql;

    return $sql;
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_from_claves_s700(mysqli $conn, array $filtros, string $alias = 'k'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'k';

    return '(' . mort_despacho_sql_subquery_claves_s700($conn, $filtros) . ") AS {$a}";
}

function mort_despacho_sql_join_causa_fact(string $aliasCz = 'cz', string $aliasMz = 'mz', string $aliasD = 'd'): string
{
    $cz = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasCz) ?: 'cz';
    $mz = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';
    $d = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasD) ?: 'd';

    return "
    LEFT JOIN san_fact_mortalidad_cab fc ON fc.id = {$cz}.external_id
    LEFT JOIN san_fact_mortalidad_det {$d} ON {$d}.cabId = fc.id
        AND CAST({$d}.posicion AS UNSIGNED) = CAST({$mz}.idmovi AS UNSIGNED)
        AND (
            (UPPER(LEFT(TRIM({$d}.sexo), 1)) = 'M' AND TRIM({$mz}.tcodigo) = 'P0001001')
            OR (UPPER(LEFT(TRIM({$d}.sexo), 1)) = 'H' AND TRIM({$mz}.tcodigo) = 'P0001002')
        )";
}

/** Código causa: movi_zonas o, si viene vacío, san_fact (como detalle listado). */
function mort_despacho_sql_expr_cod_mort_linea(string $aliasMz = 'mz', string $aliasD = 'd'): string
{
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';
    $d = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasD) ?: 'd';

    return "LPAD(TRIM(COALESCE(
        NULLIF(TRIM({$m}.tcod_mortgrs), ''),
        NULLIF(TRIM({$d}.codMortalidad), '')
    )), 2, '0')";
}

/**
 * WHERE tabla 1 = listado despacho (fechaRegistro en cabecera JD4; sin filtro tfectra extra).
 *
 * @param array<string, mixed> $filtros
 * @param list<string> $conds
 */
function mort_despacho_append_where_causas_listado(mysqli $conn, array $filtros, array &$conds, bool $filtrarMotivosDespacho = true): void
{
    if ($filtrarMotivosDespacho) {
        $causas = mort_despacho_codigos_causa_listado_sql_in();
        $expr = mort_despacho_sql_expr_cod_mort_linea('mz', 'd');
        $conds[] = "{$expr} IN {$causas}";
    }
}

/**
 * @param array<string, mixed> $filtros
 * @return list<string>
 */
function mort_despacho_sql_conds_causas_listado(mysqli $conn, array $filtros, bool $filtrarMotivosDespacho = true): array
{
    $conds = [
        "TRIM(cz.mark) = 'JD4'",
        "TRIM(mz.tcodigo) IN ('P0001001','P0001002')",
        'mz.tcantid > 0',
    ];
    mort_despacho_append_rango_fecha_registro_cz($conn, $filtros, 'cz', $conds);
    mort_despacho_append_filtros_cenco_granja($conn, $filtros, 'mz', $conds);
    mort_despacho_append_where_causas_listado($conn, $filtros, $conds, $filtrarMotivosDespacho);

    return $conds;
}

/**
 * Tabla 1: cabecera JD4 + detalle movi (mismo criterio que listado mortalidad despacho).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_text_causas_listado(mysqli $conn, array $filtros): string
{
    $conds = mort_despacho_sql_conds_causas_listado($conn, $filtros, true);
    $joinFact = mort_despacho_sql_join_causa_fact('cz', 'mz', 'd');
    $exprCod = mort_despacho_sql_expr_cod_mort_linea('mz', 'd');

    return "
    SELECT
        {$exprCod} AS cod_mort,
        COALESCE(SUM(mz.tcantid), 0) AS cantidad
    FROM cabe_zonas cz
    INNER JOIN movi_zonas mz ON
        cz.mark = mz.mark AND cz.treg = mz.treg
        AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
    {$joinFact}
    WHERE " . implode("\n        AND ", $conds) . "
    GROUP BY {$exprCod}";
}

/**
 * Detalle línea a línea (debug tabla 1): mismos filtros que causas agregadas.
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_text_causas_listado_detalle(mysqli $conn, array $filtros): string
{
    $conds = mort_despacho_sql_conds_causas_listado($conn, $filtros, true);
    $exprCenco = mort_despacho_sql_expr_cenco('mz');
    $joinFact = mort_despacho_sql_join_causa_fact('cz', 'mz', 'd');
    $exprCod = mort_despacho_sql_expr_cod_mort_linea('mz', 'd');

    return "
    SELECT
        cz.external_id AS id_registro,
        cz.tnumfac AS tnumfac_raw,
        cz.tfecrem AS fecha_registro,
        mz.tfectra AS fecha_movimiento,
        {$exprCenco} AS cenco6,
        TRIM(mz.tcencos) AS tcencos,
        TRIM(CAST(mz.tcodint AS CHAR)) AS galpon,
        TRIM(mz.tcodigo) AS tcodigo,
        CASE TRIM(mz.tcodigo) WHEN 'P0001002' THEN 'H' ELSE 'M' END AS sexo,
        {$exprCod} AS cod_mort,
        TRIM(COALESCE(r.tnom_mort, r2.tnom_mort, '')) AS nom_mort,
        TRIM(COALESCE(mz.tcod_mortgrs, '')) AS cod_mort_zonas,
        TRIM(COALESCE(d.codMortalidad, '')) AS cod_mort_fact,
        mz.tcantid AS cantidad,
        TRIM(cz.tuser) AS usuario_registro,
        mz.idmovi AS idmovi
    FROM cabe_zonas cz
    INNER JOIN movi_zonas mz ON
        cz.mark = mz.mark AND cz.treg = mz.treg
        AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
    {$joinFact}
    LEFT JOIN regmotivo_mortalidadgrs r ON r.tcod_mort = mz.tcod_mortgrs
    LEFT JOIN regmotivo_mortalidadgrs r2 ON r2.tcod_mort = d.codMortalidad
    WHERE " . implode("\n        AND ", $conds) . "
    ORDER BY cz.tfecrem ASC, cz.tnumfac ASC, mz.idmovi ASC, mz.tcodigo ASC";
}

/**
 * Cabeceras JD4 del periodo (como listado), sin filtrar por código de causa.
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_text_cabeceras_despacho_listado(mysqli $conn, array $filtros): string
{
    $conds = [
        "TRIM(cz.mark) = 'JD4'",
    ];
    mort_despacho_append_rango_fecha_registro_cz($conn, $filtros, 'cz', $conds);
    $condsMovi = ["TRIM(mz.tcodigo) IN ('P0001001','P0001002')"];
    mort_despacho_append_filtros_cenco_granja($conn, $filtros, 'mz', $condsMovi);

    return "
    SELECT
        cz.external_id AS id_registro,
        cz.tnumfac AS tnumfac_raw,
        cz.tfecrem AS fecha_registro,
        " . mort_despacho_sql_expr_cenco('mz') . " AS cenco6,
        TRIM(CAST(MAX(mz.tcodint) AS CHAR)) AS galpon,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS machos,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS hembras,
        COALESCE(SUM(mz.tcantid), 0) AS total_aves,
        TRIM(cz.tuser) AS usuario_registro
    FROM cabe_zonas cz
    INNER JOIN movi_zonas mz ON
        cz.mark = mz.mark AND cz.treg = mz.treg
        AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
    WHERE " . implode("\n        AND ", $conds) . "
      AND " . implode("\n      AND ", $condsMovi) . "
    GROUP BY cz.external_id, cz.tnumfac, cz.tfecrem, cz.tuser, TRIM(mz.tcencos)
    ORDER BY cz.tfecrem ASC, cz.tnumfac ASC";
}

/**
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_fetch_causas_por_codigo_sql(mysqli $conn, array $filtros): array
{
    $sql = mort_despacho_sql_text_causas_listado($conn, $filtros);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('SQL causas listado vacío');
    }
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new RuntimeException('Consulta causas listado: ' . mysqli_error($conn));
    }
    $causas = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $causas[] = [
            'cod_mort' => (string) ($row['cod_mort'] ?? ''),
            'cantidad' => (int) ($row['cantidad'] ?? 0),
        ];
    }

    return $causas;
}

function mort_despacho_debug_tabla_causas(mysqli $conn, array $filtros): array
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }

    $filasDetalle = [];
    $sqlDetalle = mort_despacho_sql_text_causas_listado_detalle($conn, $filtros);
    if (!is_string($sqlDetalle) || trim($sqlDetalle) === '') {
        throw new RuntimeException('SQL debug detalle vacío');
    }
    $resDet = mysqli_query($conn, $sqlDetalle);
    if (!$resDet) {
        throw new RuntimeException('Debug detalle causas: ' . mysqli_error($conn));
    }
    while ($row = mysqli_fetch_assoc($resDet)) {
        $filasDetalle[] = $row;
    }

    $cabeceras = [];
    $sqlCab = mort_despacho_sql_text_cabeceras_despacho_listado($conn, $filtros);
    if (!is_string($sqlCab) || trim($sqlCab) === '') {
        throw new RuntimeException('SQL debug cabeceras vacío');
    }
    $resCab = mysqli_query($conn, $sqlCab);
    if (!$resCab) {
        throw new RuntimeException('Debug cabeceras listado: ' . mysqli_error($conn));
    }
    while ($row = mysqli_fetch_assoc($resCab)) {
        $cabeceras[] = $row;
    }

    $porCodigo = mort_despacho_fetch_causas_por_codigo_sql($conn, $filtros);
    $tablaUi = mort_despacho_agregar_causas($porCodigo);

    $sinMotivoValido = [];
    $condsSinMot = mort_despacho_sql_conds_causas_listado($conn, $filtros, false);
    $joinFact = mort_despacho_sql_join_causa_fact('cz', 'mz', 'd');
    $exprCod = mort_despacho_sql_expr_cod_mort_linea('mz', 'd');
    $causasIn = mort_despacho_codigos_causa_listado_sql_in();
    $sqlSinMot = "
    SELECT
        cz.external_id AS id_registro,
        cz.tfecrem AS fecha_registro,
        {$exprCod} AS cod_mort,
        TRIM(COALESCE(r.tnom_mort, r2.tnom_mort, '')) AS nom_mort,
        mz.tcantid AS cantidad,
        mz.tfectra AS fecha_movimiento
    FROM cabe_zonas cz
    INNER JOIN movi_zonas mz ON
        cz.mark = mz.mark AND cz.treg = mz.treg
        AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
    {$joinFact}
    LEFT JOIN regmotivo_mortalidadgrs r ON r.tcod_mort = mz.tcod_mortgrs
    LEFT JOIN regmotivo_mortalidadgrs r2 ON r2.tcod_mort = d.codMortalidad
    WHERE " . implode("\n        AND ", $condsSinMot) . "
      AND {$exprCod} NOT IN {$causasIn}
    ORDER BY cz.tfecrem ASC";
    if (!is_string($sqlSinMot) || trim($sqlSinMot) === '') {
        throw new RuntimeException('SQL debug excluidas vacío');
    }
    $resSin = mysqli_query($conn, $sqlSinMot);
    if ($resSin) {
        while ($row = mysqli_fetch_assoc($resSin)) {
            $sinMotivoValido[] = $row;
        }
    }

    $sumDetalle = 0;
    foreach ($filasDetalle as $f) {
        $sumDetalle += (int) ($f['cantidad'] ?? 0);
    }

    return [
        'rango' => $rango,
        'filtros' => [
            'periodoTipo' => $filtros['periodoTipo'] ?? '',
            'granja' => $filtros['granja'] ?? '',
            'campania' => $filtros['campania'] ?? '',
            'cencos_list' => $filtros['cencos_list'] ?? [],
        ],
        'codigos_motivo_despacho' => mort_despacho_codigos_causa_listado_list(),
        'sql' => [
            'detalle_tabla1' => trim($sqlDetalle),
            'cabeceras_listado' => trim($sqlCab),
        ],
        'cabeceras_listado_despacho' => $cabeceras,
        'lineas_usadas_tabla1' => $filasDetalle,
        'totales' => [
            'lineas_tabla1' => count($filasDetalle),
            'aves_tabla1' => $sumDetalle,
            'cabeceras_listado' => count($cabeceras),
            'aves_cabeceras' => array_sum(array_map(static function (array $c): int {
                return (int) ($c['total_aves'] ?? 0);
            }, $cabeceras)),
        ],
        'agregado_por_codigo' => $porCodigo,
        'tabla1_ui' => $tablaUi,
        'lineas_excluidas_motivo_no_despacho' => $sinMotivoValido,
        'nota' => 'tabla1: JD4 + tfecrem; motivos 05/14/17/18/19 (05 = Muerte súbita en datos zonas).',
    ];
}

/**
 * S808 mortalidad despacho pre-agregada (periodo acotado en WHERE, luego JOIN a claves S700).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_subquery_s808_mort_resumen(mysqli $conn, array $filtros): string
{
    static $cache = [];
    $cacheId = md5(json_encode(mort_despacho_filtros_clave_cache($filtros)) . '|s808_res');
    if (isset($cache[$cacheId])) {
        return $cache[$cacheId];
    }

    $causas = mort_despacho_codigos_causa_resumen_sql_in();
    $rango = mort_ventas_rango($filtros);
    $unDia = mort_despacho_rango_es_un_dia($rango);

    $conds = [
        "mz.tcodtra = 'S808'",
        "mz.tcodigo IN ('P0001001','P0001002')",
        'mz.tcantid > 0',
        "LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') IN {$causas}",
    ];
    mort_despacho_append_rango_tfectra($conn, $filtros, 'mz', $conds);
    mort_despacho_append_filtros_cenco_granja($conn, $filtros, 'mz', $conds);

    $cenco6Expr = "CONCAT(
            LPAD(LEFT(TRIM(mz.tcencos), 3), 3, '0'),
            LPAD(RIGHT(TRIM(mz.tcencos), 3), 3, '0')
        )";

    if ($unDia && $rango !== null) {
        $fechaEsc = mort_despacho_sql_esc($conn, $rango['desde']);
        $fechaSelect = "CAST('{$fechaEsc}' AS DATE) AS fecha";
        $groupBy = "
        {$cenco6Expr},
        TRIM(mz.tcencos),
        TRIM(CAST(mz.tcodint AS CHAR)),
        mz.tcodigo";
    } else {
        $fechaSelect = 'DATE(mz.tfectra) AS fecha';
        $groupBy = "
        DATE(mz.tfectra),
        {$cenco6Expr},
        TRIM(mz.tcencos),
        TRIM(CAST(mz.tcodint AS CHAR)),
        mz.tcodigo";
    }

    $sql = trim("
    SELECT
        {$fechaSelect},
        {$cenco6Expr} AS cenco6,
        TRIM(mz.tcencos) AS tcencos,
        TRIM(CAST(mz.tcodint AS CHAR)) AS galpon,
        mz.tcodigo AS tcodigo,
        COALESCE(SUM(mz.tcantid), 0) AS muertos
    FROM movi_zonas mz
    WHERE " . implode(' AND ', $conds) . "
    GROUP BY {$groupBy}");

    $cache[$cacheId] = $sql;

    return $sql;
}

/**
 * Tabla 3: subconsulta agregada por fecha + cenco6 (S700 + S808 resumen).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_resumen_cenco_agregado(mysqli $conn, array $filtros): string
{
    $fromClavesResumen = mort_despacho_sql_from_claves_s700($conn, $filtros, 'k');
    $fromMort = '(' . mort_despacho_sql_subquery_s808_mort_resumen($conn, $filtros) . ') AS m';

    return "
    SELECT
        k.fecha,
        k.cenco6,
        SUM(k.venta_macho + k.venta_hembra) AS cantidadDespachada,
        COALESCE(SUM(m.muertos), 0) AS muertos
    FROM {$fromClavesResumen}
    LEFT JOIN {$fromMort} ON
        m.fecha = k.fecha
        AND m.tcencos = k.tcencos
        AND m.galpon = k.galpon
        AND (
            (m.tcodigo = 'P0001001' AND k.venta_macho > 0)
            OR (m.tcodigo = 'P0001002' AND k.venta_hembra > 0)
        )
    GROUP BY k.fecha, k.cenco6
    HAVING SUM(k.venta_macho + k.venta_hembra) > 0";
}

/**
 * Tabla 3: resumen por cenco (consulta completa sin paginar; benchmark / diagnóstico).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_sql_text_resumen_cenco(mysqli $conn, array $filtros): string
{
    return mort_despacho_sql_resumen_cenco_agregado($conn, $filtros);
}

/**
 * @return array{page: int, pageSize: int, offset: int}
 */
function mort_despacho_parse_resumen_paginacion(array $input): array
{
    $page = (int) ($input['resumenPage'] ?? $input['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }
    $defaultSize = (int) MORT_DESPACHO_RESUMEN_PAGE_SIZE_DEFAULT;
    $maxSize = (int) MORT_DESPACHO_RESUMEN_PAGE_SIZE_MAX;
    $pageSize = (int) ($input['resumenPageSize'] ?? $input['pageSize'] ?? $defaultSize);
    if ($pageSize < 1) {
        $pageSize = $defaultSize;
    }
    if ($pageSize > $maxSize) {
        $pageSize = $maxSize;
    }

    return [
        'page' => $page,
        'pageSize' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
    ];
}

/**
 * Resumen cenco paginado + totales del periodo (3 consultas ligeras vs. volcar todo en PHP).
 *
 * @param array<string, mixed> $filtros
 * @return array{
 *   total: int,
 *   filas: list<array{fecha: string, cencos: string, cantidadDespachada: float, muertos: int}>,
 *   totales: array{cantidadDespachada: float, muertos: int, porcentajeMortDespacho: float}
 * }
 */
function mort_despacho_fetch_resumen_cenco_paginado(mysqli $conn, array $filtros, array $pag): array
{
    $inner = mort_despacho_sql_resumen_cenco_agregado($conn, $filtros);
    $offset = (int) $pag['offset'];
    $limit = (int) $pag['pageSize'];

    $sqlStats = "
    SELECT
        COUNT(*) AS n,
        COALESCE(SUM(q.cantidadDespachada), 0) AS cantidadDespachada,
        COALESCE(SUM(q.muertos), 0) AS muertos
    FROM ({$inner}) AS q";
    $resStats = mysqli_query($conn, $sqlStats);
    if (!$resStats) {
        throw new RuntimeException('Consulta resumen (estadísticas): ' . mysqli_error($conn));
    }
    $rowTot = mysqli_fetch_assoc($resStats) ?: [];
    $total = (int) ($rowTot['n'] ?? 0);
    $sumDesp = (float) ($rowTot['cantidadDespachada'] ?? 0);
    $sumMuertos = (int) ($rowTot['muertos'] ?? 0);
    $pctTot = $sumDesp > 0 ? round($sumMuertos * 100 / $sumDesp, 2) : 0.0;

    $filas = [];
    if ($total > 0 && $limit > 0) {
        $sqlPage = "
        SELECT q.fecha, q.cenco6, q.cantidadDespachada, q.muertos
        FROM ({$inner}) AS q
        ORDER BY q.fecha DESC, q.cenco6 ASC
        LIMIT {$offset}, {$limit}";
        $resPage = mysqli_query($conn, $sqlPage);
        if (!$resPage) {
            throw new RuntimeException('Consulta resumen (página): ' . mysqli_error($conn));
        }
        while ($row = mysqli_fetch_assoc($resPage)) {
            $filas[] = [
                'fecha' => (string) ($row['fecha'] ?? ''),
                'cencos' => (string) ($row['cenco6'] ?? ''),
                'cantidadDespachada' => (float) ($row['cantidadDespachada'] ?? 0),
                'muertos' => (int) ($row['muertos'] ?? 0),
            ];
        }
    }

    return [
        'total' => $total,
        'filas' => $filas,
        'totales' => [
            'cantidadDespachada' => $sumDesp,
            'muertos' => $sumMuertos,
            'porcentajeMortDespacho' => $pctTot,
        ],
    ];
}

/** @param array<string, mixed> $filtros */
function mort_despacho_sql_text_principal_unificado(mysqli $conn, array $filtros): string
{
    return mort_despacho_sql_text_causas_listado($conn, $filtros) . "\n\n-- resumen cenco (consulta aparte en PHP)\n\n"
        . mort_despacho_sql_text_resumen_cenco($conn, $filtros);
}

/**
 * Causas (listado JD4) + resumen cenco (S700/S808 operativo); dos consultas MySQL 5.7.
 *
 * @param array<string, mixed> $filtros
 * @return array{causas: list<array{cod_mort: string, cantidad: int}>, resumen: list<array{fecha: string, cencos: string, cantidadDespachada: float, muertos: int}>}
 */
function mort_despacho_fetch_principal_unificado(mysqli $conn, array $filtros): array
{
    static $cache = [];
    $cacheId = md5(json_encode(mort_despacho_filtros_clave_cache($filtros)));
    if (isset($cache[$cacheId])) {
        return $cache[$cacheId];
    }

    $causas = mort_despacho_fetch_causas_por_codigo_sql($conn, $filtros);

    $sqlResumen = mort_despacho_sql_text_resumen_cenco($conn, $filtros);
    $res = mysqli_query($conn, $sqlResumen);
    if (!$res) {
        throw new RuntimeException('Consulta resumen cenco: ' . mysqli_error($conn));
    }

    $resumen = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $resumen[] = [
            'fecha' => (string) ($row['fecha'] ?? ''),
            'cencos' => (string) ($row['cenco6'] ?? ''),
            'cantidadDespachada' => (float) ($row['cantidadDespachada'] ?? 0),
            'muertos' => (int) ($row['muertos'] ?? 0),
        ];
    }

    if (count($resumen) > MORT_DESPACHO_MAX_KEYS_VENTA) {
        throw new RuntimeException(
            'Demasiados registros con despacho (' . count($resumen) . '). Acote el periodo o el filtro de granjas.'
        );
    }

    $out = ['causas' => $causas, 'resumen' => $resumen];
    $cache[$cacheId] = $out;

    return $out;
}

/**
 * Emparejamiento S808 ↔ S700: cenco, galpón, día y sexo (pollos P0001001 / P0001002).
 */
function mort_despacho_sql_on_s808_par_s700(string $aliasMz = 'mz', string $aliasKeys = 'k'): string
{
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';
    $k = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasKeys) ?: 'k';

    return "{$m}.tcodtra = 'S808'
        AND {$m}.tcodigo IN ('P0001001','P0001002')
        AND {$m}.tcantid > 0
        AND {$m}.tfectra >= {$k}.fecha
        AND {$m}.tfectra < DATE_ADD({$k}.fecha, INTERVAL 1 DAY)
        AND TRIM({$m}.tcencos) = {$k}.tcencos
        AND TRIM(CAST({$m}.tcodint AS CHAR)) = {$k}.galpon
        AND (
            ({$m}.tcodigo = 'P0001001' AND {$k}.venta_macho > 0)
            OR ({$m}.tcodigo = 'P0001002' AND {$k}.venta_hembra > 0)
        )";
}

/** Resumen / galpón: criterio operativo ventas (causas ampliadas). */
function mort_despacho_sql_on_s808_resumen(string $aliasMz = 'mz', string $aliasKeys = 'k'): string
{
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';
    $causas = mort_despacho_codigos_causa_resumen_sql_in();

    return mort_despacho_sql_on_s808_par_s700($aliasMz, $aliasKeys) . "
        AND LPAD(TRIM(COALESCE({$m}.tcod_mortgrs, '')), 2, '0') IN {$causas}";
}

/**
 * @deprecated Tabla 1 usa cabe_zonas JD4 (mort_despacho_sql_text_causas_listado). Referencia diagnóstico.
 */
function mort_despacho_sql_on_s808_listado(string $aliasMz = 'mz', string $aliasKeys = 'k'): string
{
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';
    $causas = mort_despacho_codigos_causa_listado_sql_in();

    return mort_despacho_sql_on_s808_par_s700($aliasMz, $aliasKeys) . "
        AND TRIM({$m}.mark) = 'JD4'
        AND LPAD(TRIM(COALESCE({$m}.tcod_mortgrs, '')), 2, '0') IN {$causas}";
}

/** @deprecated Alias resumen */
function mort_despacho_sql_on_s808_claves(string $aliasMz = 'mz', string $aliasKeys = 'k'): string
{
    return mort_despacho_sql_on_s808_resumen($aliasMz, $aliasKeys);
}

/**
 * Filas galpón-día (venta + mort despacho) usando claves S700 + S808 acotado.
 *
 * @return list<array<string, mixed>>
 */
function mort_despacho_ventas_agrupada_filas(mysqli $conn, array $filtros): array
{
    static $cache = [];
    $key = md5(json_encode(mort_despacho_filtros_clave_cache($filtros)));
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $fromClaves = mort_despacho_sql_from_claves_s700($conn, $filtros, 'k');
    $fromMort = '(' . mort_despacho_sql_subquery_s808_mort_resumen($conn, $filtros) . ') AS m';
    $macho = "'P0001001'";
    $hembra = "'P0001002'";

    $sql = "
    SELECT
        k.fecha,
        LEFT(k.cenco6, 3) AS granja,
        RIGHT(k.cenco6, 3) AS campania,
        k.galpon,
        (k.venta_macho + k.venta_hembra) AS venta,
        COALESCE(SUM(m.muertos), 0) AS mortalidad,
        k.venta_macho AS venta_macho,
        k.venta_hembra AS venta_hembra,
        COALESCE(SUM(CASE WHEN m.tcodigo = {$macho} THEN m.muertos ELSE 0 END), 0) AS mort_macho,
        COALESCE(SUM(CASE WHEN m.tcodigo = {$hembra} THEN m.muertos ELSE 0 END), 0) AS mort_hembra,
        CASE WHEN k.venta_macho > 0 THEN COALESCE(SUM(CASE WHEN m.tcodigo = {$macho} THEN m.muertos ELSE 0 END), 0) ELSE 0 END AS mort_desp_macho,
        CASE WHEN k.venta_hembra > 0 THEN COALESCE(SUM(CASE WHEN m.tcodigo = {$hembra} THEN m.muertos ELSE 0 END), 0) ELSE 0 END AS mort_desp_hembra
    FROM {$fromClaves}
    LEFT JOIN {$fromMort} ON
        m.fecha = k.fecha
        AND m.tcencos = k.tcencos
        AND m.galpon = k.galpon
        AND (
            (m.tcodigo = {$macho} AND k.venta_macho > 0)
            OR (m.tcodigo = {$hembra} AND k.venta_hembra > 0)
        )
    GROUP BY
        k.fecha, k.cenco6, k.galpon, k.venta_macho, k.venta_hembra, k.tcencos
    ORDER BY k.fecha DESC, k.cenco6 ASC, k.galpon ASC";

    $rows = [];
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new RuntimeException('Consulta ventas despacho: ' . mysqli_error($conn));
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    if (count($rows) > MORT_DESPACHO_MAX_KEYS_VENTA) {
        throw new RuntimeException(
            'Demasiados despachos en el periodo (' . count($rows) . '). Acote granjas/campañas o reduzca el rango.'
        );
    }
    $cache[$key] = $rows;

    return $rows;
}

/**
 * Análisis de mortalidad en el proceso de despacho (causas, etapas y resumen por granja).
 *
 * Tabla 1 (causas): listado — cabe_zonas JD4, motivos despacho (05, 14, 17, 18, 19),
 * periodo por tfecrem (fechaRegistro).
 * Tabla 3 (resumen): S700 + S808 con causas 05, 14, 15, 17, 18, 19 y par S700
 * mismo sexo/cenco/galpón/día.
 */

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
            'periodoTipo' => 'POR_MES',
            'fechaUnica' => date('Y-m-d'),
            'fechaInicio' => date('Y-m-01'),
            'fechaFin' => date('Y-m-t'),
            'mesUnico' => date('Y-m'),
            'mesInicio' => date('Y-m'),
            'mesFin' => date('Y-m'),
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
        if ($k === 'cencos_list' || !array_key_exists($k, $input)) {
            continue;
        }
        $f[$k] = trim((string) $input[$k]);
    }
    $gDigits = preg_replace('/\D/', '', $f['granja']) ?? '';
    $f['granja'] = $gDigits !== '' ? substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    $cencosRaw = trim((string) ($input['cencos'] ?? ''));
    $f['cencos_list'] = mort_despacho_normalizar_lista_cencos($cencosRaw);

    // Evita periodo vacío → rango null y consultas sin filtro de fecha en movi_zonas.
    $tipo = (string) ($f['periodoTipo'] ?? '');
    if ($tipo === 'POR_MES' && trim((string) ($f['mesUnico'] ?? '')) === '') {
        $f['mesUnico'] = date('Y-m');
    }
    if ($tipo === 'ENTRE_FECHAS') {
        if (trim((string) ($f['fechaInicio'] ?? '')) === '' || trim((string) ($f['fechaFin'] ?? '')) === '') {
            $f['fechaInicio'] = date('Y-m-01');
            $f['fechaFin'] = date('Y-m-t');
        }
    }

    return $f;
}

/**
 * Cenco de 6 dígitos (granja 3 + campaña 3) con ceros a la izquierda.
 */
function mort_despacho_cenco_seis(string $granja, string $campania): string
{
    $gDigits = preg_replace('/\D/', '', trim($granja)) ?? '';
    $cDigits = preg_replace('/\D/', '', trim($campania)) ?? '';
    $g = substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3);
    $c = substr(str_pad($cDigits, 3, '0', STR_PAD_LEFT), -3);

    return $g . $c;
}

/**
 * Normaliza tcencos como Ventas: LEFT(TRIM,3) + RIGHT(TRIM,3) con LPAD.
 */
function mort_despacho_cenco_desde_tcencos(string $tcencos): string
{
    $t = trim($tcencos);
    if ($t === '') {
        return '';
    }

    return mort_despacho_cenco_seis(substr($t, 0, 3), substr($t, -3));
}

/** Expresión SQL del cenco 6 dígitos (granja+campaña) al estilo Ventas. */
function mort_despacho_sql_expr_cenco(string $alias = 'mz'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';

    return "CONCAT(LPAD(LEFT(TRIM({$a}.tcencos), 3), 3, '0'), LPAD(RIGHT(TRIM({$a}.tcencos), 3), 3, '0'))";
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
            $ins[] = "'" . mort_despacho_sql_esc($conn, $c) . "'";
        }
    }
    if ($ins !== []) {
        $expr = mort_despacho_sql_expr_cenco($a);
        $conds[] = "{$expr} IN (" . implode(',', $ins) . ')';
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
        ['key' => 'asfixia', 'codigos' => ['17'], 'label' => 'Asfixia'],
        ['key' => 'muerte_subita', 'codigos' => ['05', '14'], 'label' => 'Muerte súbita'],
        ['key' => 'degollamiento', 'codigos' => ['19'], 'label' => 'Degollamiento'],
        ['key' => 'situacion_especial', 'codigos' => [], 'label' => 'Situación especial'],
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

/** Resumen despacho (S700/S808 operativo, distinto de tabla causas). */
function mort_despacho_codigos_causa_resumen_sql_in(): string
{
    return "('05','14','15','17','18','19')";
}

/** Tabla 1: mismo criterio que listado/get_motivos_listado.php → despacho. */
function mort_despacho_codigos_causa_listado_sql_in(): string
{
    $codes = mort_despacho_codigos_causa_listado_list();
    if ($codes === []) {
        return "('')";
    }
    $quoted = array_map(static function (string $c): string {
        return "'" . $c . "'";
    }, $codes);

    return '(' . implode(',', $quoted) . ')';
}

/**
 * Motivos tabla 1 despacho. Incluye 05 (Muerte súbita en zonas JD4) además de 14/17/18/19.
 *
 * @return list<string>
 */
function mort_despacho_codigos_causa_listado_list(): array
{
    return ['05', '14', '17', '18', '19'];
}

function mort_despacho_codigos_causa_sql_in(): string
{
    return mort_despacho_codigos_causa_listado_sql_in();
}

/**
 * Causas (tabla 1): cabecera JD4 + motivos listado (sin criterio ventas S700/S808 resumen).
 *
 * @return list<array{cod_mort: string, cantidad: int}>
 */
function mort_despacho_causas_listado_agregadas_sql(mysqli $conn, array $filtros): array
{
    return mort_despacho_fetch_principal_unificado($conn, $filtros)['causas'];
}

/**
 * Condición SQL: fila movi_zonas S808 es mortalidad de despacho (pollos + causas).
 */
function mort_despacho_sql_es_despacho(string $alias = 'mz'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $in = mort_despacho_codigos_causa_sql_in();

    return "(TRIM({$a}.tcodigo) IN ('P0001001','P0001002')
        AND LPAD(TRIM(COALESCE({$a}.tcod_mortgrs, '')), 2, '0') IN {$in})";
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

    mort_despacho_append_rango_tfectra($conn, $filtros, $a, $conds);

    $lista = $filtros['cencos_list'] ?? [];
    if (is_array($lista) && $lista !== []) {
        mort_despacho_append_filtro_cencos($conn, $filtros, $a, $conds);
    } else {
        $granja = trim((string) ($filtros['granja'] ?? ''));
        if ($granja !== '') {
            $gEsc = mort_despacho_sql_esc($conn, $granja);
            $conds[] = "LEFT(TRIM({$a}.tcencos), 3) = '{$gEsc}'";
        }

        $campania = trim((string) ($filtros['campania'] ?? ''));
        if ($campania !== '') {
            $cEsc = mort_despacho_sql_esc($conn, $campania);
            $conds[] = "RIGHT(TRIM({$a}.tcencos), 3) = '{$cEsc}'";
        }
    }

    return implode(' AND ', $conds);
}

/**
 * WHERE para movimientos S700 (saca / cantidad despachada), mismos filtros de periodo y cenco.
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_where_s700(mysqli $conn, array $filtros, string $alias = 'mz'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $conds = [
        "TRIM({$a}.tcodigo) IN ('P0001001','P0001002')",
        "TRIM({$a}.tcodtra) = 'S700'",
    ];

    mort_despacho_append_rango_tfectra($conn, $filtros, $a, $conds);

    $lista = $filtros['cencos_list'] ?? [];
    if (is_array($lista) && $lista !== []) {
        mort_despacho_append_filtro_cencos($conn, $filtros, $a, $conds);
    } else {
        $granja = trim((string) ($filtros['granja'] ?? ''));
        if ($granja !== '') {
            $gEsc = mort_despacho_sql_esc($conn, $granja);
            $conds[] = "LEFT(TRIM({$a}.tcencos), 3) = '{$gEsc}'";
        }
        $campania = trim((string) ($filtros['campania'] ?? ''));
        if ($campania !== '') {
            $cEsc = mort_despacho_sql_esc($conn, $campania);
            $conds[] = "RIGHT(TRIM({$a}.tcencos), 3) = '{$cEsc}'";
        }
    }

    return implode(' AND ', $conds);
}

/**
 * SQL agregado: cantidades por código de causa (mortalidad despacho válida).
 *
 * @return list<array{cod_mort: string, cantidad: int}>
 */
function mort_despacho_causas_agregadas_sql(mysqli $conn, array $filtros): array
{
    return mort_despacho_causas_listado_agregadas_sql($conn, $filtros);
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
        $desde = mort_despacho_sql_esc($conn, $rango['desde']);
        $hasta = mort_despacho_sql_esc($conn, $rango['hasta']);
        $conds[] = "c.fechaRegistro BETWEEN '{$desde}' AND '{$hasta}'";
    }
    $listaCencos = $filtros['cencos_list'] ?? [];
    if (is_array($listaCencos) && $listaCencos !== []) {
        $ins = [];
        foreach ($listaCencos as $c) {
            $c = trim((string) $c);
            if (preg_match('/^\d{6}$/', $c)) {
                $ins[] = "'" . mort_despacho_sql_esc($conn, $c) . "'";
            }
        }
        if ($ins !== []) {
            $conds[] = 'CONCAT(TRIM(c.granja), TRIM(c.campania)) IN (' . implode(',', $ins) . ')';
        }
    } else {
        $granja = trim((string) ($filtros['granja'] ?? ''));
        if ($granja !== '') {
            $gEsc = mort_despacho_sql_esc($conn, $granja);
            $conds[] = "c.granja = '{$gEsc}'";
        }
        $campania = trim((string) ($filtros['campania'] ?? ''));
        if ($campania !== '') {
            $cEsc = mort_despacho_sql_esc($conn, $campania);
            $conds[] = "c.campania = '{$cEsc}'";
        }
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

    $fromKeys = mort_despacho_sql_from_claves_s700($conn, $filtros, 'k');
    $where = implode(' AND ', $conds);

    $sql = '
    SELECT ' . implode(', ', $selectSum) . "
    FROM san_fact_mortalidad_det d
    INNER JOIN san_fact_mortalidad_cab c ON c.id = d.cabId
    INNER JOIN {$fromKeys} ON
        k.fecha = c.fechaRegistro
        AND k.cenco6 = CONCAT(
            LPAD(TRIM(c.granja), 3, '0'),
            LPAD(TRIM(c.campania), 3, '0')
        )
        AND TRIM(c.galpon) = k.galpon
    WHERE {$where}
      AND (
            (d.sexo = 'M' AND k.venta_macho > 0)
         OR (d.sexo = 'H' AND k.venta_hembra > 0)
      )
    ";

    $res = @mysqli_query($conn, $sql);
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
function mort_despacho_consultar_etapas_vacio(): array
{
    $totales = [];
    foreach (mort_despacho_etapas_display() as $d) {
        $totales[$d['col']] = 0;
    }

    return mort_despacho_armar_etapas_respuesta($totales);
}

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
        $granjasPermitidas[str_pad($g3, 3, '0', STR_PAD_LEFT)] = true;
    }
    if ($granjasPermitidas === []) {
        return [];
    }

    $inGranjas = [];
    foreach (array_keys($granjasPermitidas) as $g3) {
        $inGranjas[] = "'" . mort_despacho_sql_esc($conn, $g3) . "'";
    }

    $conds = [
        "LEFT(TRIM(c.codigo), 1) = '6'",
        'CHAR_LENGTH(TRIM(c.codigo)) = 6',
        "RIGHT(TRIM(c.codigo), 3) <> '000'",
        "TRIM(COALESCE(c.swac, 'A')) = 'A'",
        'LEFT(TRIM(c.codigo), 3) IN (' . implode(',', $inGranjas) . ')',
    ];
    if ($campaniaFiltro !== '') {
        $cEsc = mort_despacho_sql_esc($conn, $campaniaFiltro);
        $conds[] = "RIGHT(TRIM(c.codigo), 3) = '{$cEsc}'";
    }

    $sql = '
    SELECT DISTINCT
        TRIM(c.codigo) AS cencos,
        LEFT(TRIM(c.codigo), 3) AS granja,
        RIGHT(TRIM(c.codigo), 3) AS campania
    FROM ccos c
    WHERE ' . implode(' AND ', $conds) . '
    ORDER BY cencos ASC';

    $res = mysqli_query($conn, $sql);
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $granja = trim((string) ($row['granja'] ?? ''));
            $campania = trim((string) ($row['campania'] ?? ''));
            $c6 = mort_despacho_cenco_seis($granja, $campania);
            if ($c6 === '' || $c6 === '000000') {
                continue;
            }
            $out[] = [
                'cencos' => $c6,
                'granja' => substr($c6, 0, 3),
                'campania' => substr($c6, 3, 3),
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
            $c6 = mort_despacho_cenco_desde_tcencos((string) ($row['cencos'] ?? ''));
            if ($c6 === '') {
                continue;
            }
            $out[] = [
                'cencos' => $c6,
                'granja' => substr($c6, 0, 3),
                'campania' => substr($c6, 3, 3),
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
 * Filtro extra sobre el subquery de ventas (lista de cencos de 6 dígitos).
 */
function mort_despacho_sql_filtro_cencos_en_x(mysqli $conn, array $filtros): string
{
    $lista = $filtros['cencos_list'] ?? [];
    if (!is_array($lista) || $lista === []) {
        return '';
    }
    $ins = [];
    foreach ($lista as $c) {
        $c = trim((string) $c);
        if (preg_match('/^\d{6}$/', $c)) {
            $ins[] = "'" . mort_despacho_sql_esc($conn, $c) . "'";
        }
    }
    if ($ins === []) {
        return '';
    }

    return ' AND CONCAT(LPAD(TRIM(x.granja), 3, \'0\'), LPAD(TRIM(x.campania), 3, \'0\')) IN (' . implode(',', $ins) . ')';
}

/**
 * Resumen por fecha + cenco6: suma todos los galpones del cenco (tabla 3).
 *
 * @param list<array<string, mixed>> $filas
 * @return array<string, array{cantidadDespachada: float, muertos: int}>
 */
function mort_despacho_resumen_stats_map_desde_filas(array $filas): array
{
    $map = [];
    foreach ($filas as $row) {
        $venta = (float) ($row['venta'] ?? 0);
        if ($venta <= 0) {
            continue;
        }
        $fecha = (string) ($row['fecha'] ?? '');
        $cenco = mort_despacho_cenco_seis(
            (string) ($row['granja'] ?? ''),
            (string) ($row['campania'] ?? '')
        );
        if ($fecha === '' || $cenco === '') {
            continue;
        }
        $key = $fecha . '|' . $cenco;
        if (!isset($map[$key])) {
            $map[$key] = ['cantidadDespachada' => 0.0, 'muertos' => 0];
        }
        $map[$key]['cantidadDespachada'] += $venta;
        $map[$key]['muertos'] += (int) ($row['mort_desp_macho'] ?? 0) + (int) ($row['mort_desp_hembra'] ?? 0);
    }

    return $map;
}

function mort_despacho_resumen_stats_map(mysqli $conn, array $filtros): array
{
    return mort_despacho_resumen_stats_map_desde_filas(
        mort_despacho_ventas_agrupada_filas($conn, $filtros)
    );
}

/**
 * Resumen agregado por fecha + cenco6 (sin detalle galpón; más rápido que ventas_agrupada).
 *
 * @return list<array{fecha: string, cencos: string, cantidadDespachada: float, muertos: int}>
 */
function mort_despacho_resumen_cenco_sql_filas(mysqli $conn, array $filtros): array
{
    return mort_despacho_fetch_principal_unificado($conn, $filtros)['resumen'];
}

/**
 * Resumen por fecha y cenco con saca (S700) > 0 (totales cenco, no por galpón).
 *
 * @param list<array<string, mixed>>|null $filasGalpon solo para health/diagnóstico legacy
 * @return list<array<string, mixed>>
 */
function mort_despacho_consultar_resumen_granjas(mysqli $conn, array $filtros, ?array $filasGalpon = null): array
{
    $filas = [];
    if ($filasGalpon !== null) {
        $stats = mort_despacho_resumen_stats_map_desde_filas($filasGalpon);
        if ($stats === []) {
            return [];
        }
        foreach ($stats as $key => $st) {
            $cantDesp = (float) ($st['cantidadDespachada'] ?? 0);
            if ($cantDesp <= 0) {
                continue;
            }
            $parts = explode('|', $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $cencos = $parts[1];
            if (strlen($cencos) < 6) {
                continue;
            }
            $filas[] = [
                'fecha' => $parts[0],
                'cencos' => $cencos,
                'granjaCod' => substr($cencos, 0, 3),
                'campania' => substr($cencos, 3, 3),
                'cantidadDespachada' => $cantDesp,
                'muertos' => (int) ($st['muertos'] ?? 0),
                'porcentajeMortDespacho' => round((int) ($st['muertos'] ?? 0) * 100 / $cantDesp, 2),
            ];
        }
    } else {
        return mort_despacho_consultar_resumen_granjas_desde_filas_cenco(
            $conn,
            mort_despacho_resumen_cenco_sql_filas($conn, $filtros)
        );
    }

    if ($filas === []) {
        return [];
    }

    return mort_despacho_empaquetar_filas_resumen_ui($conn, $filas);
}

/**
 * @param list<array{fecha: string, cencos: string, cantidadDespachada: float, muertos: int}> $filasCenco
 * @return list<array<string, mixed>>
 */
function mort_despacho_consultar_resumen_granjas_desde_filas_cenco(mysqli $conn, array $filasCenco, int $numeroInicio = 1): array
{
    $filas = [];
    foreach ($filasCenco as $row) {
        $cantDesp = (float) ($row['cantidadDespachada'] ?? 0);
        if ($cantDesp <= 0) {
            continue;
        }
        $cencos = (string) ($row['cencos'] ?? '');
        if (strlen($cencos) < 6) {
            continue;
        }
        $muertos = (int) ($row['muertos'] ?? 0);
        $filas[] = [
            'fecha' => (string) ($row['fecha'] ?? ''),
            'cencos' => $cencos,
            'granjaCod' => substr($cencos, 0, 3),
            'campania' => substr($cencos, 3, 3),
            'cantidadDespachada' => $cantDesp,
            'muertos' => $muertos,
            'porcentajeMortDespacho' => round($muertos * 100 / $cantDesp, 2),
        ];
    }

    return mort_despacho_empaquetar_filas_resumen_ui($conn, $filas, $numeroInicio);
}

/**
 * @param list<array<string, mixed>> $filas
 * @return list<array<string, mixed>>
 */
function mort_despacho_empaquetar_filas_resumen_ui(mysqli $conn, array $filas, int $numeroInicio = 1): array
{
    if ($filas === []) {
        return [];
    }

    if ($numeroInicio <= 1 && count($filas) > MORT_DESPACHO_MAX_KEYS_VENTA) {
        throw new RuntimeException(
            'Demasiados registros con despacho (' . count($filas) . '). Acote el periodo o el filtro de granjas.'
        );
    }

    usort($filas, static function ($a, $b) {
        $cmp = strcmp((string) $b['fecha'], (string) $a['fecha']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) $a['cencos'], (string) $b['cencos']);
    });

    $g3List = [];
    foreach ($filas as $row) {
        $g3List[$row['granjaCod']] = true;
    }
    $nombres = mort_despacho_nombres_granja_lista($conn, array_keys($g3List));
    $out = [];
    $n = max(0, $numeroInicio - 1);
    foreach ($filas as $row) {
        $granja = $row['granjaCod'];
        $campania = $row['campania'];
        $nomBase = $nombres[$granja] ?? '';
        $granjaLabel = $nomBase !== ''
            ? $nomBase . ' C=' . $campania
            : 'Granja ' . $granja . ' C=' . $campania;
        $n++;
        $out[] = [
            'numero' => $n,
            'fecha' => $row['fecha'],
            'cencos' => $row['cencos'],
            'granja' => $granjaLabel,
            'granjaCod' => $granja,
            'campania' => $campania,
            'cantidadDespachada' => $row['cantidadDespachada'],
            'muertos' => $row['muertos'],
            'porcentajeMortDespacho' => $row['porcentajeMortDespacho'],
        ];
    }

    return $out;
}

/**
 * @param list<string> $g3List
 * @return array<string, string> granja3 => nombre
 */
function mort_despacho_nombres_granja_lista(mysqli $conn, array $g3List): array
{
    $out = [];
    $codes = [];
    foreach ($g3List as $g) {
        $g = substr(str_pad(trim((string) $g), 3, '0', STR_PAD_LEFT), 0, 3);
        if ($g === '') {
            continue;
        }
        $codes[$g] = "'" . mort_despacho_sql_esc($conn, $g . '000') . "'";
    }
    if ($codes === []) {
        return $out;
    }
    $q = mysqli_query($conn, 'SELECT TRIM(codigo) AS codigo, TRIM(nombre) AS nombre FROM ccos WHERE codigo IN (' . implode(',', array_values($codes)) . ')');
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $cod = trim((string) ($row['codigo'] ?? ''));
            $g3 = strlen($cod) >= 3 ? substr($cod, 0, 3) : $cod;
            if ($g3 !== '') {
                $out[$g3] = trim((string) ($row['nombre'] ?? ''));
            }
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
/**
 * Resumen + causas (sin etapas; más rápido para mostrar en UI).
 *
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_analisis_bloque_principal(mysqli $conn, array $filtros): array
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }

    $causasRaw = mort_despacho_fetch_causas_por_codigo_sql($conn, $filtros);
    $causas = mort_despacho_agregar_causas($causasRaw);

    return [
        'rango' => $rango,
        'causas' => $causas,
    ];
}

/**
 * Tabla resumen paginada (SQL LIMIT).
 *
 * @param array<string, mixed> $filtros
 * @param array{page: int, pageSize: int, offset: int} $pag
 * @return array<string, mixed>
 */
function mort_despacho_analisis_bloque_resumen(mysqli $conn, array $filtros, array $pag): array
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }

    $fetch = mort_despacho_fetch_resumen_cenco_paginado($conn, $filtros, $pag);
    $numeroInicio = (int) $pag['offset'] + 1;
    $resumen = mort_despacho_consultar_resumen_granjas_desde_filas_cenco($conn, $fetch['filas'], $numeroInicio);
    $total = $fetch['total'];
    $pageSize = (int) $pag['pageSize'];
    $page = (int) $pag['page'];
    $totalPaginas = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;
    if ($totalPaginas > 0 && $page > $totalPaginas) {
        $page = $totalPaginas;
    }

    return [
        'rango' => $rango,
        'resumenGranjas' => $resumen,
        'resumenPaginacion' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'totalFilas' => $total,
            'totalPaginas' => $totalPaginas,
        ],
        'resumenTotales' => $fetch['totales'],
    ];
}

/**
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_analisis_bloque_etapas(mysqli $conn, array $filtros): array
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }

    try {
        $etapas = mort_despacho_consultar_etapas($conn, $filtros);
    } catch (\Throwable $e) {
        $etapas = mort_despacho_consultar_etapas_vacio();
    }

    return [
        'rango' => $rango,
        'etapas' => $etapas,
    ];
}

function mort_despacho_analisis_completo(mysqli $conn, array $filtros, array $inputPaginacion = []): array
{
    $principal = mort_despacho_analisis_bloque_principal($conn, $filtros);
    $etapasBlock = mort_despacho_analisis_bloque_etapas($conn, $filtros);
    $pag = mort_despacho_parse_resumen_paginacion($inputPaginacion);
    $resumenBlock = mort_despacho_analisis_bloque_resumen($conn, $filtros, $pag);

    return [
        'rango' => $principal['rango'],
        'causas' => $principal['causas'],
        'etapas' => $etapasBlock['etapas'],
        'resumenGranjas' => $resumenBlock['resumenGranjas'],
        'resumenPaginacion' => $resumenBlock['resumenPaginacion'],
        'resumenTotales' => $resumenBlock['resumenTotales'],
    ];
}

/**
 * S808 en el periodo sin S700 pareado (mismo tcencos, día, galpón y tcodigo/sexo).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_contar_s808_sin_par_s700(mysqli $conn, array $filtros): int
{
    $conds = [
        "TRIM(mz.tcodigo) IN ('P0001001','P0001002')",
        "TRIM(mz.tcodtra) = 'S808'",
        'mz.tcantid > 0',
    ];
    mort_despacho_append_rango_tfectra($conn, $filtros, 'mz', $conds);

    $lista = $filtros['cencos_list'] ?? [];
    if (is_array($lista) && $lista !== []) {
        mort_despacho_append_filtro_cencos($conn, $filtros, 'mz', $conds);
    } else {
        $granja = trim((string) ($filtros['granja'] ?? ''));
        if ($granja !== '') {
            $conds[] = "LEFT(TRIM(mz.tcencos), 3) = '" . mort_despacho_sql_esc($conn, $granja) . "'";
        }
        $campania = trim((string) ($filtros['campania'] ?? ''));
        if ($campania !== '') {
            $conds[] = "RIGHT(TRIM(mz.tcencos), 3) = '" . mort_despacho_sql_esc($conn, $campania) . "'";
        }
    }

    $where = implode(' AND ', $conds);
    $sql = "
    SELECT COUNT(*) AS n
    FROM movi_zonas mz
    WHERE {$where}
      AND NOT EXISTS (
        SELECT 1
        FROM movi_zonas s7
        WHERE TRIM(s7.tcodtra) = 'S700'
          AND TRIM(s7.tcodigo) = TRIM(mz.tcodigo)
          AND DATE(s7.tfectra) = DATE(mz.tfectra)
          AND TRIM(s7.tcencos) = TRIM(mz.tcencos)
          AND TRIM(CAST(s7.tcodint AS CHAR)) = TRIM(CAST(mz.tcodint AS CHAR))
          AND s7.tcantid > 0
      )";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new RuntimeException('Verificación S808/S700: ' . mysqli_error($conn));
    }
    $row = mysqli_fetch_assoc($res);

    return (int) ($row['n'] ?? 0);
}

/**
 * Texto SQL que ejecuta el análisis (para EXPLAIN en MySQL; no ejecuta las consultas).
 *
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_export_sql_preview(mysqli $conn, array $filtros): array
{
    $fromClaves = mort_despacho_sql_from_claves_s700($conn, $filtros, 'k');
    $sqlClavesS700 = mort_despacho_sql_subquery_claves_s700($conn, $filtros);
    $sqlCausasListado = mort_despacho_sql_text_causas_listado($conn, $filtros);
    $sqlS808Resumen = mort_despacho_sql_subquery_s808_mort_resumen($conn, $filtros);
    $sqlResumenCenco = mort_despacho_sql_text_resumen_cenco($conn, $filtros);

    $rango = mort_ventas_rango($filtros);
    $condsEtapas = ["c.tipoMortalidad = 'despacho'"];
    if ($rango !== null) {
        $desde = mort_despacho_sql_esc($conn, $rango['desde']);
        $hasta = mort_despacho_sql_esc($conn, $rango['hasta']);
        $condsEtapas[] = "c.fechaRegistro BETWEEN '{$desde}' AND '{$hasta}'";
    }
    $listaCencos = $filtros['cencos_list'] ?? [];
    if (is_array($listaCencos) && $listaCencos !== []) {
        $ins = [];
        foreach ($listaCencos as $c) {
            $c = trim((string) $c);
            if (preg_match('/^\d{6}$/', $c)) {
                $ins[] = "'" . mort_despacho_sql_esc($conn, $c) . "'";
            }
        }
        if ($ins !== []) {
            $condsEtapas[] = 'CONCAT(TRIM(c.granja), TRIM(c.campania)) IN (' . implode(',', $ins) . ')';
        }
    }
    $whereEtapas = implode("\n  AND ", $condsEtapas);
    $colsDisp = mort_despacho_etapas_display();
    $selectSum = [];
    foreach ($colsDisp as $d) {
        $selectSum[] = 'COALESCE(SUM(d.' . $d['col'] . '), 0) AS ' . $d['col'];
    }

    $sqlEtapas = '
SELECT ' . implode(', ', $selectSum) . "
FROM san_fact_mortalidad_det d
INNER JOIN san_fact_mortalidad_cab c ON c.id = d.cabId
INNER JOIN {$fromClaves} ON
    k.fecha = c.fechaRegistro
    AND k.cenco6 = CONCAT(LPAD(TRIM(c.granja), 3, '0'), LPAD(TRIM(c.campania), 3, '0'))
    AND TRIM(c.galpon) = k.galpon
WHERE {$whereEtapas}
  AND ((d.sexo = 'M' AND k.venta_macho > 0) OR (d.sexo = 'H' AND k.venta_hembra > 0))";

    return [
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'rango' => $rango,
        'filtros_parseados' => [
            'periodoTipo' => $filtros['periodoTipo'] ?? '',
            'cencos_list' => $filtros['cencos_list'] ?? [],
        ],
        'nota' => 'Tabla 3: S700 + subconsulta S808 agregada (tfectra/tcodtra) + JOIN claves. Etapas aparte.',
        'sql' => [
            '1_subquery_s700_solo' => $sqlClavesS700,
            '2_causas_listado_jd4' => trim($sqlCausasListado),
            '3a_subquery_s808_resumen' => trim($sqlS808Resumen),
            '3_resumen_cenco_s700' => trim($sqlResumenCenco),
            '4_etapas_san_fact' => trim($sqlEtapas),
            '5_nombres_granja' => "SELECT TRIM(codigo) AS codigo, TRIM(nombre) AS nombre FROM ccos WHERE codigo IN ('601000', ...); -- solo granjas del resumen",
        ],
        'join_on_s808_listado' => mort_despacho_sql_on_s808_listado('mz', 'k'),
        'join_on_s808_resumen' => mort_despacho_sql_on_s808_resumen('mz', 'k'),
        'codigos_causa_listado' => mort_despacho_codigos_causa_listado_sql_in(),
        'codigos_causa_resumen' => mort_despacho_codigos_causa_resumen_sql_in(),
    ];
}

/**
 * Ejecuta SQL y mide query + fetch (filas materializadas en PHP).
 *
 * @return array{ms_total: float, ms_query: float, ms_fetch: float, filas: int, ok: bool, error: ?string}
 */
function mort_despacho_medir_ejecucion_sql(mysqli $conn, string $sql): array
{
    $t0 = microtime(true);
    $res = mysqli_query($conn, $sql);
    $tQuery = microtime(true);
    $filas = 0;
    $error = null;
    if (!$res) {
        $error = mysqli_error($conn);
    } else {
        while (mysqli_fetch_assoc($res)) {
            $filas++;
        }
    }
    $t1 = microtime(true);

    return [
        'ms_total' => round(($t1 - $t0) * 1000, 2),
        'ms_query' => round(($tQuery - $t0) * 1000, 2),
        'ms_fetch' => round(($t1 - $tQuery) * 1000, 2),
        'filas' => $filas,
        'ok' => $error === null,
        'error' => $error,
    ];
}

/**
 * @return array{id: string, label: string, tipo: string, ms_total: float, ms_query: float, ms_fetch: float, filas: int, ok: bool, error: ?string}
 */
function mort_despacho_benchmark_paso_sql(mysqli $conn, string $id, string $label, string $sql): array
{
    $m = mort_despacho_medir_ejecucion_sql($conn, $sql);

    return array_merge(
        ['id' => $id, 'label' => $label, 'tipo' => 'sql'],
        $m
    );
}

/**
 * @param callable(): mixed $fn
 * @return array{id: string, label: string, tipo: string, ms_total: float, ok: bool, error: ?string, meta: array<string, mixed>}
 */
function mort_despacho_benchmark_paso_php(string $id, string $label, callable $fn): array
{
    $t0 = microtime(true);
    $error = null;
    $meta = [];
    try {
        $result = $fn();
        if (is_array($result)) {
            $meta = $result;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $t1 = microtime(true);

    return [
        'id' => $id,
        'label' => $label,
        'tipo' => 'php',
        'ms_total' => round(($t1 - $t0) * 1000, 2),
        'ms_query' => 0.0,
        'ms_fetch' => 0.0,
        'filas' => isset($meta['filas']) ? (int) $meta['filas'] : (isset($meta['total']) ? (int) $meta['total'] : 0),
        'ok' => $error === null,
        'error' => $error,
        'meta' => $meta,
    ];
}

/**
 * Benchmark del análisis despacho: tiempos por paso y cuál es el más pesado.
 *
 * @param array<string, mixed> $filtros
 * @return array<string, mixed>
 */
function mort_despacho_benchmark_analisis(mysqli $conn, array $filtros, bool $extendido = false): array
{
    $rango = mort_ventas_rango($filtros);
    if ($rango === null) {
        throw new RuntimeException('Periodo inválido o incompleto.');
    }

    @mysqli_query($conn, 'RESET QUERY CACHE');

    $preview = mort_despacho_export_sql_preview($conn, $filtros);
    $sqlS700 = (string) ($preview['sql']['1_subquery_s700_solo'] ?? '');
    $sqlCausas = (string) ($preview['sql']['2_causas_listado_jd4'] ?? '');
    $sqlResumen = (string) ($preview['sql']['3_resumen_cenco_s700'] ?? '');
    $sqlEtapas = (string) ($preview['sql']['4_etapas_san_fact'] ?? '');

    $pasos = [];

    if ($sqlS700 !== '') {
        $pasos[] = mort_despacho_benchmark_paso_sql(
            $conn,
            'sql_s700_claves',
            'SQL: subconsulta S700 (COUNT de claves día/cenco/galpón)',
            'SELECT COUNT(*) AS n FROM (' . $sqlS700 . ') k'
        );
    }

    if ($sqlCausas !== '') {
        $pasos[] = mort_despacho_benchmark_paso_sql(
            $conn,
            'sql_causas_listado',
            'SQL: tabla 1 causas (cabe_zonas JD4)',
            $sqlCausas
        );
    }

    if ($sqlResumen !== '') {
        $pasos[] = mort_despacho_benchmark_paso_sql(
            $conn,
            'sql_resumen_cenco',
            'SQL: tabla 3 resumen (S700 + S808)',
            $sqlResumen
        );
    }

    if ($sqlEtapas !== '') {
        $pasos[] = mort_despacho_benchmark_paso_sql(
            $conn,
            'sql_etapas',
            'SQL: etapas (san_fact + join S700)',
            $sqlEtapas
        );
    }

    $pasos[] = mort_despacho_benchmark_paso_php(
        'php_resumen_ui',
        'PHP: nombres granja (ccos) + orden resumen',
        static function () use ($conn, $filtros): array {
            $filasCenco = mort_despacho_resumen_cenco_sql_filas($conn, $filtros);
            $ui = mort_despacho_consultar_resumen_granjas_desde_filas_cenco($conn, $filasCenco);

            return ['filas' => count($ui)];
        }
    );

    $pasos[] = mort_despacho_benchmark_paso_php('etapas', 'UI bloque etapas (san_fact + claves S700)', static function () use ($conn, $filtros): array {
        $data = mort_despacho_consultar_etapas($conn, $filtros);

        return ['total' => (int) ($data['total'] ?? 0)];
    });

    $pasos[] = mort_despacho_benchmark_paso_php('bloque_principal', 'UI bloque principal (causas JD4)', static function () use ($conn, $filtros): array {
        $data = mort_despacho_analisis_bloque_principal($conn, $filtros);

        return [
            'total' => (int) (($data['causas']['total'] ?? 0)),
        ];
    });

    $pasos[] = mort_despacho_benchmark_paso_php('bloque_resumen_p1', 'UI resumen paginado (página 1)', static function () use ($conn, $filtros): array {
        $pag = mort_despacho_parse_resumen_paginacion([]);
        $data = mort_despacho_analisis_bloque_resumen($conn, $filtros, $pag);

        return [
            'filas' => count($data['resumenGranjas'] ?? []),
            'totalFilas' => (int) (($data['resumenPaginacion']['totalFilas'] ?? 0)),
        ];
    });

    if ($extendido) {
        $pasos[] = mort_despacho_benchmark_paso_php(
            'ventas_agrupada_galpon',
            'Legacy: ventas_agrupada por galpón (health)',
            static function () use ($conn, $filtros): array {
                $filas = mort_despacho_ventas_agrupada_filas($conn, $filtros);

                return ['filas' => count($filas)];
            }
        );

        $pasos[] = mort_despacho_benchmark_paso_php(
            's808_sin_s700',
            'Diagnóstico: S808 sin par S700',
            static function () use ($conn, $filtros): array {
                return ['filas' => mort_despacho_contar_s808_sin_par_s700($conn, $filtros)];
            }
        );
    }

    $ordenado = $pasos;
    usort($ordenado, static function (array $a, array $b): int {
        $cmp = ($b['ms_total'] <=> $a['ms_total']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) $a['id'], (string) $b['id']);
    });

    $masPesado = $ordenado[0] ?? null;

    $msPrincipal = 0.0;
    $msEtapas = 0.0;
    foreach ($pasos as $p) {
        if (($p['id'] ?? '') === 'bloque_principal') {
            $msPrincipal = (float) ($p['ms_total'] ?? 0);
        }
        if (($p['id'] ?? '') === 'etapas') {
            $msEtapas = (float) ($p['ms_total'] ?? 0);
        }
    }

    $sumSql = 0.0;
    $msCausas = 0.0;
    $msResumen = 0.0;
    foreach ($pasos as $p) {
        if (($p['tipo'] ?? '') === 'sql' && !empty($p['ok'])) {
            $sumSql += (float) ($p['ms_total'] ?? 0);
        }
        if (($p['id'] ?? '') === 'sql_causas_listado') {
            $msCausas = (float) ($p['ms_total'] ?? 0);
        }
        if (($p['id'] ?? '') === 'sql_resumen_cenco') {
            $msResumen = (float) ($p['ms_total'] ?? 0);
        }
    }

    $masPesadoSql = null;
    foreach ($ordenado as $p) {
        if (($p['tipo'] ?? '') === 'sql' && !empty($p['ok'])) {
            $masPesadoSql = $p;
            break;
        }
    }

    return [
        'libRev' => defined('MORT_DESPACHO_LIB_REV') ? MORT_DESPACHO_LIB_REV : null,
        'rango' => $rango,
        'filtros' => [
            'periodoTipo' => $filtros['periodoTipo'] ?? '',
            'granja' => $filtros['granja'] ?? '',
            'campania' => $filtros['campania'] ?? '',
            'cencos_list' => $filtros['cencos_list'] ?? [],
        ],
        'extendido' => $extendido,
        'pasos' => $pasos,
        'ordenado_por_ms' => $ordenado,
        'mas_pesado' => $masPesado,
        'mas_pesado_sql' => $masPesadoSql,
        'resumen_tiempos' => [
            'ms_ui_principal' => $msPrincipal,
            'ms_ui_etapas' => $msEtapas,
            'ms_ui_paralelo_aprox' => round(max($msPrincipal, $msEtapas), 2),
            'ms_sql_causas' => $msCausas,
            'ms_sql_resumen' => $msResumen,
            'ms_principal_sql_estimado' => round($msCausas + $msResumen, 2),
            'ms_sql_desglosado_suma' => round($sumSql, 2),
            'nota' => 'SQL primero (RESET QUERY CACHE). Use mas_pesado_sql para el cuello real; '
                . 'bloque_principal al final puede ser mucho más rápido por caché MySQL 5.7. '
                . 'UI paralela ≈ max(principal, etapas).',
        ],
    ];
}
