<?php

declare(strict_types=1);

/**
 * Librería de la sección "Ventas" de Mortalidad.
 *
 * Muestra una fila por DÍA de venta (fecha + granja + campaña + galpón):
 *   - Venta      = suma de los movimientos S700 (tcantid) del día
 *                  SOLO de pollos (tcodigo P0001001 = Macho / P0001002 = Hembra).
 *   - Mortalidad = TODA la mortalidad S808 de pollos del día
 *                  (todos los tcategoria/flujo: P antiguos, Despacho,
 *                  Produccion/Crianza, Transporte, PlantaIncubacion, etc.).
 *
 * Cada total se entrega además desglosado por sexo:
 *   venta_macho, venta_hembra, mort_macho, mort_hembra.
 *
 * Adicionalmente se entrega la mortalidad que corresponde a DESPACHO por sexo
 * (mort_desp_macho / mort_desp_hembra), usando el mismo criterio de
 * "etapa = despacho": el movimiento trae tcategoria/flujo 'DESPACHO' o su
 * código de causa (tcod_mortgrs) es una causa de despacho
 * (05, 14, 15, 17, 18, 19).
 *
 * REGLA DE NEGOCIO: si el sexo NO tuvo venta en el día no puede haber
 * mortalidad "de despacho" (no hubo salida); esa mortalidad corresponde a
 * crianza (o transporte/incubación según su causa) y NO se entrega en
 * mort_desp_macho/mort_desp_hembra (se fuerza a 0). Mismo criterio que
 * get_detalle_venta.php, que en el detalle la muestra como etapa "crianza".
 *
 * También se entrega un indicador por sexo (tiene_s808_macho / tiene_s808_hembra)
 * de si EXISTIÓ al menos un movimiento S808 ese día, para distinguir en la
 * tabla "sin mortalidad registrada" (no hay S808) de "mortalidad 0"
 * (hay S808 pero la suma de cantidades es 0).
 *
 * Fuente: movi_zonas (tcencos = granja+campaña 6 dígitos, tcodint = galpón,
 * tfectra = fecha del movimiento, tcodtra = S700/S808, tcantid = cantidad).
 */

if (!function_exists('mort_ventas_filtros_defecto')) {
    /**
     * @return array<string, mixed>
     */
    function mort_ventas_filtros_defecto(): array
    {
        return [
            'periodoTipo' => 'ULTIMA_SEMANA',
            'fechaUnica' => '',
            'fechaInicio' => '',
            'fechaFin' => '',
            'mesUnico' => '',
            'mesInicio' => date('Y-01'),
            'mesFin' => date('Y-12'),
            'granja' => '',
            'campania' => '',
            'galpon' => '',
            'search' => '',
        ];
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mort_ventas_parse_filtros(array $input): array
{
    $f = mort_ventas_filtros_defecto();
    foreach (array_keys($f) as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $val = $input[$k];
        if (is_array($val)) {
            if ($k === 'search' && isset($val['value'])) {
                $f[$k] = trim((string) $val['value']);
            }
            continue;
        }
        $f[$k] = trim((string) $val);
    }
    $gDigits = preg_replace('/\D/', '', $f['granja']) ?? '';
    $f['granja'] = $gDigits !== '' ? substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    return $f;
}

/**
 * Normaliza texto de búsqueda DataTables / filtros.
 *
 * @param array<string, mixed> $input
 */
function mort_ventas_extract_search_input(array $input): string
{
    if (isset($input['search']) && is_array($input['search']) && array_key_exists('value', $input['search'])) {
        return trim((string) $input['search']['value']);
    }
    if (isset($input['search']) && is_string($input['search'])) {
        return trim($input['search']);
    }

    return '';
}

/**
 * Convierte fecha d/m/Y o d-m-Y a Y-m-d si aplica.
 */
function mort_ventas_search_fecha_ymd(string $search): string
{
    $search = trim($search);
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $search, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $search)) {
        return substr($search, 0, 10);
    }

    return '';
}

/**
 * @param array<string, mixed> $filtros
 * @return array{desde: string, hasta: string}|null
 */
function mort_ventas_rango(array $filtros): ?array
{
    require_once __DIR__ . '/../../../core/lib/filtro_periodo_util.php';

    return periodo_a_rango([
        'periodoTipo' => (string) ($filtros['periodoTipo'] ?? 'TODOS'),
        'fechaUnica' => (string) ($filtros['fechaUnica'] ?? ''),
        'fechaInicio' => (string) ($filtros['fechaInicio'] ?? ''),
        'fechaFin' => (string) ($filtros['fechaFin'] ?? ''),
        'mesUnico' => (string) ($filtros['mesUnico'] ?? ''),
        'mesInicio' => (string) ($filtros['mesInicio'] ?? ''),
        'mesFin' => (string) ($filtros['mesFin'] ?? ''),
    ]);
}

/**
 * Condición SQL OR para búsqueda libre de la tabla (columnas visibles).
 *
 * @param array<string, mixed> $filtros
 */
function mort_ventas_sql_busqueda(mysqli $conn, array $filtros): string
{
    $search = trim((string) ($filtros['search'] ?? ''));
    if ($search === '') {
        return '';
    }
    $s = mysqli_real_escape_string($conn, $search);

    $ors = [
        "v.fecha LIKE '%{$s}%'",
        "v.granja LIKE '%{$s}%'",
        "v.campania LIKE '%{$s}%'",
        "v.galpon LIKE '%{$s}%'",
        "CONCAT(TRIM(v.granja), TRIM(v.campania)) LIKE '%{$s}%'",
        "cc.nombre LIKE '%{$s}%'",
    ];

    $fechaYmd = mort_ventas_search_fecha_ymd($search);
    if ($fechaYmd !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fechaYmd);
        $ors[] = "v.fecha = '{$fEsc}'";
    }

    return '(' . implode(' OR ', $ors) . ')';
}

/**
 * Condiciones WHERE que se aplican DENTRO de movi_zonas (antes de agrupar),
 * para reducir el volumen procesado.
 *
 * @param array<string, mixed> $filtros
 */
function mort_ventas_where_internos(mysqli $conn, array $filtros): string
{
    $conds = [];
    // Venta = movimientos S700 de pollos (P0001001/P0001002).
    // Mortalidad = TODOS los S808 de pollos del día (todo tcategoria/flujo:
    // 'P' antiguos, 'Despacho' modernos, 'Produccion', 'Transporte', etc.).
    $conds[] = "(TRIM(mz.tcodigo) IN ('P0001001','P0001002')
                 AND TRIM(mz.tcodtra) IN ('S700','S808'))";

    $rango = mort_ventas_rango($filtros);
    if ($rango !== null) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $conds[] = "DATE(mz.tfectra) BETWEEN '{$desde}' AND '{$hasta}'";
    }

    $granja = trim((string) ($filtros['granja'] ?? ''));
    if ($granja !== '') {
        $gEsc = mysqli_real_escape_string($conn, $granja);
        $conds[] = "LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}'";
    }

    $campania = trim((string) ($filtros['campania'] ?? ''));
    if ($campania !== '') {
        $cEsc = mysqli_real_escape_string($conn, $campania);
        $conds[] = "RIGHT(TRIM(mz.tcencos), 3) = '{$cEsc}'";
    }

    $galpon = trim((string) ($filtros['galpon'] ?? ''));
    if ($galpon !== '') {
        $galEsc = mysqli_real_escape_string($conn, $galpon);
        $conds[] = "TRIM(CAST(mz.tcodint AS CHAR)) = '{$galEsc}'";
    }

    // Búsqueda libre (DataTables): si el texto es una FECHA exacta se empuja al
    // WHERE interno con mz.tfectra = '...' para que use el índice fci
    // (tfectra, tcencos, tcodint) y NO agrupe todo el período antes de filtrar.
    // Es una equivalencia exacta (v.fecha = DATE(tfectra)), por lo que no altera
    // el resultado frente al LIKE externo.
    $search = trim((string) ($filtros['search'] ?? ''));
    $fechaYmd = mort_ventas_search_fecha_ymd($search);
    if ($fechaYmd !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fechaYmd);
        $conds[] = "mz.tfectra = '{$fEsc}'";
    }

    return ' WHERE ' . implode(' AND ', $conds);
}

/**
 * Subconsulta agrupada: una fila por día de venta (fecha+granja+campaña+galpón)
 * con Venta = suma S700 y Mortalidad = TODOS los S808 del día, cada una
 * desglosada en Macho (P0001001) y Hembra (P0001002).
 *
 * REGLA DE NEGOCIO: si un sexo NO tuvo venta en el día, no puede haber
 * mortalidad "de despacho" (no hubo salida); esa mortalidad corresponde a
 * crianza (o transporte/incubación según su causa) y NO debe contarse en
 * mort_desp_macho/mort_desp_hembra. Por eso la mortalidad de despacho bruta se
 * calcula en el query interno y el SELECT externo la pone en 0 si la venta del
 * sexo fue 0 (mismo criterio que get_detalle_venta.php, que además la muestra
 * como etapa "crianza" en el detalle).
 *
 * @param array<string, mixed> $filtros
 */
function mort_ventas_sql_agrupada(mysqli $conn, array $filtros): string
{
    $macho = "'P0001001'";
    $hembra = "'P0001002'";
    $s700 = "'S700'";
    $s808 = "'S808'";

    // Códigos de causa que clasifican la mortalidad como DESPACHO (mismo
    // criterio que mort_ventas_etapa_de_fila en get_detalle_venta.php).
    $causasDespacho = "('05','14','15','17','18','19')";

    $inner = "
    SELECT
        DATE(mz.tfectra) AS fecha,
        LEFT(TRIM(mz.tcencos), 3) AS granja,
        RIGHT(TRIM(mz.tcencos), 3) AS campania,
        TRIM(CAST(mz.tcodint AS CHAR)) AS galpon,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s700} THEN mz.tcantid ELSE 0 END), 0) AS venta,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} THEN mz.tcantid ELSE 0 END), 0) AS mortalidad,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s700} AND TRIM(mz.tcodigo) = {$macho} THEN mz.tcantid ELSE 0 END), 0) AS venta_macho,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s700} AND TRIM(mz.tcodigo) = {$hembra} THEN mz.tcantid ELSE 0 END), 0) AS venta_hembra,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$macho} THEN mz.tcantid ELSE 0 END), 0) AS mort_macho,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$hembra} THEN mz.tcantid ELSE 0 END), 0) AS mort_hembra,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$macho}
            AND (
                UPPER(TRIM(COALESCE(mz.tcategoria, ''))) = 'DESPACHO'
                OR UPPER(TRIM(COALESCE(mz.flujo, ''))) = 'DESPACHO'
                OR LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') IN {$causasDespacho}
            )
        THEN mz.tcantid ELSE 0 END), 0) AS mort_desp_macho,
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$hembra}
            AND (
                UPPER(TRIM(COALESCE(mz.tcategoria, ''))) = 'DESPACHO'
                OR UPPER(TRIM(COALESCE(mz.flujo, ''))) = 'DESPACHO'
                OR LPAD(TRIM(COALESCE(mz.tcod_mortgrs, '')), 2, '0') IN {$causasDespacho}
            )
        THEN mz.tcantid ELSE 0 END), 0) AS mort_desp_hembra,
        MAX(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$macho} THEN 1 ELSE 0 END) AS tiene_s808_macho,
        MAX(CASE WHEN TRIM(mz.tcodtra) = {$s808} AND TRIM(mz.tcodigo) = {$hembra} THEN 1 ELSE 0 END) AS tiene_s808_hembra
    FROM movi_zonas mz"
    . mort_ventas_where_internos($conn, $filtros)
    . "
    GROUP BY
        DATE(mz.tfectra),
        LEFT(TRIM(mz.tcencos), 3),
        RIGHT(TRIM(mz.tcencos), 3),
        TRIM(CAST(mz.tcodint AS CHAR))
    HAVING
        COALESCE(SUM(CASE WHEN TRIM(mz.tcodtra) = {$s700} THEN mz.tcantid ELSE 0 END), 0) > 0";

    // Regla de negocio (despacho solo si hubo venta del sexo ese día).
    return "
    SELECT
        x.fecha,
        x.granja,
        x.campania,
        x.galpon,
        x.venta,
        x.mortalidad,
        x.venta_macho,
        x.venta_hembra,
        x.mort_macho,
        x.mort_hembra,
        CASE WHEN x.venta_macho > 0 THEN x.mort_desp_macho ELSE 0 END AS mort_desp_macho,
        CASE WHEN x.venta_hembra > 0 THEN x.mort_desp_hembra ELSE 0 END AS mort_desp_hembra,
        x.tiene_s808_macho,
        x.tiene_s808_hembra
    FROM ({$inner}) x";
}

/**
 * FROM con alias v (+ ccos para el nombre de granja), útil para búsqueda y datos.
 */
function mort_ventas_from_v(mysqli $conn, array $filtros): string
{
    return '(' . mort_ventas_sql_agrupada($conn, $filtros) . ") v
        LEFT JOIN ccos cc ON cc.codigo = CONCAT(v.granja, '000')";
}

/**
 * WHERE externo sobre v (+ cc) cuando hay búsqueda global.
 *
 * @param array<string, mixed> $filtros
 */
function mort_ventas_where_externo(mysqli $conn, array $filtros): string
{
    $busqueda = mort_ventas_sql_busqueda($conn, $filtros);
    if ($busqueda === '') {
        return '';
    }

    return ' WHERE ' . $busqueda;
}
