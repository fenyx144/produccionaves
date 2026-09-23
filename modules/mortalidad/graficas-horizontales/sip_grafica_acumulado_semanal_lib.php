<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_data_lib.php';
require_once __DIR__ . '/sip_grafica_pesaje_lib.php';
require_once __DIR__ . '/sip_grafica_acum_lote_live_lib.php';

if (!function_exists('sip_grafica_acum_granjas_nombres')) {
    /** Abreviaturas de granjas (código 3 dígitos => nombre corto). */
    function sip_grafica_acum_granjas_nombres(): array
    {
        return [
            '627' => 'SG', '621' => 'ST', '644' => 'REN', '642' => 'SL', '667' => 'TUC',
            '664' => 'VENT', '663' => 'T ESP', '661' => 'PON I', '637' => 'JM II',
            '636' => 'JM I', '635' => 'GDA V', '634' => 'GDA IV', '666' => 'T NOS',
            '665' => 'TNUE', '662' => 'PON II', '628' => 'GL I', '623' => 'GL II',
            '625' => 'GL III', '643' => 'VCH', '632' => 'GDA III', '630' => 'GDA I',
            '631' => 'GDA II',
        ];
    }
}

if (!function_exists('sip_grafica_acum_nombre_granja')) {
    function sip_grafica_acum_nombre_granja(string $granja3): string
    {
        $nombres = sip_grafica_acum_granjas_nombres();

        return $nombres[$granja3] ?? $granja3;
    }
}

if (!function_exists('sip_grafica_acum_cfg_tipo')) {
    /**
     * Config del endpoint de acumulado semanal por tipo de gráfica.
     *
     * @return array{tipo:string,label:string,tareaKey:string,parametroKey:string,modoEvaluacion:string,unidad:string,esSemanal:bool,transform:string}|null
     */
    function sip_grafica_acum_cfg_tipo(string $tipo): ?array
    {
        $t = strtolower(trim($tipo));

        if ($t === 'mortalidad') {
            return [
                'tipo' => $t,
                'label' => 'Mortalidad',
                'tareaKey' => 'CRIANZA_MORTALIDAD_DIARIA',
                'parametroKey' => 'MORTALIDAD_DIA',
                'modoEvaluacion' => 'solo_max',
                'unidad' => '%',
                'esSemanal' => false,
                'transform' => 'mortalidad',
            ];
        }

        if (sip_grafica_es_consumo($t)) {
            $cfg = sip_grafica_consumo_cfg($t);
            if ($cfg === null) {
                return null;
            }

            return [
                'tipo' => $t,
                'label' => (string) ($cfg['label'] ?? $t),
                'tareaKey' => (string) ($cfg['tareaKey'] ?? ''),
                'parametroKey' => (string) ($cfg['parametroKey'] ?? ''),
                'modoEvaluacion' => (string) ($cfg['modoEvaluacion'] ?? 'solo_max'),
                'unidad' => (string) ($cfg['unidadDefault'] ?? ''),
                'esSemanal' => false,
                'transform' => (string) ($cfg['transform'] ?? ''),
            ];
        }

        if (sip_grafica_es_peso_cloro($t)) {
            $cfg = sip_grafica_peso_cloro_cfg($t);
            if ($cfg === null) {
                return null;
            }

            return [
                'tipo' => $t,
                'label' => (string) ($cfg['label'] ?? $t),
                'tareaKey' => (string) ($cfg['tareaKey'] ?? ''),
                'parametroKey' => (string) ($cfg['parametroKey'] ?? ''),
                'modoEvaluacion' => (string) ($cfg['modoEvaluacion'] ?? 'rango_cerrado'),
                'unidad' => (string) ($cfg['unidadDefault'] ?? ''),
                'esSemanal' => (bool) ($cfg['esSemanal'] ?? false),
                'transform' => $t === 'nivel_cloro' ? 'cloro' : 'peso',
            ];
        }

        return null;
    }
}

if (!function_exists('sip_grafica_acum_galpon_norm')) {
    /** Normaliza galpón para comparar combinaciones (1 y 01 → 1). */
    function sip_grafica_acum_galpon_norm($galpon): string
    {
        $g = trim((string) $galpon);
        if ($g === '') {
            return '';
        }
        if (ctype_digit($g)) {
            return (string) (int) $g;
        }

        return $g;
    }
}

if (!function_exists('sip_grafica_acum_combo_key')) {
    function sip_grafica_acum_combo_key(string $granja3, string $campania, $galpon): string
    {
        return $granja3 . '|' . $campania . '|' . sip_grafica_acum_galpon_norm($galpon);
    }
}

if (!function_exists('sip_grafica_acum_fecha_corta')) {
    /** Formatea Y-m-d a "d mmm" (ej: 20 nov). */
    function sip_grafica_acum_fecha_corta(?string $fechaYmd): string
    {
        if ($fechaYmd === null || $fechaYmd === '' || $fechaYmd === '0000-00-00') {
            return '';
        }
        $ts = strtotime($fechaYmd);
        if ($ts === false) {
            return '';
        }
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

        return date('j', $ts) . ' ' . $meses[(int) date('n', $ts) - 1];
    }
}

if (!function_exists('sip_grafica_acum_fecha_semana')) {
    /**
     * Fecha real (Y-m-d) de la semana solicitada para un lote:
     * día 1 + (semana * 7 - 1) días (fin de la semana).
     */
    function sip_grafica_acum_fecha_semana(?string $fechaInicio, int $semana): ?string
    {
        if ($fechaInicio === null || $fechaInicio === '' || $fechaInicio === '0000-00-00') {
            return null;
        }
        $ts = strtotime($fechaInicio);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts + (($semana * 7) - 1) * 86400);
    }
}

if (!function_exists('sip_grafica_acum_fecha_carga_map')) {
    /**
     * Fecha de carga de pollo (día 1) por combo desde cargapollo_proyeccion.
     * SOLO para el orden cronológico de los registros.
     *
     * @return array<string, string> key => Y-m-d
     */
    function sip_grafica_acum_fecha_carga_map(mysqli $conn): array
    {
        if (!sip_grafica_table_exists($conn, 'cargapollo_proyeccion')) {
            return [];
        }
        $cols = ['fecha', 'tcencos', 'tcodint', 'edad'];
        foreach ($cols as $col) {
            if (!sip_grafica_acum_col_exists($conn, 'cargapollo_proyeccion', $col)) {
                return [];
            }
        }

        $sql = "SELECT
                    LEFT(TRIM(`tcencos`), 3) AS granja3,
                    RIGHT(TRIM(`tcencos`), 3) AS campania,
                    TRIM(`tcodint`) AS galpon,
                    MIN(DATE(DATE_ADD(`fecha`, INTERVAL -(`edad`) + 1 DAY))) AS fechaCarga
                FROM `cargapollo_proyeccion`
                WHERE TRIM(`tcodint`) <> ''
                  AND TRIM(`tcodint`) <> '0'
                  AND CAST(`edad` AS SIGNED) = 1
                  AND `fecha` IS NOT NULL
                  AND LEFT(TRIM(`tcencos`), 3) NOT IN ('624','640','641')
                GROUP BY LEFT(TRIM(`tcencos`), 3), RIGHT(TRIM(`tcencos`), 3), TRIM(`tcodint`)";

        $rs = $conn->query($sql);
        if (!$rs) {
            return [];
        }
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = sip_grafica_acum_galpon_norm($r['galpon'] ?? '');
            $f = trim((string) ($r['fechaCarga'] ?? ''));
            if ($g === '' || $c === '' || $gp === '' || $f === '' || $f === '0000-00-00') {
                continue;
            }
            $out[sip_grafica_acum_combo_key($g, $c, $gp)] = $f;
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_acum_col_exists')) {
    function sip_grafica_acum_col_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = spl_object_hash($conn) . '|' . $table . '|' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safeT = $conn->real_escape_string($table);
        $safeC = $conn->real_escape_string($column);
        $rs = @$conn->query("SHOW COLUMNS FROM `{$safeT}` LIKE '{$safeC}'");
        $cache[$key] = (bool) ($rs && $rs->num_rows > 0);

        return $cache[$key];
    }
}

if (!function_exists('sip_grafica_acum_cab_sql_overlap')) {
    /**
     * Predicado SQL de solape de lote con el periodo [desde, hasta].
     * Placeholders en orden: hasta, desde (fecha_inicio <= hasta AND fin abierto OR fecha_fin >= desde).
     */
    function sip_grafica_acum_cab_sql_overlap(string $alias = 'c'): string
    {
        return "{$alias}.`fecha_inicio` IS NOT NULL AND TRIM({$alias}.`fecha_inicio`) <> ''
            AND {$alias}.`fecha_inicio` <> '0000-00-00'
            AND DATE({$alias}.`fecha_inicio`) <= ?
            AND ({$alias}.`fecha_fin` IS NULL OR TRIM({$alias}.`fecha_fin`) = ''
                 OR {$alias}.`fecha_fin` = '0000-00-00' OR DATE({$alias}.`fecha_fin`) >= ?)";
    }
}

if (!function_exists('sip_grafica_acum_combos_periodo')) {
    /**
     * Combos granja|campaña|galpón que tienen datos reales de la semana solicitada
     * dentro del periodo [desde, hasta].
     *
     * La fecha real de la semana se calcula con la fecha de inicio real del lote
     * (san_fact_historia_clinica_cab.fecha_inicio) + (semana*7 - 1) días.
     * cargapollo_proyeccion se usa SOLO para el orden de salida.
     *
     * @return list<array{key:string,granja:string,campania:string,galpon:string,fechaInicio:string,fechaCarga:string}>
     */
    function sip_grafica_acum_combos_periodo(
        mysqli $conn,
        string $desde,
        string $hasta,
        int $semana,
        bool $esSemanal
    ): array {
        if (!sip_grafica_table_exists($conn, 'san_fact_historia_clinica_cab')
            || !sip_grafica_table_exists($conn, 'san_fact_historia_clinica_det')) {
            return [];
        }

        $dias = ($semana * 7) - 1;
        $minEd = (($semana - 1) * 7) + 1;
        $maxEd = $semana * 7;

        $condEdad = $esSemanal
            ? 'CAST(d.`edadDia` AS SIGNED) = ?'
            : 'CAST(d.`edadDia` AS SIGNED) BETWEEN ? AND ?';

        $sql = "SELECT
                    LPAD(LEFT(TRIM(c.`granja`),3),3,'0') AS granja3,
                    TRIM(c.`campania`) AS campania,
                    TRIM(CAST(c.`galpon` AS CHAR)) AS galpon,
                    MIN(DATE(c.`fecha_inicio`)) AS fechaInicio
                FROM `san_fact_historia_clinica_det` d
                INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                WHERE CAST(d.`edadDia` AS SIGNED) > 0
                  AND c.`fecha_inicio` IS NOT NULL AND TRIM(c.`fecha_inicio`) <> ''
                  AND c.`fecha_inicio` <> '0000-00-00'
                  AND c.`granja` IS NOT NULL AND TRIM(c.`granja`) <> ''
                  AND TRIM(c.`campania`) <> '' AND TRIM(c.`campania`) <> '000'
                  AND LPAD(LEFT(TRIM(c.`granja`),3),3,'0') NOT IN ('624','640','641')
                  AND {$condEdad}
                  AND DATE_ADD(DATE(c.`fecha_inicio`), INTERVAL ? DAY) BETWEEN ? AND ?
                GROUP BY granja3, campania, galpon";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        if ($esSemanal) {
            $st->bind_param('iiss', $semana, $dias, $desde, $hasta);
        } else {
            $st->bind_param('iiiss', $minEd, $maxEd, $dias, $desde, $hasta);
        }
        $st->execute();
        $rs = $st->get_result();

        $fechaCargaMap = sip_grafica_acum_fecha_carga_map($conn);

        $byKey = [];
        $rows = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = trim((string) ($r['galpon'] ?? ''));
            $fi = trim((string) ($r['fechaInicio'] ?? ''));
            if ($g === '' || $c === '' || $gp === '' || $fi === '' || $fi === '0000-00-00') {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = true;
            $fProj = $fechaCargaMap[$key] ?? '';
            $rows[] = [
                'key' => $key,
                'granja' => $g,
                'campania' => $c,
                'galpon' => sip_grafica_acum_galpon_norm($gp),
                'fechaInicio' => $fi,
                'fechaCarga' => $fProj !== '' ? $fProj : $fi,
            ];
        }
        $st->close();

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['fechaCarga'], $b['fechaCarga']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['granja'], $b['granja']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['campania'], $b['campania']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $na = is_numeric($a['galpon']) ? (float) $a['galpon'] : null;
            $nb = is_numeric($b['galpon']) ? (float) $b['galpon'] : null;
            if ($na !== null && $nb !== null) {
                return $na <=> $nb;
            }

            return strnatcasecmp($a['galpon'], $b['galpon']);
        });

        return $rows;
    }
}

if (!function_exists('sip_grafica_acum_ingest_rows')) {
    /**
     * Agrupa filas det por combo y por edad (la última fila por edad gana, como la gráfica HC).
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string, array<int, array<string,mixed>>>
     */
    function sip_grafica_acum_ingest_rows(array $rows): array
    {
        $byCombo = [];
        foreach ($rows as $r) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = sip_grafica_acum_galpon_norm($r['galpon'] ?? '');
            if ($g === '' || $c === '' || $gp === '') {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            $ed = (int) ($r['edadDia'] ?? 0);
            if ($ed <= 0) {
                continue;
            }
            if (!isset($byCombo[$key])) {
                $byCombo[$key] = [];
            }
            $byCombo[$key][$ed] = $r;
        }
        foreach ($byCombo as $key => &$edades) {
            ksort($edades, SORT_NUMERIC);
        }
        unset($edades);

        return $byCombo;
    }
}

if (!function_exists('sip_grafica_acum_stock_por_combo')) {
    /**
     * Stock CANTIDAD_POLLOS (día 1) por combo en el periodo.
     *
     * @return array<string, array{m:?float,h:?float}>
     */
    function sip_grafica_acum_stock_por_combo(mysqli $conn, string $desde, string $hasta): array
    {
        if (!sip_grafica_table_exists($conn, 'san_fact_historia_clinica_det')) {
            return [];
        }

        $sql = "SELECT
                    LPAD(LEFT(TRIM(c.`granja`),3),3,'0') AS granja3,
                    TRIM(c.`campania`) AS campania,
                    TRIM(CAST(c.`galpon` AS CHAR)) AS galpon,
                    d.`edadDia`, d.`valor` AS stockVal
                FROM `san_fact_historia_clinica_det` d
                INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                WHERE d.`tareaKey` = 'CANTIDAD_POLLOS'
                  AND LPAD(LEFT(TRIM(c.`granja`),3),3,'0') NOT IN ('624','640','641')
                  AND " . sip_grafica_acum_cab_sql_overlap('c') . "
                ORDER BY c.`granja` ASC, c.`campania` ASC, c.`galpon` ASC, d.`edadDia` ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('ss', $hasta, $desde);
        $st->execute();
        $rs = $st->get_result();

        $minPorCombo = [];
        $out = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = sip_grafica_acum_galpon_norm($r['galpon'] ?? '');
            if ($g === '' || $c === '' || $gp === '') {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            $ed = (int) ($r['edadDia'] ?? 0);
            if ($ed <= 0) {
                continue;
            }
            if (isset($minPorCombo[$key]) && $minPorCombo[$key] <= $ed) {
                continue;
            }
            $minPorCombo[$key] = $ed;

            $parsed = sip_grafica_parse_valor_mh($r['stockVal'] ?? null, 0);
            if ($parsed['esMh']) {
                $mVal = $parsed['m'];
                $hVal = $parsed['h'];
            } elseif ($parsed['unified'] !== null) {
                $mVal = $parsed['unified'];
                $hVal = $parsed['unified'];
            } else {
                $mVal = $parsed['m'];
                $hVal = $parsed['h'];
            }
            $out[$key] = ['m' => $mVal, 'h' => $hVal];
        }
        $st->close();

        return $out;
    }
}

if (!function_exists('sip_grafica_acum_det_rows')) {
    /**
     * Filas det de la tareaKey en el periodo (todas las combinaciones de una sola consulta).
     *
     * @return list<array<string,mixed>>
     */
    function sip_grafica_acum_det_rows(mysqli $conn, string $tareaKey, string $desde, string $hasta): array
    {
        if (!sip_grafica_table_exists($conn, 'san_fact_historia_clinica_det')) {
            return [];
        }

        $sql = "SELECT
                    LPAD(LEFT(TRIM(c.`granja`),3),3,'0') AS granja3,
                    TRIM(c.`campania`) AS campania,
                    TRIM(CAST(c.`galpon` AS CHAR)) AS galpon,
                    d.`edadDia`, d.`parametroKey`, d.`valor`, d.`estandarJson`
                FROM `san_fact_historia_clinica_det` d
                INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                WHERE d.`tareaKey` = ?
                  AND LPAD(LEFT(TRIM(c.`granja`),3),3,'0') NOT IN ('624','640','641')
                  AND " . sip_grafica_acum_cab_sql_overlap('c') . "
                ORDER BY c.`granja` ASC, c.`campania` ASC, c.`galpon` ASC, d.`edadDia` ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('sss', $tareaKey, $hasta, $desde);
        $st->execute();
        $rs = $st->get_result();

        $rows = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $rows[] = $r;
        }
        $st->close();

        return $rows;
    }
}

if (!function_exists('sip_grafica_acum_dias_por_combo')) {
    /**
     * Valores diarios (M/H) ya transformados por edad, replicando la gráfica HC
     * (mortalidad %, consumo por ave, cloro promedio de lecturas, peso/ganancia directo).
     *
     * @param array<int, array<string,mixed>> $edades
     * @param array{m:?float,h:?float} $stock
     * @return array<int, array{m:?float,h:?float}>
     */
    function sip_grafica_acum_dias_por_combo(array $edades, string $transform, array $stock, bool $esSemanal): array
    {
        $stkM = sip_grafica_to_num($stock['m'] ?? null);
        $stkH = sip_grafica_to_num($stock['h'] ?? null);

        $dias = [];
        foreach ($edades as $ed => $r) {
            if ($transform === 'cloro') {
                $lineas = sip_grafica_cloro_lineas_desde_valor($r['valor'] ?? null);
                $lecturas = sip_grafica_cloro_parse_lecturas($lineas, null, null, 'rango_cerrado');
                $vals = [];
                foreach ($lecturas as $l) {
                    if ($l['valor'] !== null && is_finite($l['valor'])) {
                        $vals[] = $l['valor'];
                    }
                }
                $m = null;
                $h = null;
                if ($vals !== []) {
                    $m = array_sum($vals) / count($vals);
                    $h = $m;
                }
                $dias[$ed] = ['m' => $m, 'h' => $h];
                continue;
            }

            $parsed = sip_grafica_parse_valor_mh($r['valor'] ?? null, 0);
            if ($parsed['esMh']) {
                $m = $parsed['m'];
                $h = $parsed['h'];
            } elseif ($parsed['unified'] !== null) {
                $m = $parsed['unified'];
                $h = $parsed['unified'];
            } else {
                $m = $parsed['m'];
                $h = $parsed['h'];
            }

            if ($transform === 'mortalidad') {
                if ($m !== null && $stkM !== null && $stkM > 0) {
                    $m = ($m / $stkM) * 100.0;
                }
                if ($h !== null && $stkH !== null && $stkH > 0) {
                    $h = ($h / $stkH) * 100.0;
                }
            } elseif ($transform === 'alimento' || $transform === 'gas_agua') {
                $m = $m !== null ? sip_grafica_consumo_transform_valor($m, $stkM, $stkH, $transform) : null;
                $h = $h !== null ? sip_grafica_consumo_transform_valor($h, $stkH, $stkM, $transform) : null;
            }
            /* peso/ganancia: valor directo (ya es semanal). */

            $dias[$ed] = ['m' => $m, 'h' => $h];
        }

        return $dias;
    }
}

if (!function_exists('sip_grafica_acum_std_ref_desde_rango')) {
    /** Valor de referencia del estándar según modo de evaluación (como HC acumulado). */
    function sip_grafica_acum_std_ref_desde_rango(?float $sMin, ?float $sMax, string $modoEvaluacion): ?float
    {
        $modo = strtolower(trim($modoEvaluacion));
        if ($modo === 'solo_min' || $modo === 'valor_unico') {
            return $sMin ?? $sMax;
        }

        return $sMax ?? $sMin;
    }
}

if (!function_exists('sip_grafica_acum_std_valor_resuelto')) {
    /**
     * Estándar diario/semanal por edad, replicando resolución de las gráficas HC.
     *
     * @param array<string,mixed> $r
     */
    function sip_grafica_acum_std_valor_resuelto(
        array $r,
        array $cfg,
        array $stdMap,
        int $edadLookup,
        string $parametroKey
    ): ?float {
        $transform = (string) ($cfg['transform'] ?? '');
        $modoEval = (string) ($cfg['modoEvaluacion'] ?? 'solo_max');
        $tk = (string) ($cfg['tareaKey'] ?? '');
        $pk = trim($parametroKey);
        if ($pk === '') {
            $pk = trim((string) ($cfg['parametroKey'] ?? ''));
        }

        if ($transform === 'mortalidad') {
            if ($pk === '') {
                $pk = 'MORTALIDAD_DIA';
            }
            $sm = null;
            $ej = trim((string) ($r['estandarJson'] ?? ''));
            if ($ej !== '') {
                $est = @json_decode($ej, true);
                if (is_array($est)) {
                    if (isset($est['stdMax']) && is_numeric($est['stdMax'])) {
                        $sm = (float) $est['stdMax'];
                    } elseif (isset($est['stdMin']) && is_numeric($est['stdMin'])) {
                        $sm = (float) $est['stdMin'];
                    } elseif (isset($est['valor']) && is_numeric($est['valor'])) {
                        $sm = (float) $est['valor'];
                    }
                } elseif (is_numeric($ej)) {
                    $sm = (float) $ej;
                }
            }
            if ($sm === null && $stdMap !== []) {
                [, $sMax] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pk, $edadLookup);
                $sm = $sMax;
                if ($sm !== null && $sm > 1.0) {
                    $sm /= 100.0;
                }
            }

            return $sm;
        }

        if ($transform === 'alimento' || $transform === 'gas_agua') {
            [$sMin, $sMax] = sip_grafica_consumo_std_desde_row($r, $modoEval);
            if ($sMin === null && $sMax === null && $stdMap !== []) {
                [$sMin, $sMax] = sip_grafica_consumo_std_desde_map($stdMap, $tk, $pk, $edadLookup);
            }

            return sip_grafica_acum_std_ref_desde_rango($sMin, $sMax, $modoEval);
        }

        if ($transform === 'cloro' || $transform === 'peso') {
            [$sMin, $sMax] = sip_grafica_peso_std_desde_row($r, $modoEval);
            if ($sMin === null && $sMax === null && $stdMap !== []) {
                [$sMin, $sMax] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pk, $edadLookup);
            }

            return sip_grafica_acum_std_ref_desde_rango($sMin, $sMax, $modoEval);
        }

        return null;
    }
}

if (!function_exists('sip_grafica_acum_std_dias_por_combo')) {
    /**
     * Estándar por edad (M/H comparten el mismo valor de referencia).
     *
     * @param array<int, array<string,mixed>> $edades
     * @return array<int, array{m:?float,h:?float}>
     */
    function sip_grafica_acum_std_dias_por_combo(
        array $edades,
        array $cfg,
        array $stdMap,
        bool $esSemanal
    ): array {
        $transform = (string) ($cfg['transform'] ?? '');
        $pkDefault = trim((string) ($cfg['parametroKey'] ?? ''));

        $dias = [];
        foreach ($edades as $ed => $r) {
            $pk = trim((string) ($r['parametroKey'] ?? ''));
            if ($pk === '') {
                $pk = $pkDefault;
            }
            $edLookup = ($esSemanal && $transform === 'peso')
                ? sip_grafica_semana_a_edad_std($pk, $ed)
                : $ed;
            $std = sip_grafica_acum_std_valor_resuelto($r, $cfg, $stdMap, $edLookup, $pk);
            if ($std === null && $pk !== '') {
                $std = sip_grafica_acum_std_valor_resuelto(
                    ['parametroKey' => $pk, 'estandarJson' => ''],
                    $cfg,
                    $stdMap,
                    $edLookup,
                    $pk
                );
            }
            if ($std !== null) {
                $dias[$ed] = ['m' => $std, 'h' => $std];
            }
        }

        return $dias;
    }
}

if (!function_exists('sip_grafica_acum_por_semana')) {
    /**
     * Acumulado por semana replicando la HC:
     * - Métricas diarias: semana k = días (k-1)*7+1..k*7; el acumulado suma días desde el día 1.
     * - Peso/Ganancia: la edad ya es semana; el acumulado suma semanas 1..k.
     *
     * @param array<int, array{m:?float,h:?float}> $dias
     * @return array{m:?float,h:?float}
     */
    function sip_grafica_acum_por_semana(array $dias, int $semanaMax, bool $esSemanal): array
    {
        $acumM = 0.0;
        $acumH = 0.0;
        $haveM = false;
        $haveH = false;

        for ($k = 1; $k <= $semanaMax; $k++) {
            if ($esSemanal) {
                $vM = isset($dias[$k]) ? $dias[$k]['m'] : null;
                $vH = isset($dias[$k]) ? $dias[$k]['h'] : null;
            } else {
                $vM = null;
                $vH = null;
                $desde = (($k - 1) * 7) + 1;
                $hasta = $k * 7;
                foreach ($dias as $ed => $v) {
                    if ($ed < $desde || $ed > $hasta) {
                        continue;
                    }
                    if ($v['m'] !== null) {
                        $vM = ($vM ?? 0.0) + $v['m'];
                    }
                    if ($v['h'] !== null) {
                        $vH = ($vH ?? 0.0) + $v['h'];
                    }
                }
            }

            if ($vM !== null) {
                $acumM += $vM;
                $haveM = true;
            }
            if ($vH !== null) {
                $acumH += $vH;
                $haveH = true;
            }
        }

        return [
            'm' => $haveM ? round($acumM, 4) : null,
            'h' => $haveH ? round($acumH, 4) : null,
        ];
    }
}

if (!function_exists('sip_grafica_acum_peso_promedio_from_pairs')) {
    /**
     * Promedio ponderado por tcantid (misma regla que el ETL de pesaje).
     *
     * @param list<array{0:float,1:float}> $pairs [tpeso, tcantid]
     */
    function sip_grafica_acum_peso_promedio_from_pairs(array $pairs): ?float
    {
        $n = 0.0;
        $sum = 0.0;
        foreach ($pairs as $p) {
            $n += $p[1];
            $sum += $p[0] * $p[1];
        }
        if ($n <= 0.0) {
            return null;
        }
        $mean = $sum / $n;

        return is_finite($mean) ? round($mean, 4) : null;
    }
}

if (!function_exists('sip_grafica_acum_cv_from_pairs')) {
    /**
     * CV% poblacional ponderado por tcantid (mismas filas que el ETL de pesaje).
     *
     * @param list<array{0:float,1:float}> $pairs [tpeso, tcantid]
     */
    function sip_grafica_acum_cv_from_pairs(array $pairs): ?float
    {
        $n = 0.0;
        $sum = 0.0;
        foreach ($pairs as $p) {
            $n += $p[1];
            $sum += $p[0] * $p[1];
        }
        if ($n < 2.0 || $sum == 0.0) {
            return null;
        }
        $mean = $sum / $n;
        $ss = 0.0;
        foreach ($pairs as $p) {
            $d = $p[0] - $mean;
            $ss += $p[1] * $d * $d;
        }
        $sigma = sqrt($ss / $n);
        if ($mean == 0.0 || !is_finite($sigma)) {
            return null;
        }

        return round(($sigma / $mean) * 100.0, 1);
    }
}

if (!function_exists('sip_grafica_acum_pesaje_combos_periodo')) {
    /**
     * Combos con pesaje registrado en muestreoaves para una tedad concreta.
     *
     * @return list<array{key:string,granja:string,campania:string,galpon:string,fechaInicio:string,fechaCarga:string}>
     */
    function sip_grafica_acum_pesaje_combos_periodo(
        mysqli $conn,
        string $desde,
        string $hasta,
        int $edad
    ): array {
        if ($edad < 0 || !sip_grafica_acum_muestreo_tablas_disponibles($conn)) {
            return [];
        }

        $fechaPesaje = sip_grafica_acum_muestreo_fecha_pesaje_sql('a', 'c', 'mz');
        $overlap = sip_grafica_acum_lote_sql_overlap('c', 'mz');
        $fechaInicio = sip_grafica_acum_lote_fecha_inicio_sql('c', 'mz');

        $sql = "SELECT
                    LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') AS granja3,
                    TRIM(RIGHT(TRIM(a.`tcencos`),3)) AS campania,
                    TRIM(a.`tcodint`) AS galpon,
                    MIN({$fechaInicio}) AS fechaInicio
                FROM `muestreoaves` a
                " . sip_grafica_acum_muestreo_lote_join('a', 'c', 'mz') . "
                WHERE CAST(a.`tedad` AS SIGNED) = ?
                  AND LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') NOT IN ('624','640','641')
                  AND " . sip_grafica_acum_lote_tiene_fecha_sql('c', 'mz') . "
                  AND {$overlap}
                  AND {$fechaPesaje} BETWEEN ? AND ?
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tcantid` IS NOT NULL
                  AND a.`tcantid` > 0
                GROUP BY LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0'), TRIM(RIGHT(TRIM(a.`tcencos`),3)), TRIM(a.`tcodint`)";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('issss', $edad, $hasta, $desde, $desde, $hasta);
        $st->execute();
        $rs = $st->get_result();

        $fechaCargaMap = sip_grafica_acum_fecha_carga_map($conn);
        $byKey = [];
        $rows = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = trim((string) ($r['galpon'] ?? ''));
            $fi = trim((string) ($r['fechaInicio'] ?? ''));
            if ($g === '' || $c === '' || $gp === '' || $fi === '' || $fi === '0000-00-00') {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = true;
            $fProj = $fechaCargaMap[$key] ?? '';
            $rows[] = [
                'key' => $key,
                'granja' => $g,
                'campania' => $c,
                'galpon' => sip_grafica_acum_galpon_norm($gp),
                'fechaInicio' => $fi,
                'fechaCarga' => $fProj !== '' ? $fProj : $fi,
            ];
        }
        $st->close();

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['fechaCarga'], $b['fechaCarga']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['granja'], $b['granja']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['campania'], $b['campania']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $na = is_numeric($a['galpon']) ? (float) $a['galpon'] : null;
            $nb = is_numeric($b['galpon']) ? (float) $b['galpon'] : null;
            if ($na !== null && $nb !== null) {
                return $na <=> $nb;
            }

            return strnatcasecmp($a['galpon'], $b['galpon']);
        });

        return $rows;
    }
}

if (!function_exists('sip_grafica_acum_muestreo_agg_por_combo')) {
    /**
     * Peso promedio M/H y CV% por combo desde muestreoaves (tedad exacta, todas las filas).
     *
     * @return array<string, array{m:?float,h:?float,cvM:?float,cvH:?float,edad:int}>
     */
    function sip_grafica_acum_muestreo_agg_por_combo(
        mysqli $conn,
        int $edad,
        string $desde,
        string $hasta
    ): array {
        if ($edad < 0 || !sip_grafica_acum_muestreo_tablas_disponibles($conn)) {
            return [];
        }

        $fechaPesaje = sip_grafica_acum_muestreo_fecha_pesaje_sql('a', 'c', 'mz');
        $overlap = sip_grafica_acum_lote_sql_overlap('c', 'mz');

        $sql = "SELECT
                    LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') AS granja3,
                    TRIM(RIGHT(TRIM(a.`tcencos`),3)) AS campania,
                    TRIM(a.`tcodint`) AS galpon,
                    TRIM(a.`tsexo`) AS tsexo,
                    a.`tpeso`,
                    a.`tcantid`
                FROM `muestreoaves` a
                " . sip_grafica_acum_muestreo_lote_join('a', 'c', 'mz') . "
                WHERE CAST(a.`tedad` AS SIGNED) = ?
                  AND LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') NOT IN ('624','640','641')
                  AND " . sip_grafica_acum_lote_tiene_fecha_sql('c', 'mz') . "
                  AND {$overlap}
                  AND {$fechaPesaje} BETWEEN ? AND ?
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tcantid` IS NOT NULL
                  AND a.`tcantid` > 0";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('issss', $edad, $hasta, $desde, $desde, $hasta);
        $st->execute();
        $rs = $st->get_result();

        /** @var array<string, array{M:list<array{0:float,1:float}>,H:list<array{0:float,1:float}>}> $by */
        $by = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = sip_grafica_acum_galpon_norm($r['galpon'] ?? '');
            if ($g === '' || $c === '' || $gp === '') {
                continue;
            }
            $sexo = strtoupper(trim((string) ($r['tsexo'] ?? '')));
            if ($sexo !== 'M' && $sexo !== 'H') {
                continue;
            }
            $peso = (float) ($r['tpeso'] ?? 0);
            $cant = (float) ($r['tcantid'] ?? 0);
            if ($cant <= 0 || !is_finite($peso)) {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (!isset($by[$key])) {
                $by[$key] = ['M' => [], 'H' => []];
            }
            $by[$key][$sexo][] = [$peso, $cant];
        }
        $st->close();

        $out = [];
        foreach ($by as $key => $sexos) {
            $out[$key] = [
                'm' => sip_grafica_acum_peso_promedio_from_pairs($sexos['M']),
                'h' => sip_grafica_acum_peso_promedio_from_pairs($sexos['H']),
                'cvM' => sip_grafica_acum_cv_from_pairs($sexos['M']),
                'cvH' => sip_grafica_acum_cv_from_pairs($sexos['H']),
                'edad' => $edad,
            ];
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_acum_pesaje_std')) {
    /** Estándar de peso para una edad de muestreo (no acumulado). */
    function sip_grafica_acum_pesaje_std(int $edad, array $cfg, array $stdMap): array
    {
        $tk = (string) ($cfg['tareaKey'] ?? '');
        $pk = (string) ($cfg['parametroKey'] ?? '');
        $modoEval = (string) ($cfg['modoEvaluacion'] ?? 'solo_min');
        [$sMin, $sMax] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pk, $edad);
        $std = sip_grafica_acum_std_ref_desde_rango($sMin, $sMax, $modoEval);

        return ['m' => $std, 'h' => $std];
    }
}

if (!function_exists('sip_grafica_acum_pesaje_dia1_movi')) {
    /**
     * Peso inicial (día 1) desde movi_zonas (E001) por combo para un periodo.
     * Solo se usa cuando el front pide dia=1; el peso sale de movi_zonas, no de muestreoaves.
     *
     * @return array{combos:list<array<string,mixed>>,agg:array<string,array{m:?float,h:?float}>}
     */
    function sip_grafica_acum_pesaje_dia1_movi(mysqli $conn, string $desde, string $hasta): array
    {
        $out = ['combos' => [], 'agg' => []];
        if (!sip_grafica_table_exists($conn, 'movi_zonas')) {
            return $out;
        }

        $mzSub = sip_grafica_acum_movi_zonas_fechas_subquery();
        $fechaInicio = sip_grafica_acum_lote_fecha_inicio_sql('mz');
        $overlap = sip_grafica_acum_lote_sql_overlap('mz');

        $sql = "SELECT
                    LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') AS granja3,
                    TRIM(RIGHT(TRIM(a.`tcencos`),3)) AS campania,
                    TRIM(a.`tcodint`) AS galpon,
                    TRIM(a.`tcodigo`) AS tcodigo,
                    a.`tpeso`,
                    {$fechaInicio} AS fechaInicio
                FROM `movi_zonas` a
                INNER JOIN {$mzSub} mz
                    ON TRIM(a.`tcencos`) = mz.`tcencos`
                   AND TRIM(a.`tcodint`) = mz.`galpon`
                WHERE a.`tcodtra` = 'E001'
                  AND a.`tcodigo` IN ('P0001001', 'P0001002')
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tpeso` > 0
                  AND LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') NOT IN ('624','640','641')
                  AND {$overlap}
                  AND {$fechaInicio} BETWEEN ? AND ?
                ORDER BY a.`tcencos` ASC, a.`tcodint` ASC, a.`tfectra` ASC, a.`tnumfac` ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return $out;
        }
        $st->bind_param('ssss', $hasta, $desde, $desde, $hasta);
        $st->execute();
        $rs = $st->get_result();

        $fechaCargaMap = sip_grafica_acum_fecha_carga_map($conn);
        $byKey = [];
        $rows = [];
        $agg = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = trim((string) ($r['galpon'] ?? ''));
            $fi = trim((string) ($r['fechaInicio'] ?? ''));
            $cod = strtoupper(trim((string) ($r['tcodigo'] ?? '')));
            $peso = (float) ($r['tpeso'] ?? 0);
            if ($g === '' || $c === '' || $gp === '' || $fi === '' || $fi === '0000-00-00'
                || $peso <= 0 || !is_finite($peso)) {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (!isset($agg[$key])) {
                $agg[$key] = ['m' => null, 'h' => null];
            }
            if ($cod === 'P0001001' && $agg[$key]['m'] === null) {
                $agg[$key]['m'] = round($peso, 4);
            } elseif ($cod === 'P0001002' && $agg[$key]['h'] === null) {
                $agg[$key]['h'] = round($peso, 4);
            }
            if (isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = true;
            $fProj = $fechaCargaMap[$key] ?? '';
            $rows[] = [
                'key' => $key,
                'granja' => $g,
                'campania' => $c,
                'galpon' => sip_grafica_acum_galpon_norm($gp),
                'fechaInicio' => $fi,
                'fechaCarga' => $fProj !== '' ? $fProj : $fi,
            ];
        }
        $st->close();

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['fechaCarga'], $b['fechaCarga']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['granja'], $b['granja']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['campania'], $b['campania']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $na = is_numeric($a['galpon']) ? (float) $a['galpon'] : null;
            $nb = is_numeric($b['galpon']) ? (float) $b['galpon'] : null;
            if ($na !== null && $nb !== null) {
                return $na <=> $nb;
            }

            return strnatcasecmp($a['galpon'], $b['galpon']);
        });

        $out['combos'] = $rows;
        $out['agg'] = $agg;

        return $out;
    }
}

if (!function_exists('sip_grafica_acum_fecha_por_edad')) {
    function sip_grafica_acum_fecha_por_edad(?string $fechaInicio, int $edad): ?string
    {
        return sip_grafica_pesaje_fecha_por_tedad($fechaInicio, $edad);
    }
}

if (!function_exists('sip_grafica_acum_pesaje_build')) {
    /**
     * Pesaje de pollo: peso y CV desde muestreoaves por tedad registrada (no san_fact, no acumulado).
     *
     * @param array<string,mixed> $opts tipo, fechaInicio, fechaFin, dia, tedad
     * @return array<string,mixed>
     */
    function sip_grafica_acum_pesaje_build(mysqli $conn, array $opts): array
    {
        $fechaInicio = trim((string) ($opts['fechaInicio'] ?? ''));
        $fechaFin = trim((string) ($opts['fechaFin'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
            return ['success' => false, 'message' => 'Faltan o son invalidos fechaInicio/fechaFin (YYYY-MM-DD)'];
        }
        if ($fechaInicio > $fechaFin) {
            [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
        }

        if (!sip_grafica_acum_muestreo_tablas_disponibles($conn)) {
            return ['success' => false, 'message' => 'Tabla muestreoaves no disponible'];
        }

        $cfg = sip_grafica_acum_cfg_tipo('pesaje_pollo');
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado: pesaje_pollo'];
        }

        $dia = (int) ($opts['dia'] ?? 1);
        $tedad = (int) ($opts['tedad'] ?? sip_grafica_pesaje_dia_api_a_tedad($dia));
        $diasPermitidos = sip_grafica_pesaje_dias_permitidos_tipo('pesaje_pollo');
        if (!sip_grafica_pesaje_dia_valido_tipo('pesaje_pollo', $dia)) {
            return [
                'success' => false,
                'message' => 'Parametro dia invalido. Valores permitidos: ' . implode(', ', $diasPermitidos),
            ];
        }

        $combos = [];
        $aggPorCombo = [];
        $tedadPesaje = $tedad;
        if ($dia === 1) {
            $movi = sip_grafica_acum_pesaje_dia1_movi($conn, $fechaInicio, $fechaFin);
            $combos = $movi['combos'];
            $aggPorCombo = $movi['agg'];
        } else {
            $combos = sip_grafica_acum_pesaje_combos_periodo($conn, $fechaInicio, $fechaFin, $tedad);
            $aggPorCombo = sip_grafica_acum_muestreo_agg_por_combo($conn, $tedad, $fechaInicio, $fechaFin);
        }
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);
        $std = sip_grafica_acum_pesaje_std($tedadPesaje, $cfg, $stdMap);
        $semCat = sip_grafica_pesaje_dia_semana_catalogo($dia);

        $registros = [];
        foreach ($combos as $combo) {
            $key = $combo['key'];
            $row = $aggPorCombo[$key] ?? ['m' => null, 'h' => null];
            $fechaCarga = $combo['fechaCarga'] !== '' ? $combo['fechaCarga'] : null;
            $fechaPesaje = sip_grafica_acum_fecha_por_edad($combo['fechaInicio'], $tedadPesaje);
            $registros[] = [
                'granja' => $combo['granja'],
                'granjaNombre' => sip_grafica_acum_nombre_granja($combo['granja']),
                'campania' => $combo['campania'],
                'galpon' => $combo['galpon'],
                'fechaCarga' => $fechaCarga,
                'fechaSemana' => $fechaPesaje,
                'valorM' => $row['m'],
                'valorH' => $row['h'],
                'estandarM' => $std['m'],
                'estandarH' => $std['h'],
                'dia' => $dia,
                'semana' => $semCat,
            ];
        }

        return [
            'success' => true,
            'tipo' => 'pesaje_pollo',
            'nombre' => (string) $cfg['label'],
            'dia' => $dia,
            'semana' => $semCat,
            'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
            'unidad' => (string) $cfg['unidad'],
            'totalRegistros' => count($registros),
            'registros' => $registros,
        ];
    }
}

if (!function_exists('sip_grafica_acumulado_semanal_build')) {
    /**
     * Acumulado semanal por granja/galpón para un tipo de gráfica y un periodo.
     *
     * @param array<string,mixed> $opts tipo, fechaInicio, fechaFin, semana
     * @return array<string,mixed>
     */
    function sip_grafica_acumulado_semanal_build(mysqli $conn, array $opts): array
    {
        $tipo = strtolower(trim((string) ($opts['tipo'] ?? 'mortalidad')));
        $semana = (int) ($opts['semana'] ?? 0);

        if (in_array($tipo, ['pesaje_pollo', 'ganancia_peso', 'cv_peso', 'cv_ganancia'], true)) {
            require_once __DIR__ . '/sip_grafica_acumulado_semanal_fact_lib.php';

            return sip_grafica_acum_fact_pesaje_ganancia_build($conn, $opts);
        }

        $diasMulti = (array) ($opts['dias'] ?? []);
        $diasMulti = array_values(array_filter($diasMulti, static function ($d): bool {
            return is_numeric($d) && (int) $d > 0;
        }));
        if ($diasMulti !== []) {
            if (in_array($tipo, ['pesaje_pollo', 'cv_peso', 'cv_ganancia'], true)) {
                return sip_grafica_acum_peso_cv_multidia_build($conn, $opts);
            }
            if ($tipo === 'ganancia_peso') {
                require_once __DIR__ . '/sip_grafica_ganancia_lib.php';

                return sip_grafica_acum_ganancia_multidia_build($conn, $opts);
            }

            return ['success' => false, 'message' => 'Tipo no soportado con varios dias: ' . $tipo];
        }

        if ($tipo === 'pesaje_pollo') {
            return sip_grafica_acum_pesaje_build($conn, $opts);
        }

        if ($tipo === 'ganancia_peso') {
            require_once __DIR__ . '/sip_grafica_ganancia_lib.php';

            return sip_grafica_acum_ganancia_build($conn, $opts);
        }

        if ($tipo === 'cv_peso') {
            return sip_grafica_acum_cv_build($conn, $opts, 'cv_peso');
        }

        if ($tipo === 'cv_ganancia') {
            return sip_grafica_acum_cv_ganancia_build($conn, $opts);
        }

        $cfg = sip_grafica_acum_cfg_tipo($tipo);
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado: ' . $tipo];
        }
        if ($semana < 1 || $semana > 10) {
            return ['success' => false, 'message' => 'Parametro semana invalido (1-10): ' . $semana];
        }

        $fechaInicio = trim((string) ($opts['fechaInicio'] ?? ''));
        $fechaFin = trim((string) ($opts['fechaFin'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
            return ['success' => false, 'message' => 'Faltan o son invalidos fechaInicio/fechaFin (YYYY-MM-DD)'];
        }
        if ($fechaInicio > $fechaFin) {
            [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
        }

        if (!sip_grafica_table_exists($conn, 'san_fact_historia_clinica_cab')) {
            return ['success' => false, 'message' => 'Tabla san_fact_historia_clinica_cab no disponible'];
        }

        $tk = (string) $cfg['tareaKey'];
        $esSemanal = (bool) $cfg['esSemanal'];
        $transform = (string) $cfg['transform'];

        $combos = sip_grafica_acum_combos_periodo($conn, $fechaInicio, $fechaFin, $semana, $esSemanal);
        $base = [
            'success' => true,
            'tipo' => $tipo,
            'nombre' => (string) $cfg['label'],
            'semana' => $semana,
            'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
            'unidad' => (string) $cfg['unidad'],
            'totalRegistros' => 0,
            'registros' => [],
        ];

        if ($combos === []) {
            return $base;
        }

        $stockPorCombo = sip_grafica_acum_stock_por_combo($conn, $fechaInicio, $fechaFin);
        $detRows = sip_grafica_acum_det_rows($conn, $tk, $fechaInicio, $fechaFin);
        $edadesPorCombo = sip_grafica_acum_ingest_rows($detRows);
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);

        $registros = [];
        foreach ($combos as $combo) {
            $key = $combo['key'];
            $edades = $edadesPorCombo[$key] ?? [];
            $stock = $stockPorCombo[$key] ?? ['m' => null, 'h' => null];

            $dias = sip_grafica_acum_dias_por_combo($edades, $transform, $stock, $esSemanal);
            $acum = sip_grafica_acum_por_semana($dias, $semana, $esSemanal);
            $stdDias = sip_grafica_acum_std_dias_por_combo($edades, $cfg, $stdMap, $esSemanal);
            $acumStd = sip_grafica_acum_por_semana($stdDias, $semana, $esSemanal);

            $fechaCarga = $combo['fechaCarga'] !== '' ? $combo['fechaCarga'] : null;
            $fechaSemana = sip_grafica_acum_fecha_semana($combo['fechaInicio'], $semana);
            $granjaNombre = sip_grafica_acum_nombre_granja($combo['granja']);
            $registros[] = [
                'granja' => $combo['granja'],
                'granjaNombre' => $granjaNombre,
                'campania' => $combo['campania'],
                'galpon' => $combo['galpon'],
                'fechaCarga' => $fechaCarga,
                'fechaSemana' => $fechaSemana,
                'valorM' => $acum['m'],
                'valorH' => $acum['h'],
                'estandarM' => $acumStd['m'],
                'estandarH' => $acumStd['h'],
            ];
        }

        $base['totalRegistros'] = count($registros);
        $base['registros'] = $registros;

        return $base;
    }
}

if (!function_exists('sip_grafica_acum_combo_rows_sort')) {
    /**
     * Normaliza filas crudas de combos y las ordena por fecha de carga.
     *
     * @param list<array<string,mixed>> $rsRows
     * @param array<string, string> $fechaCargaMap
     * @return list<array{key:string,granja:string,campania:string,galpon:string,fechaInicio:string,fechaCarga:string}>
     */
    function sip_grafica_acum_combo_rows_sort(array $rsRows, array $fechaCargaMap): array
    {
        $byKey = [];
        $rows = [];
        foreach ($rsRows as $r) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = sip_grafica_acum_galpon_norm($r['galpon'] ?? '');
            $fi = trim((string) ($r['fechaInicio'] ?? ''));
            if ($g === '' || $c === '' || $gp === '' || $fi === '' || $fi === '0000-00-00') {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = true;
            $fProj = $fechaCargaMap[$key] ?? '';
            $rows[] = [
                'key' => $key,
                'granja' => $g,
                'campania' => $c,
                'galpon' => $gp,
                'fechaInicio' => $fi,
                'fechaCarga' => $fProj !== '' ? $fProj : $fi,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['fechaCarga'], $b['fechaCarga']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['granja'], $b['granja']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['campania'], $b['campania']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $na = is_numeric($a['galpon']) ? (float) $a['galpon'] : null;
            $nb = is_numeric($b['galpon']) ? (float) $b['galpon'] : null;
            if ($na !== null && $nb !== null) {
                return $na <=> $nb;
            }

            return strnatcasecmp($a['galpon'], $b['galpon']);
        });

        return $rows;
    }
}

if (!function_exists('sip_grafica_acum_pesaje_combos_multiedad_periodo')) {
    /**
     * Combos con pesaje registrado en muestreoaves para un conjunto de edades (una sola pasada).
     *
     * @param list<int> $tedads
     * @return list<array{key:string,granja:string,campania:string,galpon:string,fechaInicio:string,fechaCarga:string}>
     */
    function sip_grafica_acum_pesaje_combos_multiedad_periodo(
        mysqli $conn,
        string $desde,
        string $hasta,
        array $tedads
    ): array {
        $tedads = array_values(array_unique(array_filter(array_map('intval', $tedads), static function (int $e): bool {
            return $e >= 2;
        })));
        if ($tedads === [] || !sip_grafica_acum_muestreo_tablas_disponibles($conn)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($tedads), '?'));
        $fechaPesaje = sip_grafica_acum_muestreo_fecha_pesaje_sql('a', 'c', 'mz');
        $overlap = sip_grafica_acum_lote_sql_overlap('c', 'mz');
        $fechaInicio = sip_grafica_acum_lote_fecha_inicio_sql('c', 'mz');

        $sql = "SELECT
                    LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') AS granja3,
                    TRIM(RIGHT(TRIM(a.`tcencos`),3)) AS campania,
                    TRIM(a.`tcodint`) AS galpon,
                    MIN({$fechaInicio}) AS fechaInicio
                FROM `muestreoaves` a
                " . sip_grafica_acum_muestreo_lote_join('a', 'c', 'mz') . "
                WHERE CAST(a.`tedad` AS SIGNED) IN ({$in})
                  AND LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') NOT IN ('624','640','641')
                  AND " . sip_grafica_acum_lote_tiene_fecha_sql('c', 'mz') . "
                  AND {$overlap}
                  AND {$fechaPesaje} BETWEEN ? AND ?
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tcantid` IS NOT NULL
                  AND a.`tcantid` > 0
                GROUP BY LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0'), TRIM(RIGHT(TRIM(a.`tcencos`),3)), TRIM(a.`tcodint`)";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $types = str_repeat('i', count($tedads)) . 'ssss';
        $params = array_merge($tedads, [$hasta, $desde, $desde, $hasta]);
        $st->bind_param($types, ...$params);
        $st->execute();
        $rs = $st->get_result();

        $rsRows = [];
        while ($rs && ($r = $rs->fetch_assoc()) !== null) {
            $rsRows[] = $r;
        }
        $st->close();

        $fechaCargaMap = sip_grafica_acum_fecha_carga_map($conn);

        return sip_grafica_acum_combo_rows_sort($rsRows, $fechaCargaMap);
    }
}

if (!function_exists('sip_grafica_acum_muestreo_agg_multiedad_por_combo')) {
    /**
     * Peso promedio M/H y CV% por combo y edad desde muestreoaves (varias edades en una pasada).
     *
     * @param list<int> $tedads
     * @return array<string, array<int, array{m:?float,h:?float,cvM:?float,cvH:?float}>>
     */
    function sip_grafica_acum_muestreo_agg_multiedad_por_combo(
        mysqli $conn,
        string $desde,
        string $hasta,
        array $tedads
    ): array {
        $tedads = array_values(array_unique(array_filter(array_map('intval', $tedads), static function (int $e): bool {
            return $e >= 2;
        })));
        if ($tedads === [] || !sip_grafica_acum_muestreo_tablas_disponibles($conn)) {
            return [];
        }

        $in = implode(',', array_fill(0, count($tedads), '?'));
        $fechaPesaje = sip_grafica_acum_muestreo_fecha_pesaje_sql('a', 'c', 'mz');
        $overlap = sip_grafica_acum_lote_sql_overlap('c', 'mz');

        $sql = "SELECT
                    LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') AS granja3,
                    TRIM(RIGHT(TRIM(a.`tcencos`),3)) AS campania,
                    TRIM(a.`tcodint`) AS galpon,
                    CAST(a.`tedad` AS SIGNED) AS tedad,
                    TRIM(a.`tsexo`) AS tsexo,
                    a.`tpeso`,
                    a.`tcantid`
                FROM `muestreoaves` a
                " . sip_grafica_acum_muestreo_lote_join('a', 'c', 'mz') . "
                WHERE CAST(a.`tedad` AS SIGNED) IN ({$in})
                  AND LPAD(LEFT(TRIM(a.`tcencos`),3),3,'0') NOT IN ('624','640','641')
                  AND " . sip_grafica_acum_lote_tiene_fecha_sql('c', 'mz') . "
                  AND {$overlap}
                  AND {$fechaPesaje} BETWEEN ? AND ?
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tcantid` IS NOT NULL
                  AND a.`tcantid` > 0";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $types = str_repeat('i', count($tedads)) . 'ssss';
        $params = array_merge($tedads, [$hasta, $desde, $desde, $hasta]);
        $st->bind_param($types, ...$params);
        $st->execute();
        $rs = $st->get_result();

        /** @var array<string, array<int, array{M:list<array{0:float,1:float}>,H:list<array{0:float,1:float}>}>> $by */
        $by = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = sip_grafica_acum_galpon_norm($r['galpon'] ?? '');
            $tedad = (int) ($r['tedad'] ?? 0);
            if ($g === '' || $c === '' || $gp === '' || $tedad <= 1) {
                continue;
            }
            $sexo = strtoupper(trim((string) ($r['tsexo'] ?? '')));
            if ($sexo !== 'M' && $sexo !== 'H') {
                continue;
            }
            $peso = (float) ($r['tpeso'] ?? 0);
            $cant = (float) ($r['tcantid'] ?? 0);
            if ($cant <= 0 || !is_finite($peso)) {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (!isset($by[$key])) {
                $by[$key] = [];
            }
            if (!isset($by[$key][$tedad])) {
                $by[$key][$tedad] = ['M' => [], 'H' => []];
            }
            $by[$key][$tedad][$sexo][] = [$peso, $cant];
        }
        $st->close();

        $out = [];
        foreach ($by as $key => $edades) {
            foreach ($edades as $tedad => $sexos) {
                $out[$key][$tedad] = [
                    'm' => sip_grafica_acum_peso_promedio_from_pairs($sexos['M']),
                    'h' => sip_grafica_acum_peso_promedio_from_pairs($sexos['H']),
                    'cvM' => sip_grafica_acum_cv_from_pairs($sexos['M']),
                    'cvH' => sip_grafica_acum_cv_from_pairs($sexos['H']),
                ];
            }
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_acum_peso_cv_multidia_build')) {
    /**
     * Pesaje/CV horizontal para varios días con una sola pasada de muestreoaves.
     * La respuesta trae items[] con el mismo formato por día de los builds individuales.
     *
     * @param array<string,mixed> $opts tipo, fechaInicio, fechaFin, dias
     * @return array<string,mixed>
     */
    function sip_grafica_acum_peso_cv_multidia_build(mysqli $conn, array $opts): array
    {
        $tipo = strtolower(trim((string) ($opts['tipo'] ?? '')));
        if (!in_array($tipo, ['pesaje_pollo', 'cv_peso', 'cv_ganancia'], true)) {
            return ['success' => false, 'message' => 'Tipo no soportado en multi-dia: ' . $tipo];
        }

        $fechaInicio = trim((string) ($opts['fechaInicio'] ?? ''));
        $fechaFin = trim((string) ($opts['fechaFin'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
            return ['success' => false, 'message' => 'Faltan o son invalidos fechaInicio/fechaFin (YYYY-MM-DD)'];
        }
        if ($fechaInicio > $fechaFin) {
            [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
        }

        if (!sip_grafica_acum_muestreo_tablas_disponibles($conn)) {
            return ['success' => false, 'message' => 'Tablas de pesaje no disponibles'];
        }

        $dias = [];
        foreach ((array) ($opts['dias'] ?? []) as $d) {
            $di = (int) $d;
            if ($di > 0 && sip_grafica_pesaje_dia_valido_tipo($tipo, $di)) {
                $dias[$di] = $di;
            }
        }
        $dias = array_values($dias);
        if ($dias === []) {
            return [
                'success' => false,
                'message' => 'Parametro dias invalido. Valores permitidos: '
                    . implode(', ', sip_grafica_pesaje_dias_permitidos_tipo($tipo)),
            ];
        }
        sort($dias, SORT_NUMERIC);

        $esPesaje = $tipo === 'pesaje_pollo';
        $cfg = $esPesaje ? sip_grafica_acum_cfg_tipo('pesaje_pollo') : sip_grafica_cv_cfg($tipo);
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado: ' . $tipo];
        }

        $diasMuestreo = array_values(array_filter($dias, static function (int $d): bool {
            return $d > 1;
        }));

        $combos = [];
        $agg = [];
        if ($diasMuestreo !== []) {
            $combos = sip_grafica_acum_pesaje_combos_multiedad_periodo($conn, $fechaInicio, $fechaFin, $diasMuestreo);
            $agg = sip_grafica_acum_muestreo_agg_multiedad_por_combo($conn, $fechaInicio, $fechaFin, $diasMuestreo);
        }

        $moviDia1 = ['combos' => [], 'agg' => []];
        if ($esPesaje && in_array(1, $dias, true)) {
            $moviDia1 = sip_grafica_acum_pesaje_dia1_movi($conn, $fechaInicio, $fechaFin);
        }

        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);
        $cfgPeso = sip_grafica_acum_cfg_tipo('pesaje_pollo');

        $items = [];
        foreach ($dias as $dia) {
            $semCat = sip_grafica_pesaje_dia_semana_catalogo($dia);
            $combosDia = [];
            if ($dia === 1) {
                $combosDia = $moviDia1['combos'];
            } else {
                foreach ($combos as $combo) {
                    if (isset($agg[$combo['key']][$dia])) {
                        $combosDia[] = $combo;
                    }
                }
            }

            $std = ['m' => null, 'h' => null];
            if ($esPesaje && $cfgPeso !== null) {
                $std = sip_grafica_acum_pesaje_std($dia, $cfgPeso, $stdMap);
            }

            $registros = [];
            foreach ($combosDia as $combo) {
                $key = $combo['key'];
                if ($dia === 1) {
                    $row = $moviDia1['agg'][$key] ?? ['m' => null, 'h' => null];
                    $valM = $row['m'] ?? null;
                    $valH = $row['h'] ?? null;
                } else {
                    $row = $agg[$key][$dia] ?? null;
                    if ($row === null) {
                        continue;
                    }
                    $valM = $esPesaje ? $row['m'] : $row['cvM'];
                    $valH = $esPesaje ? $row['h'] : $row['cvH'];
                }
                $fechaCarga = $combo['fechaCarga'] !== '' ? $combo['fechaCarga'] : null;
                $fechaPesaje = sip_grafica_acum_fecha_por_edad($combo['fechaInicio'], $dia);
                $registros[] = [
                    'granja' => $combo['granja'],
                    'granjaNombre' => sip_grafica_acum_nombre_granja($combo['granja']),
                    'campania' => $combo['campania'],
                    'galpon' => $combo['galpon'],
                    'fechaCarga' => $fechaCarga,
                    'fechaSemana' => $fechaPesaje,
                    'valorM' => $valM,
                    'valorH' => $valH,
                    'estandarM' => $esPesaje ? $std['m'] : null,
                    'estandarH' => $esPesaje ? $std['h'] : null,
                    'dia' => $dia,
                    'semana' => $semCat,
                ];
            }

            $items[] = [
                'success' => true,
                'tipo' => $tipo,
                'nombre' => (string) ($cfg['label'] ?? $tipo),
                'dia' => $dia,
                'semana' => $semCat,
                'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
                'unidad' => (string) ($cfg['unidad'] ?? ''),
                'totalRegistros' => count($registros),
                'registros' => $registros,
            ];
        }

        return [
            'success' => true,
            'tipo' => $tipo,
            'nombre' => (string) ($cfg['label'] ?? $tipo),
            'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
            'items' => $items,
        ];
    }
}
