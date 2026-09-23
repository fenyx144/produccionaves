<?php
declare(strict_types=1);

/**
 * Resolución granja / campaña / galpón desde `san_fact_historia_clinica_cab`
 * usando `fecha_inicio` y `fecha_fin` (vigente en fecha de referencia o campaña anterior más cercana).
 */

require_once __DIR__ . '/sip_estandares_tree_repository.php';

if (!function_exists('sip_hc_cab_tabla_lista')) {
    function sip_hc_cab_tabla_lista(): string
    {
        return 'san_fact_historia_clinica_cab';
    }
}

if (!function_exists('sip_hc_cab_tabla_disponible')) {
    function sip_hc_cab_tabla_disponible(mysqli $conn): bool
    {
        return sip_est2_repo_table_exists($conn, sip_hc_cab_tabla_lista());
    }
}

if (!function_exists('sip_hc_cab_tabla_disponible_movi_zonas')) {
    function sip_hc_cab_tabla_disponible_movi_zonas(mysqli $conn): bool
    {
        return sip_est2_repo_table_exists($conn, 'movi_zonas');
    }
}

if (!function_exists('sip_hc_cab_tiene_columnas_fecha')) {
    function sip_hc_cab_tiene_columnas_fecha(mysqli $conn): bool
    {
        static $cache = [];
        $k = spl_object_hash($conn);
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        $t = sip_hc_cab_tabla_lista();

        return $cache[$k] = sip_est2_repo_col_exists($conn, $t, 'fecha_inicio')
            && sip_est2_repo_col_exists($conn, $t, 'fecha_fin');
    }
}

if (!function_exists('sip_hc_cab_normalizar_granja')) {
    function sip_hc_cab_normalizar_granja(string $granja): string
    {
        return substr(str_pad(trim($granja), 3, '0', STR_PAD_LEFT), 0, 3);
    }
}

if (!function_exists('sip_hc_cab_normalizar_campania')) {
    function sip_hc_cab_normalizar_campania(string $campania): string
    {
        return substr(str_pad(preg_replace('/\s+/', '', trim($campania)), 3, '0', STR_PAD_LEFT), -3);
    }
}

if (!function_exists('sip_hc_cab_key_granja')) {
    /** Clave de array no numérica (evita que PHP convierta "601" en int 601). */
    function sip_hc_cab_key_granja(string $granja): string
    {
        return 'G' . sip_hc_cab_normalizar_granja($granja);
    }
}

if (!function_exists('sip_hc_cab_key_granja_parse')) {
    function sip_hc_cab_key_granja_parse(string $key): string
    {
        return sip_hc_cab_normalizar_granja(ltrim($key, 'G'));
    }
}

if (!function_exists('sip_hc_cab_es_granja_codigo_6')) {
    function sip_hc_cab_es_granja_codigo_6(string $granja3): bool
    {
        $g3 = sip_hc_cab_normalizar_granja($granja3);

        return $g3 !== '' && $g3[0] === '6';
    }
}

if (!function_exists('sip_hc_cab_codigo_cenco6')) {
    /** Código CENCO compacto de 6 dígitos (621001). */
    function sip_hc_cab_codigo_cenco6(string $granja, string $campania): string
    {
        return sip_hc_cab_normalizar_granja($granja) . sip_hc_cab_normalizar_campania($campania);
    }
}

if (!function_exists('sip_hc_cab_codigo_correlativo')) {
    /** Código correlativo granja-campaña (621-0001). */
    function sip_hc_cab_codigo_correlativo(string $granja, string $campania): string
    {
        $g3 = sip_hc_cab_normalizar_granja($granja);
        if ($g3 === '') {
            return '';
        }
        $c4 = str_pad(sip_hc_cab_normalizar_campania($campania), 4, '0', STR_PAD_LEFT);

        return $g3 . '-' . $c4;
    }
}

if (!function_exists('sip_hc_cab_normalizar_codigo_cenco')) {
    /** Normaliza 621-0001 o 621001 a 621001 para comparación interna. */
    function sip_hc_cab_normalizar_codigo_cenco(string $codigo): string
    {
        $cod = preg_replace('/\s+/', '', trim($codigo));
        if ($cod === '') {
            return '';
        }
        if (preg_match('/^(\d{3})-(\d{3,4})$/', $cod, $m)) {
            return $m[1] . substr(str_pad($m[2], 3, '0', STR_PAD_LEFT), -3);
        }
        $digits = preg_replace('/\D/', '', $cod);
        if (strlen($digits) >= 6) {
            return substr($digits, 0, 3) . substr($digits, -3);
        }

        return $cod;
    }
}

if (!function_exists('sip_hc_cab_normalizar_fecha_ymd')) {
    /** @param mixed $raw */
    function sip_hc_cab_normalizar_fecha_ymd($raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '' || $s === '0000-00-00') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }

        return null;
    }
}

if (!function_exists('sip_hc_cab_vigencia_rank')) {
    /**
     * Mayor rank = mejor candidato para la fecha de referencia.
     * - Vigente: ref dentro de [fecha_inicio, fecha_fin] (fin abierto si fecha_fin vacía).
     * - Anterior: la campaña ya terminó (fecha_fin &lt; ref) o solo tiene inicio &lt;= ref sin fin.
     *
     * @return array{rank:int,kind:string,fecha_inicio:?string,fecha_fin:?string}
     */
    function sip_hc_cab_vigencia_rank(?string $fechaInicio, ?string $fechaFin, string $fechaRef): array
    {
        $fi = sip_hc_cab_normalizar_fecha_ymd($fechaInicio);
        $ff = sip_hc_cab_normalizar_fecha_ymd($fechaFin);
        $ref = sip_hc_cab_normalizar_fecha_ymd($fechaRef);
        if ($ref === null || $fi === null) {
            return ['rank' => PHP_INT_MAX, 'kind' => 'sin_fecha', 'fecha_inicio' => $fi, 'fecha_fin' => $ff];
        }

        // Distancia absoluta en días entre fecha_inicio y fecha de referencia
        $diffSec = abs(strtotime($fi) - strtotime($ref));
        $diffDays = (int) ($diffSec / 86400);

        // Vigente: fecha_inicio <= ref Y (fecha_fin es null O fecha_fin >= ref)
        if ($fi <= $ref && ($ff === null || $ff >= $ref)) {
            return [
                'rank' => $diffDays,
                'kind' => 'vigente',
                'fecha_inicio' => $fi,
                'fecha_fin' => $ff,
            ];
        }

        // Anterior: fecha_inicio <= ref pero NO vigente (fecha_fin < ref)
        if ($fi <= $ref) {
            return [
                'rank' => 1000000 + $diffDays,
                'kind' => 'anterior',
                'fecha_inicio' => $fi,
                'fecha_fin' => $ff,
            ];
        }

        // Futura: fecha_inicio > ref
        return [
            'rank' => 2000000 + $diffDays,
            'kind' => 'futura',
            'fecha_inicio' => $fi,
            'fecha_fin' => $ff,
        ];
    }
}

if (!function_exists('sip_hc_cab_fetch_agrupado_por_granja_campania')) {
    /**
     * Agrupa filas cab por granja+campaña con rango de fechas agregado y galpones.
     *
     * @param list<string> $granjas3 vacío = todas las granjas en cab
     * @return array<string, array<string, array{granja:string,campania:string,fecha_inicio:?string,fecha_fin:?string,galpones:list<string>}>>
     */
    function sip_hc_cab_fetch_agrupado_por_granja_campania(mysqli $conn, array $granjas3 = []): array
    {
        $keys = [];
        foreach ($granjas3 as $g) {
            $g3 = sip_hc_cab_normalizar_granja((string) $g);
            if ($g3 !== '') {
                $keys[$g3] = true;
            }
        }
        $keys = array_keys($keys);

        $out = [];

        // 1. Fuente principal: movi_zonas
        $tieneMz = sip_hc_cab_tabla_disponible_movi_zonas($conn);
        if ($tieneMz) {
            $sqlMz = 'SELECT LEFT(TRIM(`tcencos`),3) AS granja, RIGHT(TRIM(`tcencos`),3) AS campania,'
                . ' TRIM(`tcodint`) AS galpon, MIN(DATE(`tfectra`)) AS fecha_inicio, MAX(DATE(`tfectra`)) AS fecha_fin'
                . ' FROM `movi_zonas`'
                . ' WHERE LENGTH(TRIM(`tcencos`))=6'
                . ' AND LEFT(TRIM(`tcencos`),1)=\'6\''
                . ' AND RIGHT(TRIM(`tcencos`),3)<>\'000\''
                . ' AND LEFT(TRIM(`tcencos`),3) NOT IN (\'619\',\'624\',\'650\')';
            $typesMz = '';
            $bindMz = [];
            if ($keys !== []) {
                $phMz = implode(',', array_fill(0, count($keys), '?'));
                $sqlMz .= ' AND LEFT(TRIM(`tcencos`),3) IN (' . $phMz . ')';
                $typesMz = str_repeat('s', count($keys));
                $bindMz = $keys;
            }
            $sqlMz .= ' GROUP BY LEFT(TRIM(`tcencos`),3), RIGHT(TRIM(`tcencos`),3), TRIM(`tcodint`)';
            $stMz = $conn->prepare($sqlMz);
            if ($stMz) {
                if ($bindMz !== []) {
                    $paramsMz = array_merge([$typesMz], $bindMz);
                    $refsMz = [];
                    foreach ($paramsMz as $j => $_) {
                        $refsMz[$j] = &$paramsMz[$j];
                    }
                    call_user_func_array([$stMz, 'bind_param'], $refsMz);
                }
                if ($stMz->execute()) {
                    $rsMz = $stMz->get_result();
                    while ($rsMz && ($row = $rsMz->fetch_assoc())) {
                        $g = sip_hc_cab_normalizar_granja((string) ($row['granja'] ?? ''));
                        $c = sip_hc_cab_normalizar_campania((string) ($row['campania'] ?? ''));
                        if ($g === '' || $c === '' || $c === '000') {
                            continue;
                        }
                        $gk = sip_hc_cab_key_granja($g);
                        $fiMz = sip_hc_cab_normalizar_fecha_ymd($row['fecha_inicio'] ?? null);
                        $ffMz = sip_hc_cab_normalizar_fecha_ymd($row['fecha_fin'] ?? null);
                        if (!isset($out[$gk])) {
                            $out[$gk] = [];
                        }
                        if (!isset($out[$gk][$c])) {
                            $out[$gk][$c] = [
                                'granja' => $g,
                                'campania' => $c,
                                'fecha_inicio' => $fiMz,
                                'fecha_fin' => $ffMz,
                                'galpones' => [],
                            ];
                        } else {
                            if ($out[$gk][$c]['fecha_inicio'] === null && $fiMz !== null) {
                                $out[$gk][$c]['fecha_inicio'] = $fiMz;
                            }
                            if ($out[$gk][$c]['fecha_fin'] === null && $ffMz !== null) {
                                $out[$gk][$c]['fecha_fin'] = $ffMz;
                            }
                        }
                        $gal = trim((string) ($row['galpon'] ?? ''));
                        if ($gal !== '' && $gal !== '0' && !in_array($gal, $out[$gk][$c]['galpones'], true)) {
                            $out[$gk][$c]['galpones'][] = $gal;
                        }
                    }
                }
                $stMz->close();
            }
        }

        // 2. Fallback: san_fact_historia_clinica_cab para granjas sin datos en movi_zonas
        $granjasSinDatos = [];
        foreach ($keys as $k) {
            $gk = sip_hc_cab_key_granja((string) $k);
            if (empty($out[$gk])) {
                $granjasSinDatos[] = $k;
            }
        }
        if ($granjasSinDatos !== [] && sip_hc_cab_tabla_disponible($conn)) {
            $t = sip_hc_cab_tabla_lista();
            $tieneFechas = sip_hc_cab_tiene_columnas_fecha($conn);
            $colsCab = 'TRIM(`granja`) AS granja, TRIM(`campania`) AS campania, TRIM(CAST(`galpon` AS CHAR)) AS galpon';
            if ($tieneFechas) {
                $colsCab .= ', `fecha_inicio`, `fecha_fin`';
            }
            $sqlCab = 'SELECT ' . $colsCab . ' FROM `' . $t . '`'
                . ' WHERE TRIM(`granja`) <> \'\' AND TRIM(`campania`) <> \'\''
                . ' AND TRIM(`granja`) IN (' . implode(',', array_fill(0, count($granjasSinDatos), '?')) . ')'
                . ' ORDER BY TRIM(`granja`), TRIM(`campania`)';
            $typesCab = str_repeat('s', count($granjasSinDatos));
            $stCab = $conn->prepare($sqlCab);
            if ($stCab) {
                $paramsCab = array_merge([$typesCab], $granjasSinDatos);
                $refsCab = [];
                foreach ($paramsCab as $i => $_) {
                    $refsCab[$i] = &$paramsCab[$i];
                }
                call_user_func_array([$stCab, 'bind_param'], $refsCab);
                $stCab->execute();
                $rsCab = $stCab->get_result();
                while ($rsCab && ($row = $rsCab->fetch_assoc())) {
                    $g = sip_hc_cab_normalizar_granja((string) ($row['granja'] ?? ''));
                    $c = sip_hc_cab_normalizar_campania((string) ($row['campania'] ?? ''));
                    if ($g === '' || $c === '' || $c === '000') {
                        continue;
                    }
                    $gk = sip_hc_cab_key_granja($g);
                    if (!isset($out[$gk])) {
                        $out[$gk] = [];
                    }
                    if (!isset($out[$gk][$c])) {
                        $out[$gk][$c] = [
                            'granja' => $g,
                            'campania' => $c,
                            'fecha_inicio' => null,
                            'fecha_fin' => null,
                            'galpones' => [],
                        ];
                    }
                    $fi = $tieneFechas ? sip_hc_cab_normalizar_fecha_ymd($row['fecha_inicio'] ?? null) : null;
                    $ff = $tieneFechas ? sip_hc_cab_normalizar_fecha_ymd($row['fecha_fin'] ?? null) : null;
                    if ($fi !== null) {
                        if ($out[$gk][$c]['fecha_inicio'] === null || $fi < $out[$gk][$c]['fecha_inicio']) {
                            $out[$gk][$c]['fecha_inicio'] = $fi;
                        }
                    }
                    if ($ff !== null) {
                        if ($out[$gk][$c]['fecha_fin'] === null || $ff > $out[$gk][$c]['fecha_fin']) {
                            $out[$gk][$c]['fecha_fin'] = $ff;
                        }
                    }
                    $gal = trim((string) ($row['galpon'] ?? ''));
                    if ($gal !== '' && $gal !== '0' && !in_array($gal, $out[$gk][$c]['galpones'], true)) {
                        $out[$gk][$c]['galpones'][] = $gal;
                    }
                }
                $stCab->close();
            }
        }

        return $out;
    }
}

if (!function_exists('sip_hc_cab_campania_para_granja_en_fecha')) {
    /**
     * Campaña vigente o, si no hay, la anterior más cercana (por fecha_fin / fecha_inicio).
     *
     * @return array{
     *   campania:string,
     *   codigo:string,
     *   fecha_inicio:?string,
     *   fecha_fin:?string,
     *   kind:string,
     *   fallback:bool,
     *   galpones:list<string>
     * }|null
     */
    function sip_hc_cab_campania_para_granja_en_fecha(mysqli $conn, string $granja3, string $fechaYmd): ?array
    {
        $g3 = sip_hc_cab_normalizar_granja($granja3);
        if ($g3 === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fechaYmd))) {
            return null;
        }
        $gk = sip_hc_cab_key_granja($g3);
        $grupos = sip_hc_cab_fetch_agrupado_por_granja_campania($conn, [$g3]);
        if (!isset($grupos[$gk]) || $grupos[$gk] === []) {
            return null;
        }

        $mejor = null;
        $mejorRank = PHP_INT_MAX;
        $mejorKind = 'none';
        foreach ($grupos[$gk] as $camp => $item) {
            $meta = sip_hc_cab_vigencia_rank($item['fecha_inicio'], $item['fecha_fin'], $fechaYmd);
            // Menor rank = fecha_inicio más cercana a la fecha de referencia = mejor
            if ($meta['rank'] < $mejorRank || ($meta['rank'] === $mejorRank && $mejor !== null && strcmp($camp, (string) $mejor['campania']) > 0)) {
                $mejorRank = $meta['rank'];
                $mejorKind = (string) $meta['kind'];
                $mejor = [
                    'campania' => $camp,
                    'codigo' => sip_hc_cab_codigo_correlativo($g3, $camp),
                    'fecha_inicio' => $meta['fecha_inicio'],
                    'fecha_fin' => $meta['fecha_fin'],
                    'kind' => $mejorKind,
                    'fallback' => $mejorKind === 'sin_fecha',
                    'galpones' => $item['galpones'],
                ];
            }
        }

        return $mejor;
    }
}

if (!function_exists('sip_hc_cab_galpones_granja_campania')) {
    /**
     * @return list<array{tcodint:string,galpon:string,nombre:string}>
     */
    function sip_hc_cab_galpones_granja_campania(mysqli $conn, string $granja3, string $campania): array
    {
        $g3 = sip_hc_cab_normalizar_granja($granja3);
        $c3 = sip_hc_cab_normalizar_campania($campania);
        if ($g3 === '' || $c3 === '') {
            return [];
        }
        $gk = sip_hc_cab_key_granja($g3);
        $grupos = sip_hc_cab_fetch_agrupado_por_granja_campania($conn, [$g3]);
        $gps = $grupos[$gk][$c3]['galpones'] ?? [];
        $out = [];
        foreach ($gps as $gal) {
            $gal = trim((string) $gal);
            if ($gal !== '') {
                $out[] = ['tcodint' => $gal, 'galpon' => $gal, 'nombre' => ''];
            }
        }
        usort($out, static function (array $a, array $b): int {
            $na = is_numeric($a['galpon']) ? (float) $a['galpon'] : null;
            $nb = is_numeric($b['galpon']) ? (float) $b['galpon'] : null;
            if ($na !== null && $nb !== null) {
                return $na <=> $nb;
            }

            return strnatcasecmp((string) $a['galpon'], (string) $b['galpon']);
        });

        return $out;
    }
}

if (!function_exists('sip_hc_cab_append_cenco_item')) {
    /**
     * @param array<string, array<string, mixed>> $byCod
     * @param array{kind?:string,rank?:int} $meta
     */
    function sip_hc_cab_append_cenco_item(
        array &$byCod,
        string $g3,
        string $camp3,
        array $item,
        array $meta,
        bool $esFallback,
        int &$fallbackCount
    ): void {
        $cod6 = sip_hc_cab_codigo_cenco6($g3, $camp3);
        if (isset($byCod[$cod6])) {
            return;
        }
        if ($esFallback) {
            $fallbackCount++;
        }
        $codCorrel = sip_hc_cab_codigo_correlativo($g3, $camp3);
        $galpones = [];
        foreach ($item['galpones'] as $gal) {
            $gal = trim((string) $gal);
            if ($gal !== '') {
                $galpones[] = ['tcodint' => $gal, 'galpon' => $gal, 'nombre' => ''];
            }
        }
        $byCod[$cod6] = [
            'codigo' => $codCorrel,
            'granja' => $g3,
            'campania' => $camp3,
            'nombre' => '',
            'nombre_granja' => '',
            'etiqueta' => $codCorrel,
            'fecha_inicio' => $item['fecha_inicio'] ?? null,
            'fecha_fin' => $item['fecha_fin'] ?? null,
            'campania_fallback' => $esFallback,
            'vigencia_kind' => (string) ($meta['kind'] ?? ''),
            'galpones' => $galpones,
        ];
    }
}

if (!function_exists('sip_hc_cab_campanias_por_granja_en_fecha')) {
    /**
     * Campañas por granja (3 dígitos) para una fecha de referencia.
     *
     * @param array{
     *   granjas?:list<string>,
     *   solo_granja_6?:bool,
     *   max_campanias_por_granja?:int|null
     * } $opts max_campanias_por_granja=1 deja solo la vigente mejor rankeada (p. ej. TTPBB)
     * @return array<string, list<array{campania:string,item:array<string,mixed>,meta:array<string,mixed>,fallback:bool}>>
     */
    function sip_hc_cab_campanias_por_granja_en_fecha(mysqli $conn, string $fechaYmd, array $opts = []): array
    {
        $opts = array_merge([
            'granjas' => [],
            'solo_granja_6' => true,
            'max_campanias_por_granja' => null,
        ], $opts);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fechaYmd)) || !sip_hc_cab_tabla_disponible($conn)) {
            return [];
        }

        $granjasFiltroGk = [];
        $granjasCodigosFetch = [];
        foreach ((array) ($opts['granjas'] ?? []) as $g) {
            $g3 = sip_hc_cab_normalizar_granja((string) $g);
            if ($g3 === '') {
                continue;
            }
            if (!empty($opts['solo_granja_6']) && !sip_hc_cab_es_granja_codigo_6($g3)) {
                continue;
            }
            $granjasFiltroGk[sip_hc_cab_key_granja($g3)] = true;
            $granjasCodigosFetch[] = $g3;
        }
        $filtrarPorGranjas = $granjasCodigosFetch !== [];

        $grupos = sip_hc_cab_fetch_agrupado_por_granja_campania(
            $conn,
            $filtrarPorGranjas ? $granjasCodigosFetch : []
        );

        $porGranja = [];

        foreach ($grupos as $gk => $camps) {
            $g3 = sip_hc_cab_key_granja_parse($gk);
            if (!empty($opts['solo_granja_6']) && !sip_hc_cab_es_granja_codigo_6($g3)) {
                continue;
            }
            if ($filtrarPorGranjas && !isset($granjasFiltroGk[$gk])) {
                continue;
            }

            $items = [];

            foreach ($camps as $camp => $item) {
                $camp3 = sip_hc_cab_normalizar_campania((string) ($item['campania'] ?? (string) $camp));
                if ($camp3 === '' || $camp3 === '000') {
                    continue;
                }
                $meta = sip_hc_cab_vigencia_rank($item['fecha_inicio'], $item['fecha_fin'], $fechaYmd);
                $items[] = [
                    'campania' => $camp3,
                    'item' => $item,
                    'meta' => $meta,
                    'fallback' => $meta['kind'] === 'sin_fecha',
                ];
            }

            if ($items !== []) {
                $maxCamp = $opts['max_campanias_por_granja'] ?? null;
                if ($maxCamp !== null && (int) $maxCamp === 1 && count($items) > 1) {
                    usort($items, static function (array $a, array $b): int {
                        $ra = (int) ($a['meta']['rank'] ?? 0);
                        $rb = (int) ($b['meta']['rank'] ?? 0);
                        if ($ra !== $rb) {
                            return $ra <=> $rb;  // Ascendente: menor distancia = mejor
                        }
                        $fa = sip_hc_cab_normalizar_fecha_ymd($a['item']['fecha_inicio'] ?? null) ?? '';
                        $fb = sip_hc_cab_normalizar_fecha_ymd($b['item']['fecha_inicio'] ?? null) ?? '';
                        return strcmp($fb, $fa);  // Desempate: fecha_inicio más reciente
                    });
                    $items = [array_shift($items)];
                } else {
                    usort($items, static function (array $a, array $b): int {
                        return strcmp((string) ($a['campania'] ?? ''), (string) ($b['campania'] ?? ''));
                    });
                }
                $porGranja[sip_hc_cab_key_granja($g3)] = $items;
            }
        }

        return $porGranja;
    }
}

if (!function_exists('sip_hc_cab_campanias_por_granja_api_shape')) {
    /**
     * @param array<string, list<array{campania:string,item:array<string,mixed>,meta:array<string,mixed>,fallback:bool}>> $porGranja
     * @return array<string, list<array<string, mixed>>>
     */
    function sip_hc_cab_campanias_por_granja_api_shape(array $porGranja): array
    {
        $out = [];
        foreach ($porGranja as $gk => $rows) {
            $g3 = sip_hc_cab_key_granja_parse((string) $gk);
            $items = [];
            foreach ($rows as $row) {
                $camp3 = (string) ($row['campania'] ?? '');
                if ($camp3 === '') {
                    continue;
                }
                $items[] = [
                    'campania' => $camp3,
                    'codigo' => sip_hc_cab_codigo_correlativo($g3, $camp3),
                    'campania_fallback' => (bool) ($row['fallback'] ?? false),
                    'fecha_inicio' => $row['item']['fecha_inicio'] ?? null,
                    'fecha_fin' => $row['item']['fecha_fin'] ?? null,
                ];
            }
            if ($items !== []) {
                $out[$g3] = $items;
            }
        }

        return $out;
    }
}

if (!function_exists('sip_hc_cab_listar_cencos_por_fecha_referencia')) {
    /**
     * Lista CENCOS (granja+campaña 6 dígitos) con galpones para una fecha de referencia.
     *
     * @param array{
     *   granjas?:list<string>,
     *   solo_granja_6?:bool,
     *   nombres_ccos?:bool
     * } $opts
     * @return array{
     *   ok:bool,
     *   cencos:list<array<string,mixed>>,
     *   campanias_por_granja:array<string,list<array<string,mixed>>>,
     *   message:string,
     *   fallback_count:int
     * }
     */
    function sip_hc_cab_listar_cencos_por_fecha_referencia(mysqli $conn, string $fechaYmd, array $opts = []): array
    {
        $opts = array_merge([
            'granjas' => [],
            'solo_granja_6' => true,
            'nombres_ccos' => true,
        ], $opts);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($fechaYmd))) {
            return [
                'ok' => false,
                'cencos' => [],
                'campanias_por_granja' => [],
                'message' => 'Fecha de referencia inválida.',
                'fallback_count' => 0,
            ];
        }
        if (!sip_hc_cab_tabla_disponible($conn)) {
            return [
                'ok' => false,
                'cencos' => [],
                'campanias_por_granja' => [],
                'message' => 'Tabla san_fact_historia_clinica_cab no disponible.',
                'fallback_count' => 0,
            ];
        }

        $optsCamp = $opts;
        if (!array_key_exists('max_campanias_por_granja', $optsCamp)) {
            $optsCamp['max_campanias_por_granja'] = null;
        }
        $porGranja = sip_hc_cab_campanias_por_granja_en_fecha($conn, $fechaYmd, $optsCamp);
        $byCod = [];
        $fallbackCount = 0;

        foreach ($porGranja as $gk => $rows) {
            $g3 = sip_hc_cab_key_granja_parse((string) $gk);
            foreach ($rows as $row) {
                sip_hc_cab_append_cenco_item(
                    $byCod,
                    $g3,
                    (string) $row['campania'],
                    $row['item'],
                    $row['meta'],
                    (bool) ($row['fallback'] ?? false),
                    $fallbackCount
                );
            }
        }

        if ($byCod === []) {
            return [
                'ok' => true,
                'cencos' => [],
                'campanias_por_granja' => [],
                'message' => 'No hay campañas en san_fact_historia_clinica_cab para esta fecha de referencia.',
                'fallback_count' => 0,
            ];
        }

        if (!empty($opts['nombres_ccos']) && sip_est2_repo_table_exists($conn, 'ccos')) {
            $codes = array_keys($byCod);
            $ph = implode(',', array_fill(0, count($codes), '?'));
            $sqlC = 'SELECT TRIM(codigo) AS codigo, TRIM(nombre) AS nombre FROM ccos WHERE codigo IN (' . $ph . ')';
            $stC = $conn->prepare($sqlC);
            if ($stC) {
                $types = str_repeat('s', count($codes));
                $params = array_merge([$types], $codes);
                $refs = [];
                foreach ($params as $i => $_) {
                    $refs[$i] = &$params[$i];
                }
                call_user_func_array([$stC, 'bind_param'], $refs);
                $stC->execute();
                $rsC = $stC->get_result();
                while ($rsC && ($r = $rsC->fetch_assoc())) {
                    $c = trim((string) ($r['codigo'] ?? ''));
                    if ($c !== '' && isset($byCod[$c])) {
                        $nom = trim((string) ($r['nombre'] ?? ''));
                        $byCod[$c]['nombre'] = $nom;
                        $byCod[$c]['nombre_granja'] = $nom;
                        $codShow = (string) ($byCod[$c]['codigo'] ?? $c);
                        $byCod[$c]['etiqueta'] = $nom !== '' ? ($codShow . ' · ' . $nom) : $codShow;
                    }
                }
                $stC->close();
            }
        }

        $cencos = array_values($byCod);
        usort($cencos, static function (array $a, array $b): int {
            return strcmp((string) ($a['codigo'] ?? ''), (string) ($b['codigo'] ?? ''));
        });

        $msg = '';

        return [
            'ok' => true,
            'cencos' => $cencos,
            'campanias_por_granja' => sip_hc_cab_campanias_por_granja_api_shape($porGranja),
            'message' => $msg,
            'fallback_count' => $fallbackCount,
        ];
    }
}
