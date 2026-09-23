<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';

/**
 * @return array<string, mixed>
 */
function inf_ctb_filtros_defecto(): array
{
    return [
        'periodoTipo' => 'POR_MES',
        'fechaUnica' => '',
        'fechaInicio' => '',
        'fechaFin' => '',
        'mesUnico' => date('Y-m'),
        'mesInicio' => '',
        'mesFin' => '',
        'granja' => '',
        'campania' => '',
        'galpon' => '',
        'sexo' => '',
        'tcategoria' => '',
        'flujo' => '',
        'nomMort' => '',
        'search' => '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function inf_ctb_parse_filtros(array $input): array
{
    $f = inf_ctb_filtros_defecto();
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

    // Normalizar campania: solo digitos, padding a 3, max 3
    $cDigits = preg_replace('/\D/', '', $f['campania']) ?? '';
    $f['campania'] = $cDigits !== '' ? substr(str_pad($cDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    return $f;
}

/**
 * @param array<string, mixed> $filtros
 */
function inf_ctb_where_filtros(mysqli $conn, array $filtros): string
{
    $where = ' WHERE a.tcodigo IN (\'P0001001\',\'P0001002\') ';
    $params = [];
    $types = '';

    $rango = inf_ctb_periodo_a_rango($filtros);
    if ($rango) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $where .= " AND b.tdate BETWEEN '{$desde}' AND '{$hasta}' ";
    }

    $granja = trim((string) ($filtros['granja'] ?? ''));
    if ($granja !== '') {
        $gEsc = mysqli_real_escape_string($conn, $granja);
        $where .= " AND LEFT(a.tcencos, 3) = '{$gEsc}' ";
    }

    $campania = trim((string) ($filtros['campania'] ?? ''));
    if ($campania !== '') {
        $campEsc = mysqli_real_escape_string($conn, $campania);
        $where .= " AND CHAR_LENGTH(TRIM(a.tcencos)) >= 6 AND RIGHT(TRIM(a.tcencos), 3) = '{$campEsc}' ";
    }

    $galpon = trim((string) ($filtros['galpon'] ?? ''));
    if ($galpon !== '') {
        $galEsc = mysqli_real_escape_string($conn, $galpon);
        $where .= " AND TRIM(a.tcodint) = '{$galEsc}' ";
    }

    $sexo = trim((string) ($filtros['sexo'] ?? ''));
    if ($sexo !== '') {
        if ($sexo === 'Macho') {
            $where .= " AND a.ttipo = '015' ";
        } elseif ($sexo === 'Hembra') {
            $where .= " AND a.ttipo = '016' ";
        }
    }

    $flujo = trim((string) ($filtros['flujo'] ?? ''));
    if ($flujo !== '') {
        $flujoEsc = mysqli_real_escape_string($conn, $flujo);
        // La app guarda el label completo (ej. 'Crianza', 'Transporte'...), pero puede
        // haber datos legacy con valores cortos ('P' para Crianza). Buscamos ambos.
        if ($flujo === 'Crianza') {
            // Crianza incluye registros donde flujo es Crianza/P, o donde tcategoria es Produccion/P
            $where .= " AND (a.flujo LIKE '%{$flujoEsc}%' OR a.flujo = 'P' OR a.tcategoria = 'Produccion' OR a.tcategoria = 'P') ";
        } elseif ($flujo === 'Planta Incubacion') {
            $where .= " AND (a.flujo = 'PlantaIncubacion' OR a.flujo LIKE '%{$flujoEsc}%') ";
        } else {
            $where .= " AND a.flujo LIKE '%{$flujoEsc}%' ";
        }
    }

    $tcategoria = trim((string) ($filtros['tcategoria'] ?? ''));
    if ($tcategoria !== '') {
        $catEsc = mysqli_real_escape_string($conn, $tcategoria);
        if ($tcategoria === 'Otros') {
            $where .= " AND (a.tcategoria IS NULL OR TRIM(a.tcategoria) = '' OR (TRIM(a.tcategoria) NOT IN ('PlantaIncubacion','Transporte','Produccion','Despacho') AND TRIM(a.tcategoria) <> 'P')) ";
        } elseif ($tcategoria === 'Produccion') {
            // La app guarda 'Produccion' pero hay datos legacy con 'P'
            $where .= " AND (a.tcategoria = 'Produccion' OR a.tcategoria = 'P') ";
        } else {
            $where .= " AND a.tcategoria = '{$catEsc}' ";
        }
    }

    $nomMort = trim((string) ($filtros['nomMort'] ?? ''));
    if ($nomMort !== '') {
        $nEsc = mysqli_real_escape_string($conn, $nomMort);
        $where .= " AND m.tnom_mort = '{$nEsc}' ";
    }

    $search = trim((string) ($filtros['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (' . inf_ctb_sql_busqueda($conn, $search) . ') ';
    }

    return $where;
}

/**
 * Búsqueda libre informe contable: usuario, fechas, cencos, galpón, sexo, causa, flujo, etc.
 */
function inf_ctb_sql_busqueda(mysqli $conn, string $search): string
{
    require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

    $s = mysqli_real_escape_string($conn, $search);
    $sLower = function_exists('mb_strtolower')
        ? mb_strtolower($search, 'UTF-8')
        : strtolower($search);

    $ors = [
        "c.nombre LIKE '%{$s}%'",
        "a.tcodint LIKE '%{$s}%'",
        "l.descri LIKE '%{$s}%'",
        "m.tnom_mort LIKE '%{$s}%'",
        "a.tcod_mortgrs LIKE '%{$s}%'",
        "u.nombre LIKE '%{$s}%'",
        "b.tuser LIKE '%{$s}%'",
        "a.tcencos LIKE '%{$s}%'",
        "a.tnumfac LIKE '%{$s}%'",
        "a.tcodigo LIKE '%{$s}%'",
        "i.descri LIKE '%{$s}%'",
        "a.tcodtra LIKE '%{$s}%'",
        "a.flujo LIKE '%{$s}%'",
        "a.tcategoria LIKE '%{$s}%'",
        "a.tfectra LIKE '%{$s}%'",
        "b.tdate LIKE '%{$s}%'",
        "b.ttime LIKE '%{$s}%'",
        "CAST(a.idmovi AS CHAR) LIKE '%{$s}%'",
        "CAST(a.tedad AS CHAR) LIKE '%{$s}%'",
    ];

    $fechaYmd = mort_listado_search_fecha_ymd($search);
    if ($fechaYmd !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fechaYmd);
        $ors[] = "b.tdate = '{$fEsc}'";
        $ors[] = "DATE(a.tfectra) = '{$fEsc}'";
    }

    if ($sLower === 'macho' || $sLower === 'm' || strpos($sLower, 'macho') !== false) {
        $ors[] = "a.ttipo = '015'";
    }
    if ($sLower === 'hembra' || $sLower === 'h' || strpos($sLower, 'hembra') !== false) {
        $ors[] = "a.ttipo = '016'";
    }

    // Categoría / flujo labels
    $mapCat = [
        'produccion' => ["a.tcategoria = 'Produccion'", "a.tcategoria = 'P'"],
        'producción' => ["a.tcategoria = 'Produccion'", "a.tcategoria = 'P'"],
        'plantaincubacion' => ["a.tcategoria = 'PlantaIncubacion'", "a.flujo = 'PlantaIncubacion'"],
        'planta incubacion' => ["a.tcategoria = 'PlantaIncubacion'", "a.flujo LIKE '%Planta%'"],
        'transporte' => ["a.tcategoria = 'Transporte'", "a.flujo LIKE '%Transporte%'"],
        'despacho' => ["a.tcategoria = 'Despacho'", "a.flujo LIKE '%Despacho%'"],
        'crianza' => ["a.flujo LIKE '%Crianza%'", "a.flujo = 'P'"],
        'laboratorio' => ["a.flujo LIKE '%Laboratorio%'"],
        'necropsia' => ["a.flujo LIKE '%Necropsia%'"],
        'cuarentena' => ["a.flujo LIKE '%Cuarentena%'"],
    ];
    $norm = preg_replace('/\s+/', ' ', $sLower) ?? $sLower;
    foreach ($mapCat as $needle => $conds) {
        if ($norm === $needle || strpos($norm, $needle) !== false) {
            foreach ($conds as $cnd) {
                $ors[] = $cnd;
            }
        }
    }

    return implode(' OR ', $ors);
}

/**
 * @param array<string, mixed> $opts
 * @return array{desde: string, hasta: string}|null
 */
function inf_ctb_periodo_a_rango(array $opts): ?array
{
    $tipo = trim((string) ($opts['periodoTipo'] ?? ''));
    if ($tipo === '' || $tipo === 'TODOS') {
        return null;
    }

    $fechaUnica = trim((string) ($opts['fechaUnica'] ?? ''));
    $fechaInicio = trim((string) ($opts['fechaInicio'] ?? ''));
    $fechaFin = trim((string) ($opts['fechaFin'] ?? ''));
    $mesUnico = trim((string) ($opts['mesUnico'] ?? ''));
    $mesInicio = trim((string) ($opts['mesInicio'] ?? ''));
    $mesFin = trim((string) ($opts['mesFin'] ?? ''));

    switch ($tipo) {
        case 'POR_FECHA':
            if ($fechaUnica === '') return null;
            return ['desde' => $fechaUnica, 'hasta' => $fechaUnica];

        case 'ENTRE_FECHAS':
            if ($fechaInicio === '' || $fechaFin === '') return null;
            return ['desde' => $fechaInicio, 'hasta' => $fechaFin];

        case 'POR_MES':
            if ($mesUnico === '' || !preg_match('/^\d{4}-\d{2}$/', $mesUnico)) return null;
            return [
                'desde' => $mesUnico . '-01',
                'hasta' => date('Y-m-t', strtotime($mesUnico . '-01'))
            ];

        case 'ENTRE_MESES':
            if ($mesInicio === '' || $mesFin === '' || !preg_match('/^\d{4}-\d{2}$/', $mesInicio) || !preg_match('/^\d{4}-\d{2}$/', $mesFin)) return null;
            return [
                'desde' => $mesInicio . '-01',
                'hasta' => date('Y-m-t', strtotime($mesFin . '-01'))
            ];

        case 'ULTIMA_SEMANA':
            return [
                'desde' => date('Y-m-d', strtotime('-6 days')),
                'hasta' => date('Y-m-d')
            ];

        default:
            return null;
    }
}

/**
 * JOIN correcto cabe_zonas ↔ movi_zonas (clave completa).
 */
function inf_ctb_join_cabe_zonas(): string
{
    return 'LEFT JOIN cabe_zonas AS b
        ON a.mark = b.mark
       AND a.treg = b.treg
       AND a.tdoc = b.tdoc
       AND a.tserie = b.tserie
       AND a.tnumfac = b.tnumfac';
}

/**
 * FROM mínimo para COUNT según filtros activos (evita JOINs innecesarios).
 *
 * @param array<string, mixed> $filtros
 */
function inf_ctb_from_count(array $filtros): string
{
    $needMort = trim((string) ($filtros['nomMort'] ?? '')) !== '';
    $needSearch = trim((string) ($filtros['search'] ?? '')) !== '';

    $from = 'FROM movi_zonas AS a ' . inf_ctb_join_cabe_zonas();

    if ($needMort || $needSearch) {
        $from .= ' LEFT JOIN regmotivo_mortalidadgrs AS m ON a.tcod_mortgrs = m.tcod_mort';
    }
    if ($needSearch) {
        $from .= ' LEFT JOIN ccos AS c ON a.tcencos = c.codigo';
        $from .= ' LEFT JOIN coal AS l ON a.tcodtra = l.codtra';
        $from .= ' LEFT JOIN usuario AS u ON b.tuser = u.codigo';
        $from .= ' LEFT JOIN mitm AS i ON a.tcodigo = i.codigo';
    }

    return $from;
}

/**
 * Retorna la SQL base para el listado.
 */
function inf_ctb_sql_base(): string
{
    return '
        FROM movi_zonas AS a
        ' . inf_ctb_join_cabe_zonas() . '
        LEFT JOIN ccos AS c ON a.tcencos = c.codigo
        LEFT JOIN coal AS l ON a.tcodtra = l.codtra
        LEFT JOIN regmotivo_mortalidadgrs AS m ON a.tcod_mortgrs = m.tcod_mort
        LEFT JOIN usuario AS u ON b.tuser = u.codigo
    ';
}

/**
 * Obtiene las opciones de causas de mortalidad (nom_mort).
 *
 * @return list<array{nom_mort: string}>
 */
function inf_ctb_opciones_causas(mysqli $conn): array
{
    $rows = [];
    $q = @$conn->query("SELECT DISTINCT tnom_mort FROM regmotivo_mortalidadgrs WHERE TRIM(tnom_mort) <> '' ORDER BY tnom_mort ASC");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $rows[] = ['nom_mort' => trim((string) ($r['tnom_mort'] ?? ''))];
        }
    }
    return $rows;
}
