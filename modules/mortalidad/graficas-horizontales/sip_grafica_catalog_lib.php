<?php

declare(strict_types=1);

/**
 * Catálogo para selectores de gráficas SIP (tipos, granjas, campañas, galpones).
 */

if (!function_exists('sip_grafica_catalog_require_periodo_helpers')) {
    function sip_grafica_catalog_require_periodo_helpers(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/core/lib/filtro_periodo_util.php';
        require_once $root . '/core/lib/hc/hc_campanias_modal_helpers.php';
    }
}

if (!function_exists('sip_grafica_catalog_table_exists')) {
    function sip_grafica_catalog_table_exists(mysqli $conn, string $tabla): bool
    {
        if (function_exists('tabla_existe')) {
            return tabla_existe($conn, $tabla);
        }
        if (function_exists('hc_campanias_tabla_existe')) {
            return hc_campanias_tabla_existe($conn, $tabla);
        }
        $t = $conn->real_escape_string($tabla);
        $rs = @$conn->query("SHOW TABLES LIKE '{$t}'");

        return ($rs && $rs->num_rows > 0);
    }
}

if (!function_exists('sip_grafica_catalog_tipos_grafica')) {
    /**
     * @return array{success:bool, data:list<array<string,mixed>>}
     */
    function sip_grafica_catalog_tipos_grafica(): array
    {
        return [
            'success' => true,
            'data' => [
                [
                    'id' => 'mortalidad',
                    'label' => 'Mortalidad',
                    'secciones' => [
                        ['id' => 'linea', 'label' => '% Mortalidad', 'chartType' => 'line'],
                        ['id' => 'causas', 'label' => 'Causas de mortalidad', 'chartType' => 'bar', 'barMode' => 'stacked'],
                        ['id' => 'pareto', 'label' => 'Pareto', 'chartType' => 'bar'],
                        ['id' => 'all', 'label' => 'Todas', 'chartType' => 'line'],
                    ],
                ],
                [
                    'id' => 'consumo_alimento',
                    'label' => 'Consumo de alimento',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'consumo_gas',
                    'label' => 'Consumo de gas',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'consumo_agua',
                    'label' => 'Consumo de agua',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'pesaje_pollo',
                    'label' => 'Pesaje de pollo',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'ganancia_peso',
                    'label' => 'Ganancia de peso',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'nivel_cloro',
                    'label' => 'Nivel de cloro',
                    'chartType' => 'bar',
                    'barMode' => 'grouped',
                ],
                [
                    'id' => 'respiratorio',
                    'label' => 'Evaluación respiratoria',
                    'chartType' => 'bar',
                    'barMode' => 'grouped',
                ],
                [
                    'id' => 'digestivo',
                    'label' => 'Evaluación digestiva',
                    'chartType' => 'bar',
                    'barMode' => 'stacked',
                ],
                [
                    'id' => 'cv_peso',
                    'label' => 'CV de pesaje de pollo',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'cv_ganancia',
                    'label' => 'CV de ganancia',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'ica_2_4',
                    'label' => 'ICA 2.4',
                    'chartType' => 'line',
                ],
                [
                    'id' => 'iep_2_4',
                    'label' => 'IEP 2.4',
                    'chartType' => 'line',
                ],
            ],
        ];
    }
}

if (!function_exists('sip_grafica_catalog_tipos_index')) {
    /**
     * @return array<string, array<string,mixed>>
     */
    function sip_grafica_catalog_tipos_index(): array
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }
        $index = [];
        foreach (sip_grafica_catalog_tipos_grafica()['data'] ?? [] as $tipo) {
            $id = trim((string) ($tipo['id'] ?? ''));
            if ($id !== '') {
                $index[$id] = $tipo;
            }
        }

        return $index;
    }
}

if (!function_exists('sip_grafica_catalog_tipo_usa_seccion')) {
    function sip_grafica_catalog_tipo_usa_seccion(string $tipo): bool
    {
        $cfg = sip_grafica_catalog_tipos_index()[strtolower(trim($tipo))] ?? null;

        return is_array($cfg) && !empty($cfg['secciones']);
    }
}

if (!function_exists('sip_grafica_catalog_tipo_chart_type')) {
    function sip_grafica_catalog_tipo_chart_type(string $tipo, string $seccion = ''): ?string
    {
        $tipo = strtolower(trim($tipo));
        $cfg = sip_grafica_catalog_tipos_index()[$tipo] ?? null;
        if (!is_array($cfg)) {
            return null;
        }
        if ($seccion !== '' && !empty($cfg['secciones'])) {
            $meta = sip_grafica_catalog_seccion_meta($tipo, $seccion);
            if (is_array($meta) && isset($meta['chartType'])) {
                return (string) $meta['chartType'];
            }
        }
        if (isset($cfg['chartType'])) {
            return (string) $cfg['chartType'];
        }

        return null;
    }
}

if (!function_exists('sip_grafica_catalog_tipo_bar_mode')) {
    function sip_grafica_catalog_tipo_bar_mode(string $tipo, string $seccion = ''): ?string
    {
        $tipo = strtolower(trim($tipo));
        $cfg = sip_grafica_catalog_tipos_index()[$tipo] ?? null;
        if (!is_array($cfg)) {
            return null;
        }
        if ($seccion !== '' && !empty($cfg['secciones'])) {
            $meta = sip_grafica_catalog_seccion_meta($tipo, $seccion);
            if (is_array($meta) && isset($meta['barMode'])) {
                return (string) $meta['barMode'];
            }
        }
        if (isset($cfg['barMode'])) {
            return (string) $cfg['barMode'];
        }

        return null;
    }
}

if (!function_exists('sip_grafica_catalog_seccion_meta')) {
    /**
     * Metadatos de una sección del catálogo (id, label, chartType, …).
     *
     * @return array<string,mixed>|null
     */
    function sip_grafica_catalog_seccion_meta(string $tipo, string $seccionId): ?array
    {
        $tipo = strtolower(trim($tipo));
        $seccionId = strtolower(trim($seccionId));
        $cfg = sip_grafica_catalog_tipos_index()[$tipo] ?? null;
        if (!is_array($cfg)) {
            return null;
        }
        foreach ($cfg['secciones'] ?? [] as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            if (strtolower(trim((string) ($sec['id'] ?? ''))) === $seccionId) {
                return $sec;
            }
        }

        return null;
    }
}

if (!function_exists('sip_grafica_catalog_seccion_ids_por_tipo')) {
    /** @return list<string> */
    function sip_grafica_catalog_seccion_ids_por_tipo(string $tipo): array
    {
        $cfg = sip_grafica_catalog_tipos_index()[strtolower(trim($tipo))] ?? null;
        if (!is_array($cfg)) {
            return [];
        }
        $out = [];
        foreach ($cfg['secciones'] ?? [] as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $id = trim((string) ($sec['id'] ?? ''));
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_catalog_todos_seccion_ids')) {
    /** @return list<string> */
    function sip_grafica_catalog_todos_seccion_ids(): array
    {
        $ids = [];
        foreach (sip_grafica_catalog_tipos_index() as $cfg) {
            foreach ($cfg['secciones'] ?? [] as $sec) {
                if (!is_array($sec)) {
                    continue;
                }
                $id = trim((string) ($sec['id'] ?? ''));
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}

if (!function_exists('sip_grafica_catalog_requiere_galpon')) {
    function sip_grafica_catalog_requiere_galpon(string $tipo, string $seccion = ''): bool
    {
        $tipo = strtolower(trim($tipo));
        $seccion = strtolower(trim($seccion));
        if ($tipo !== 'mortalidad') {
            return true;
        }
        if ($seccion === '') {
            $seccion = 'linea';
        }

        return in_array($seccion, ['linea', 'all'], true);
    }
}

if (!function_exists('sip_grafica_catalog_seccion_requiere_galpon')) {
    /** @deprecated Use sip_grafica_catalog_requiere_galpon() */
    function sip_grafica_catalog_seccion_requiere_galpon(string $seccionId): bool
    {
        return in_array(strtolower(trim($seccionId)), ['linea', 'all'], true);
    }
}

if (!function_exists('sip_grafica_catalog_tipos')) {
    /** @deprecated Use sip_grafica_catalog_tipos_grafica() */
    function sip_grafica_catalog_tipos(): array
    {
        return sip_grafica_catalog_tipos_grafica();
    }
}

if (!function_exists('sip_grafica_catalog_granjas')) {
    /**
     * @param array{zona?:string} $opts
     * @return array{success:bool, data:list<array<string,string>>}
     */
    function sip_grafica_catalog_granjas(mysqli $conn, array $opts = []): array
    {
        if (!sip_grafica_catalog_table_exists($conn, 'pi_dim_detalles')) {
            return ['success' => true, 'data' => []];
        }

        $zona = trim((string) ($opts['zona'] ?? ''));
        $excluidas = "('624','640','641')";

        $sql = "SELECT
            TRIM(p.granja) AS granja,
            COALESCE(NULLIF(MAX(TRIM(rg.nombre)), ''), MAX(TRIM(p.granja))) AS nombre,
            COALESCE(NULLIF(MAX(TRIM(zs.zona)), ''), '') AS zona,
            COALESCE(NULLIF(MAX(TRIM(zs.subzona)), ''), '') AS subzona
        FROM (
            SELECT DISTINCT TRIM(id_granja) AS granja
            FROM pi_dim_detalles
            WHERE TRIM(id_granja) <> ''
              AND TRIM(id_granja) LIKE '6%'
              AND TRIM(id_granja) NOT IN {$excluidas}
        ) p
        LEFT JOIN (
            SELECT LEFT(TRIM(tcencos), 3) AS codigo, MAX(TRIM(tnomcen)) AS nombre
            FROM regcencosgalpones WHERE TRIM(tcencos) <> '' GROUP BY LEFT(TRIM(tcencos), 3)
        ) rg ON CONVERT(rg.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(LEFT(TRIM(p.granja),3) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LEFT JOIN (
            SELECT
                TRIM(det.id_granja) AS codigo,
                MAX(CASE WHEN UPPER(TRIM(car.nombre)) = 'ZONA' THEN TRIM(det.dato) END) AS zona,
                MAX(CASE WHEN UPPER(TRIM(car.nombre)) = 'SUBZONA' THEN TRIM(det.dato) END) AS subzona
            FROM pi_dim_detalles det
            INNER JOIN pi_dim_caracteristicas car ON car.id = det.id_caracteristica
            WHERE TRIM(det.id_granja) <> ''
              AND TRIM(det.id_granja) LIKE '6%'
              AND TRIM(det.id_granja) NOT IN {$excluidas}
              AND UPPER(TRIM(car.nombre)) IN ('ZONA', 'SUBZONA')
            GROUP BY TRIM(det.id_granja)
        ) zs ON CONVERT(TRIM(zs.codigo) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(p.granja) USING utf8mb4) COLLATE utf8mb4_unicode_ci";

        if ($zona !== '' && sip_grafica_catalog_table_exists($conn, 'pi_dim_caracteristicas')) {
            $zEsc = $conn->real_escape_string($zona);
            $sql .= "
        INNER JOIN (
            SELECT TRIM(det.id_granja) AS codigo
            FROM pi_dim_detalles det
            INNER JOIN pi_dim_caracteristicas car ON car.id = det.id_caracteristica
            WHERE UPPER(TRIM(car.nombre)) = 'ZONA'
              AND CONVERT(TRIM(det.dato) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT('{$zEsc}' USING utf8mb4) COLLATE utf8mb4_unicode_ci
        ) fz ON CONVERT(TRIM(fz.codigo) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(p.granja) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        }

        $sql .= ' GROUP BY TRIM(p.granja) ORDER BY TRIM(p.granja)';

        $res = $conn->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $data[] = [
                    'granja' => trim((string) ($r['granja'] ?? '')),
                    'nombre' => trim((string) ($r['nombre'] ?? $r['granja'] ?? '')),
                    'zona' => trim((string) ($r['zona'] ?? '')),
                    'subzona' => trim((string) ($r['subzona'] ?? '')),
                ];
            }
        }

        return ['success' => true, 'data' => $data];
    }
}

if (!function_exists('sip_grafica_catalog_zonas')) {
    /**
     * @return array{success:bool, data:list<string>}
     */
    function sip_grafica_catalog_zonas(mysqli $conn): array
    {
        if (!sip_grafica_catalog_table_exists($conn, 'pi_dim_detalles')
            || !sip_grafica_catalog_table_exists($conn, 'pi_dim_caracteristicas')) {
            return ['success' => true, 'data' => []];
        }

        $excluidas = "('624','640','641')";
        $res = $conn->query("
            SELECT DISTINCT TRIM(det.dato) AS zona
            FROM pi_dim_detalles det
            INNER JOIN pi_dim_caracteristicas car ON car.id = det.id_caracteristica
            WHERE UPPER(TRIM(car.nombre)) = 'ZONA'
              AND TRIM(det.dato) <> ''
              AND TRIM(det.id_granja) LIKE '6%'
              AND TRIM(det.id_granja) NOT IN {$excluidas}
            ORDER BY TRIM(det.dato)
        ");
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $z = trim((string) ($r['zona'] ?? ''));
                if ($z !== '') {
                    $data[] = $z;
                }
            }
        }

        return ['success' => true, 'data' => $data];
    }
}

if (!function_exists('sip_grafica_catalog_resolver_rango')) {
    /**
     * @param array<string,mixed> $get
     * @return array{desde:string,hasta:string}|null
     */
    function sip_grafica_catalog_resolver_rango(array $get): ?array
    {
        sip_grafica_catalog_require_periodo_helpers();

        $periodoTipo = trim((string) ($get['periodoTipo'] ?? 'TODOS'));
        $fechaLegacy = trim((string) ($get['fecha'] ?? ''));

        $rango = periodo_a_rango([
            'periodoTipo' => $periodoTipo,
            'fechaUnica' => trim((string) ($get['fechaUnica'] ?? '')),
            'fechaInicio' => trim((string) ($get['fechaInicio'] ?? '')),
            'fechaFin' => trim((string) ($get['fechaFin'] ?? '')),
            'mesUnico' => trim((string) ($get['mesUnico'] ?? '')),
            'mesInicio' => trim((string) ($get['mesInicio'] ?? '')),
            'mesFin' => trim((string) ($get['mesFin'] ?? '')),
        ]);

        if ($rango === null && $fechaLegacy !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaLegacy)) {
            return ['desde' => $fechaLegacy, 'hasta' => $fechaLegacy];
        }

        return $rango;
    }
}

if (!function_exists('sip_grafica_catalog_periodo_params')) {
    /**
     * Normaliza parámetros de periodo: solo envía los campos del tipo activo.
     * Atajo: fechaInicio + fechaFin sin periodoTipo → ENTRE_FECHAS.
     *
     * @param array<string,mixed> $get
     * @return array<string,mixed>
     */
    function sip_grafica_catalog_periodo_params(array $get): array
    {
        $fechaInicio = trim((string) ($get['fechaInicio'] ?? ''));
        $fechaFin = trim((string) ($get['fechaFin'] ?? ''));
        $tipo = strtoupper(trim((string) ($get['periodoTipo'] ?? '')));

        if ($tipo === '' && $fechaInicio !== '' && $fechaFin !== '') {
            $tipo = 'ENTRE_FECHAS';
        }
        if ($tipo === '') {
            $tipo = 'TODOS';
        }

        $out = ['periodoTipo' => $tipo];

        switch ($tipo) {
            case 'POR_FECHA':
                $out['fechaUnica'] = trim((string) ($get['fechaUnica'] ?? ''));
                break;
            case 'ENTRE_FECHAS':
                $out['fechaInicio'] = $fechaInicio;
                $out['fechaFin'] = $fechaFin;
                break;
            case 'POR_MES':
                $out['mesUnico'] = trim((string) ($get['mesUnico'] ?? ''));
                break;
            case 'ENTRE_MESES':
                $out['mesInicio'] = trim((string) ($get['mesInicio'] ?? ''));
                $out['mesFin'] = trim((string) ($get['mesFin'] ?? ''));
                break;
            default:
                break;
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_catalog_granjas_campanias_galpones')) {
    /**
     * Granjas con campañas (filtradas por periodo) y galpones en una sola respuesta.
     * campanias: list<string>
     *
     * @param array<string,mixed> $opts fechaInicio/fechaFin o periodoTipo + campos HC
     * @return array<string,mixed>
     */
    function sip_grafica_catalog_granjas_campanias_galpones(mysqli $conn, array $opts): array
    {
        sip_grafica_catalog_require_periodo_helpers();

        $periodoOpts = sip_grafica_catalog_periodo_params($opts);
        $periodoTipo = (string) ($periodoOpts['periodoTipo'] ?? 'TODOS');
        $rango = sip_grafica_catalog_resolver_rango($periodoOpts);

        $granjaFiltro = trim((string) ($opts['granja'] ?? ''));
        $zona = trim((string) ($opts['zona'] ?? ''));

        $granjasRes = sip_grafica_catalog_granjas($conn, ['zona' => $zona]);
        $granjasList = $granjasRes['data'] ?? [];

        if ($granjaFiltro !== '') {
            $g3f = sip_grafica_catalog_norm_granja($granjaFiltro);
            $granjasList = array_values(array_filter($granjasList, static function (array $g) use ($g3f): bool {
                return sip_grafica_catalog_norm_granja($g['granja'] ?? '') === $g3f;
            }));
        }

        $keys = array_values(array_unique(array_map(static function (array $g): string {
            return sip_grafica_catalog_norm_granja($g['granja'] ?? '');
        }, $granjasList)));
        sort($keys);

        $byGranja = [];
        foreach ($keys as $k) {
            $byGranja[$k] = [];
        }

        if ($periodoTipo !== 'TODOS' && $rango === null) {
            return [
                'success' => true,
                'periodo' => null,
                'granjas' => sip_grafica_catalog_granjas_campanias_galpones_pack($granjasList, $byGranja, []),
                'message' => 'Periodo incompleto o invalido',
            ];
        }

        hc_merge_campanias_desde_hc_cab($conn, $byGranja, $keys, $rango);
        foreach ($byGranja as $gk => $rows) {
            usort($byGranja[$gk], static function (array $a, array $b): int {
                return strcmp((string) ($a['campania'] ?? ''), (string) ($b['campania'] ?? ''));
            });
        }

        $galponesMap = sip_grafica_catalog_galpones_map($conn, $keys);

        return [
            'success' => true,
            'periodo' => $rango,
            'granjas' => sip_grafica_catalog_granjas_campanias_galpones_pack($granjasList, $byGranja, $galponesMap),
        ];
    }
}

if (!function_exists('sip_grafica_catalog_campanias_to_strings')) {
    /**
     * @param list<array{campania:string}> $rows
     * @return list<string>
     */
    function sip_grafica_catalog_campanias_to_strings(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $c = trim((string) ($row['campania'] ?? ''));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_catalog_granjas_campanias_galpones_pack')) {
    /**
     * @param list<array<string,string>> $granjasList
     * @param array<string,list<array{campania:string}>> $byGranja
     * @param array<string,list<string>> $galponesMap
     * @return list<array<string,mixed>>
     */
    function sip_grafica_catalog_granjas_campanias_galpones_pack(array $granjasList, array $byGranja, array $galponesMap): array
    {
        $out = [];
        foreach ($granjasList as $g) {
            $gk = sip_grafica_catalog_norm_granja($g['granja'] ?? '');
            if ($gk === '') {
                continue;
            }
            $out[] = [
                'granja' => $gk,
                'nombre' => $g['nombre'] ?? $gk,
                'zona' => $g['zona'] ?? '',
                'subzona' => $g['subzona'] ?? '',
                'campanias' => sip_grafica_catalog_campanias_to_strings($byGranja[$gk] ?? []),
                'galpones' => $galponesMap[$gk] ?? [],
            ];
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_catalog_galpones_map')) {
    /**
     * @param list<string> $granjas
     * @return array<string,list<string>>
     */
    function sip_grafica_catalog_galpones_map(mysqli $conn, array $granjas): array
    {
        $out = [];
        foreach ($granjas as $gk) {
            $g3 = sip_grafica_catalog_norm_granja($gk);
            $out[$g3] = sip_grafica_catalog_galpones_list($conn, $g3);
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_catalog_norm_granja')) {
    function sip_grafica_catalog_norm_granja($granja): string
    {
        return substr(str_pad(trim((string) $granja), 3, '0', STR_PAD_LEFT), 0, 3);
    }
}

if (!function_exists('sip_grafica_catalog_galpones_list')) {
    /**
     * @param string|int $granja
     * @return list<string>
     */
    function sip_grafica_catalog_galpones_list(mysqli $conn, $granja): array
    {
        $g3 = sip_grafica_catalog_norm_granja($granja);
        if ($g3 === '' || !sip_grafica_catalog_table_exists($conn, 'pi_dim_detalles')) {
            return [];
        }

        $stmt = $conn->prepare(
            'SELECT DISTINCT TRIM(id_galpon) AS galpon
             FROM pi_dim_detalles
             WHERE CONVERT(TRIM(id_granja) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
               AND TRIM(id_galpon) <> \'\'
             ORDER BY TRIM(id_galpon) ASC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $g3);
        $stmt->execute();
        $res = $stmt->get_result();
        $list = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $gp = trim((string) ($r['galpon'] ?? ''));
                if ($gp !== '') {
                    $list[] = $gp;
                }
            }
        }
        $stmt->close();

        return $list;
    }
}
