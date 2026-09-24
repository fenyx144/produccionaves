<?php

declare(strict_types=1);

/** Revisión desplegable (health / JSON libRev). Compatible PHP >= 7.2. */
if (!defined('MORT_DESPACHO_LIB_REV')) {
    define('MORT_DESPACHO_LIB_REV', '20260925f');
}

if (!defined('MORT_DESPACHO_MAX_KEYS_VENTA')) {
    define('MORT_DESPACHO_MAX_KEYS_VENTA', 40000);
}

if (!defined('MORT_DESPACHO_TEMP_KEYS')) {
    define('MORT_DESPACHO_TEMP_KEYS', 'mort_dsp_vkeys');
}

/** Tiempo máximo PHP/BD por petición de análisis (evita bloquear el servidor compartido). */
if (!defined('MORT_DESPACHO_QUERY_TIME_SEC')) {
    define('MORT_DESPACHO_QUERY_TIME_SEC', 90);
}

/** Sin filtro de cencos: como mucho un mes calendario. */
if (!defined('MORT_DESPACHO_MAX_DIAS_SIN_CENCOS')) {
    define('MORT_DESPACHO_MAX_DIAS_SIN_CENCOS', 31);
}

if (!defined('MORT_DESPACHO_MAX_DIAS_ABSOLUTO')) {
    define('MORT_DESPACHO_MAX_DIAS_ABSOLUTO', 366);
}

/** Consultas simultáneas Despacho por sesión (principal + etapas en paralelo). */
if (!defined('MORT_DESPACHO_MAX_CONCURRENT_PER_SESSION')) {
    define('MORT_DESPACHO_MAX_CONCURRENT_PER_SESSION', 2);
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
    @mysqli_query($conn, "SET SESSION max_execution_time = {$ms}");
    @mysqli_query($conn, "SET SESSION max_statement_time = {$sec}");
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

    $lista = $filtros['cencos_list'] ?? [];
    $tieneCencos = is_array($lista) && count($lista) > 0;
    if (!$tieneCencos && $dias > MORT_DESPACHO_MAX_DIAS_SIN_CENCOS) {
        throw new RuntimeException(
            'Sin granjas/campañas seleccionadas solo se permite hasta 31 días. '
            . 'Use el filtro de granjas o acorte el periodo (por ejemplo un mes).'
        );
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
function mort_despacho_append_filtros_movimiento(mysqli $conn, array $filtros, string $alias, array &$conds): void
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'mz';
    $conds[] = "LEFT(TRIM({$a}.tcencos), 1) = '6'";
    mort_despacho_append_rango_tfectra($conn, $filtros, $a, $conds);

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

function mort_despacho_temp_keys_drop(mysqli $conn): void
{
    $t = MORT_DESPACHO_TEMP_KEYS;
    @mysqli_query($conn, "DROP TEMPORARY TABLE IF EXISTS `{$t}`");
}

/**
 * Claves día+cenco+galpón con venta S700 (escaneo único y acotado).
 *
 * @param array<string, mixed> $filtros
 */
function mort_despacho_temp_keys_prepare(mysqli $conn, array $filtros): int
{
    static $preparedKey = null;
    static $preparedCount = null;

    $cacheId = md5(json_encode(mort_despacho_filtros_clave_cache($filtros)));
    if ($preparedKey === $cacheId && $preparedCount !== null) {
        return $preparedCount;
    }

    mort_despacho_temp_keys_drop($conn);
    $t = MORT_DESPACHO_TEMP_KEYS;

    $create = "CREATE TEMPORARY TABLE `{$t}` (
        fecha DATE NOT NULL,
        cenco6 CHAR(6) NOT NULL,
        tcencos VARCHAR(32) NOT NULL,
        galpon VARCHAR(24) NOT NULL,
        venta_macho DECIMAL(18,4) NOT NULL DEFAULT 0,
        venta_hembra DECIMAL(18,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (fecha, cenco6, galpon),
        KEY idx_tcencos (tcencos)
    ) ENGINE=MEMORY";
    if (!mysqli_query($conn, $create)) {
        throw new RuntimeException('Temp claves venta: ' . mysqli_error($conn));
    }

    $conds = [
        "TRIM(mz.tcodigo) IN ('P0001001','P0001002')",
        "TRIM(mz.tcodtra) = 'S700'",
        'mz.tcantid > 0',
    ];
    mort_despacho_append_filtros_movimiento($conn, $filtros, 'mz', $conds);

    $insert = "
    INSERT INTO `{$t}` (fecha, cenco6, tcencos, galpon, venta_macho, venta_hembra)
    SELECT
        DATE(mz.tfectra) AS fecha,
        CONCAT(
            LPAD(LEFT(TRIM(mz.tcencos), 3), 3, '0'),
            LPAD(RIGHT(TRIM(mz.tcencos), 3), 3, '0')
        ) AS cenco6,
        TRIM(mz.tcencos) AS tcencos,
        TRIM(CAST(mz.tcodint AS CHAR)) AS galpon,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS venta_macho,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS venta_hembra
    FROM movi_zonas mz
    WHERE " . implode(' AND ', $conds) . "
    GROUP BY
        DATE(mz.tfectra),
        CONCAT(LPAD(LEFT(TRIM(mz.tcencos), 3), 3, '0'), LPAD(RIGHT(TRIM(mz.tcencos), 3), 3, '0')),
        TRIM(mz.tcencos),
        TRIM(CAST(mz.tcodint AS CHAR))
    HAVING (COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001001' THEN mz.tcantid ELSE 0 END), 0)
          + COALESCE(SUM(CASE WHEN TRIM(mz.tcodigo) = 'P0001002' THEN mz.tcantid ELSE 0 END), 0)) > 0";

    if (!mysqli_query($conn, $insert)) {
        throw new RuntimeException('Claves venta S700: ' . mysqli_error($conn));
    }

    $res = mysqli_query($conn, "SELECT COUNT(*) AS n FROM `{$t}`");
    $n = 0;
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $n = (int) ($row['n'] ?? 0);
    }
    if ($n > MORT_DESPACHO_MAX_KEYS_VENTA) {
        mort_despacho_temp_keys_drop($conn);
        throw new RuntimeException(
            'Demasiados despachos en el periodo (' . $n . '). Acote granjas/campañas o reduzca el rango.'
        );
    }

    $preparedKey = $cacheId;
    $preparedCount = $n;

    return $n;
}

function mort_despacho_sql_join_claves_venta(string $aliasMz = 'mz', string $aliasKeys = 'k'): string
{
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasMz) ?: 'mz';
    $k = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasKeys) ?: 'k';
    $t = MORT_DESPACHO_TEMP_KEYS;

    return "INNER JOIN `{$t}` {$k} ON
        {$k}.fecha = DATE({$m}.tfectra)
        AND TRIM({$m}.tcencos) = {$k}.tcencos
        AND TRIM(CAST({$m}.tcodint AS CHAR)) = {$k}.galpon";
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

    $nKeys = mort_despacho_temp_keys_prepare($conn, $filtros);
    if ($nKeys === 0) {
        $cache[$key] = [];

        return [];
    }

    $t = MORT_DESPACHO_TEMP_KEYS;
    $causasDespacho = "('05','14','15','17','18','19')";
    $s808 = "'S808'";
    $macho = "'P0001001'";
    $hembra = "'P0001002'";

    $joinMz = mort_despacho_sql_join_claves_venta('mz', 'k');
    $sql = "
    SELECT
        k.fecha,
        LEFT(k.cenco6, 3) AS granja,
        RIGHT(k.cenco6, 3) AS campania,
        k.galpon,
        (k.venta_macho + k.venta_hembra) AS venta,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} THEN mz.tcantid ELSE 0 END), 0) AS mortalidad,
        k.venta_macho AS venta_macho,
        k.venta_hembra AS venta_hembra,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$macho} THEN mz.tcantid ELSE 0 END), 0) AS mort_macho,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$hembra} THEN mz.tcantid ELSE 0 END), 0) AS mort_hembra,
        CASE WHEN k.venta_macho > 0 THEN COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$macho}
            AND (
                UPPER(TRIM(COALESCE(mz.tcategoria, ''))) = 'DESPACHO'
                OR UPPER(TRIM(COALESCE(mz.flujo, ''))) = 'DESPACHO'
                OR LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') IN {$causasDespacho}
            ) THEN mz.tcantid ELSE 0 END), 0) ELSE 0 END AS mort_desp_macho,
        CASE WHEN k.venta_hembra > 0 THEN COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$hembra}
            AND (
                UPPER(TRIM(COALESCE(mz.tcategoria, ''))) = 'DESPACHO'
                OR UPPER(TRIM(COALESCE(mz.flujo, ''))) = 'DESPACHO'
                OR LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') IN {$causasDespacho}
            ) THEN mz.tcantid ELSE 0 END), 0) ELSE 0 END AS mort_desp_hembra
    FROM `{$t}` k
    LEFT JOIN movi_zonas mz ON
        k.fecha = DATE(mz.tfectra)
        AND TRIM(mz.tcencos) = k.tcencos
        AND TRIM(CAST(mz.tcodint AS CHAR)) = k.galpon
        AND TRIM(mz.tcodigo) IN ('P0001001','P0001002')
        AND TRIM(mz.tcodtra) = {$s808}
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
    $cache[$key] = $rows;

    return $rows;
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
    mort_despacho_temp_keys_prepare($conn, $filtros);
    $t = MORT_DESPACHO_TEMP_KEYS;
    $resCount = mysqli_query($conn, "SELECT COUNT(*) AS n FROM `{$t}`");
    $n = 0;
    if ($resCount && ($r = mysqli_fetch_assoc($resCount))) {
        $n = (int) ($r['n'] ?? 0);
    }
    if ($n === 0) {
        return [];
    }

    $sqlDesp = mort_despacho_sql_es_despacho('mz');
    $joinKeys = mort_despacho_sql_join_claves_venta('mz', 'k');
    $where = "
        TRIM(mz.tcodigo) IN ('P0001001','P0001002')
        AND TRIM(mz.tcodtra) = 'S808'
        AND mz.tcantid > 0
        AND {$sqlDesp}
        AND (
            (TRIM(mz.tcodigo) = 'P0001001' AND k.venta_macho > 0)
            OR (TRIM(mz.tcodigo) = 'P0001002' AND k.venta_hembra > 0)
        )";

    $sql = "
    SELECT
        LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') AS cod_mort,
        COALESCE(SUM(mz.tcantid), 0) AS cantidad
    FROM movi_zonas mz
    {$joinKeys}
    WHERE {$where}
    GROUP BY LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0')
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new RuntimeException('Consulta causas despacho: ' . mysqli_error($conn));
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

    mort_despacho_temp_keys_prepare($conn, $filtros);
    $tKeys = MORT_DESPACHO_TEMP_KEYS;
    $where = implode(' AND ', $conds);

    $sql = '
    SELECT ' . implode(', ', $selectSum) . "
    FROM san_fact_mortalidad_det d
    INNER JOIN san_fact_mortalidad_cab c ON c.id = d.cabId
    INNER JOIN `{$tKeys}` k ON
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
 * Resumen stats desde filas Ventas (una sola query mort_ventas_sql_agrupada por request).
 *
 * @return array<string, array{cantidadDespachada: float, muertos: int}>
 */
function mort_despacho_resumen_stats_map(mysqli $conn, array $filtros): array
{
    $filas = mort_despacho_ventas_agrupada_filas($conn, $filtros);
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

/**
 * Resumen por fecha y cenco con saca (venta S700) > 0 — alineado al módulo Ventas.
 *
 * @return list<array<string, mixed>>
 */
function mort_despacho_consultar_resumen_granjas(mysqli $conn, array $filtros): array
{
    $stats = mort_despacho_resumen_stats_map($conn, $filtros);
    if ($stats === []) {
        return [];
    }

    $filas = [];
    foreach ($stats as $key => $st) {
        $cantDesp = (float) ($st['cantidadDespachada'] ?? 0);
        if ($cantDesp <= 0) {
            continue;
        }
        $parts = explode('|', $key, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $fecha = $parts[0];
        $cencos = $parts[1];
        if (strlen($cencos) < 6) {
            continue;
        }
        $granja = substr($cencos, 0, 3);
        $campania = substr($cencos, 3, 3);
        $muertos = (int) ($st['muertos'] ?? 0);
        $pct = round($muertos * 100 / $cantDesp, 2);
        $filas[] = [
            'fecha' => $fecha,
            'cencos' => $cencos,
            'granjaCod' => $granja,
            'campania' => $campania,
            'cantidadDespachada' => $cantDesp,
            'muertos' => $muertos,
            'porcentajeMortDespacho' => $pct,
        ];
    }

    if (count($filas) > 8000) {
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
    $n = 0;
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

    $filasVentas = mort_despacho_ventas_agrupada_filas($conn, $filtros);
    $resumen = mort_despacho_consultar_resumen_granjas($conn, $filtros);
    if ($filasVentas === []) {
        $causas = mort_despacho_agregar_causas([]);
    } else {
        $causas = mort_despacho_agregar_causas(mort_despacho_causas_agregadas_sql($conn, $filtros));
    }

    return [
        'rango' => $rango,
        'causas' => $causas,
        'resumenGranjas' => $resumen,
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

    mort_despacho_temp_keys_prepare($conn, $filtros);
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

function mort_despacho_analisis_completo(mysqli $conn, array $filtros): array
{
    $principal = mort_despacho_analisis_bloque_principal($conn, $filtros);
    $etapasBlock = mort_despacho_analisis_bloque_etapas($conn, $filtros);

    return [
        'rango' => $principal['rango'],
        'causas' => $principal['causas'],
        'etapas' => $etapasBlock['etapas'],
        'resumenGranjas' => $principal['resumenGranjas'],
    ];
}
