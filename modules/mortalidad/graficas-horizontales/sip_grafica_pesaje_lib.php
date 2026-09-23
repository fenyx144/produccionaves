<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';
require_once __DIR__ . '/sip_grafica_std_lib.php';
require_once __DIR__ . '/sip_grafica_peso_cloro.php';

if (!function_exists('sip_grafica_pesaje_tedades_canonicas')) {
    /** Edades de pesaje para gráficas horizontales (referencia de negocio). */
    function sip_grafica_pesaje_tedades_canonicas(): array
    {
        return [1, 8, 15, 22, 29, 36, 38, 41, 43];
    }
}

if (!function_exists('sip_grafica_pesaje_edad_es_dia1_movi')) {
    /** Edad 1 (día API) = peso inicial; fuente exclusiva movi_zonas (E001). */
    function sip_grafica_pesaje_edad_es_dia1_movi(int $edad): bool
    {
        return $edad === 1;
    }
}

if (!function_exists('sip_grafica_pesaje_dias_desde_inicio')) {
    /** Días a sumar a fecha_inicio del lote según edad (1 = día de entrada). */
    function sip_grafica_pesaje_dias_desde_inicio(int $tedad): int
    {
        if ($tedad <= 1) {
            return 0;
        }

        return $tedad - 1;
    }
}

if (!function_exists('sip_grafica_pesaje_fecha_por_tedad')) {
    function sip_grafica_pesaje_fecha_por_tedad(?string $fechaInicio, int $tedad): ?string
    {
        if ($fechaInicio === null || $fechaInicio === '' || $fechaInicio === '0000-00-00' || $tedad < 0) {
            return null;
        }
        $ts = strtotime($fechaInicio);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts + sip_grafica_pesaje_dias_desde_inicio($tedad) * 86400);
    }
}

if (!function_exists('sip_grafica_pesaje_tedad_canonica_valida')) {
    function sip_grafica_pesaje_tedad_canonica_valida(int $tedad): bool
    {
        return in_array($tedad, sip_grafica_pesaje_tedades_canonicas(), true);
    }
}

if (!function_exists('sip_grafica_pesaje_tedad_a_dia_api')) {
    /** Día expuesto al front/API (peso inicial = 1). */
    function sip_grafica_pesaje_tedad_a_dia_api(int $tedad): int
    {
        return max(1, $tedad);
    }
}

if (!function_exists('sip_grafica_pesaje_dia_api_a_tedad')) {
    /** Convierte día API → edad de negocio (día 1 = peso inicial). */
    function sip_grafica_pesaje_dia_api_a_tedad(int $dia): int
    {
        return max(1, $dia);
    }
}

if (!function_exists('sip_grafica_pesaje_dias_api_canonicos')) {
    /** Días de pesaje expuestos al front/API. */
    function sip_grafica_pesaje_dias_api_canonicos(): array
    {
        $out = [];
        foreach (sip_grafica_pesaje_tedades_canonicas() as $tedad) {
            $out[] = sip_grafica_pesaje_tedad_a_dia_api((int) $tedad);
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_dia_api_valido')) {
    function sip_grafica_pesaje_dia_api_valido(int $dia): bool
    {
        return in_array($dia, sip_grafica_pesaje_dias_api_canonicos(), true);
    }
}

if (!function_exists('sip_grafica_pesaje_dia_semana_catalogo')) {
    /** Semana de negocio asociada al día API (null si no aplica). */
    function sip_grafica_pesaje_dia_semana_catalogo(int $diaApi): ?int
    {
        static $map = [
            1 => null,
            8 => 1,
            15 => 2,
            22 => 3,
            29 => 4,
            36 => 5,
            38 => null,
            41 => null,
            43 => 6,
        ];

        return array_key_exists($diaApi, $map) ? $map[$diaApi] : null;
    }
}

if (!function_exists('sip_grafica_pesaje_dias_catalogo_build')) {
    /**
     * @return array{success:bool,tipo:string,dias:list<array{dia:int,semana:?int}>}|array{success:bool,message:string}
     */
    function sip_grafica_pesaje_dias_catalogo_build(string $tipo): array
    {
        $tipoNorm = strtolower(trim($tipo));
        $permitidos = ['pesaje_pollo', 'cv_peso', 'ganancia_peso', 'cv_ganancia'];
        if (!in_array($tipoNorm, $permitidos, true)) {
            return [
                'success' => false,
                'message' => 'Tipo invalido. Valores permitidos: ' . implode(', ', $permitidos),
            ];
        }

        $dias = [];
        foreach (sip_grafica_pesaje_dias_api_canonicos() as $diaApi) {
            if (($tipoNorm === 'cv_peso' || $tipoNorm === 'ganancia_peso' || $tipoNorm === 'cv_ganancia') && $diaApi === 1) {
                continue;
            }
            $dias[] = [
                'dia' => $diaApi,
                'semana' => sip_grafica_pesaje_dia_semana_catalogo($diaApi),
            ];
        }

        return [
            'success' => true,
            'tipo' => $tipoNorm,
            'dias' => $dias,
        ];
    }
}

if (!function_exists('sip_grafica_pesaje_tedad_a_dia')) {
    /**
     * Día de gráfica para una tedad en muestreoaves.
     * Edad 1 (peso inicial) no viene de muestreoaves; se excluye en la consulta.
     */
    function sip_grafica_pesaje_tedad_a_dia(int $tedad): int
    {
        return max(1, $tedad);
    }
}

if (!function_exists('sip_grafica_pesaje_linea_tiene_dia')) {
    /** @param list<array<string,mixed>> $dataPorDia */
    function sip_grafica_pesaje_linea_tiene_dia(array $dataPorDia, int $dia): bool
    {
        foreach ($dataPorDia as $entry) {
            if ((int) ($entry['dia'] ?? -1) === $dia) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('sip_grafica_pesaje_linea_asegurar_dia_cero')) {
    /**
     * Punto D1 con estándar si falta en la serie (peso inicial vía movi_zonas).
     *
     * @param list<array<string,mixed>> $dataPorDia
     * @return list<array<string,mixed>>
     */
    function sip_grafica_pesaje_linea_asegurar_dia_cero(
        array $dataPorDia,
        ?string $fechaInicio,
        array $stdMap,
        string $tareaKey,
        string $parametroKey
    ): array {
        if (sip_grafica_pesaje_linea_tiene_dia($dataPorDia, 1)) {
            return $dataPorDia;
        }
        if ($fechaInicio === null || $fechaInicio === '' || $fechaInicio === '0000-00-00') {
            return $dataPorDia;
        }

        [$sMin, $sMax] = sip_grafica_std_rango_desde_map($stdMap, $tareaKey, $parametroKey, 1);

        array_unshift($dataPorDia, [
            'dia' => 1,
            'fecha' => sip_grafica_pesaje_fecha_por_tedad($fechaInicio, 1),
            'estandar' => [
                'min' => $sMin ?? $sMax,
                'max' => $sMax ?? $sMin,
            ],
            'macho' => [
                'valor' => null,
                'cumple' => null,
            ],
            'hembra' => [
                'valor' => null,
                'cumple' => null,
            ],
        ]);

        return $dataPorDia;
    }
}

if (!function_exists('sip_grafica_cv_linea_asegurar_dia_cero')) {
    /**
     * @param list<array<string,mixed>> $dataPorDia
     * @return list<array<string,mixed>>
     */
    function sip_grafica_cv_linea_asegurar_dia_cero(array $dataPorDia, ?string $fechaInicio): array
    {
        if (sip_grafica_pesaje_linea_tiene_dia($dataPorDia, 1)) {
            return $dataPorDia;
        }
        if ($fechaInicio === null || $fechaInicio === '' || $fechaInicio === '0000-00-00') {
            return $dataPorDia;
        }

        array_unshift($dataPorDia, [
            'dia' => 1,
            'fecha' => sip_grafica_pesaje_fecha_por_tedad($fechaInicio, 1),
            'macho' => [
                'valor' => null,
                'cumple' => null,
            ],
            'hembra' => [
                'valor' => null,
                'cumple' => null,
            ],
        ]);

        return $dataPorDia;
    }
}

if (!function_exists('sip_grafica_pesaje_promedio_from_pairs')) {
    /**
     * @param list<array{0:float,1:float}> $pairs [tpeso, tcantid]
     */
    function sip_grafica_pesaje_promedio_from_pairs(array $pairs): ?float
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

if (!function_exists('sip_grafica_pesaje_cv_from_pairs')) {
    /**
     * @param list<array{0:float,1:float}> $pairs [tpeso, tcantid]
     */
    function sip_grafica_pesaje_cv_from_pairs(array $pairs): ?float
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

if (!function_exists('sip_grafica_pesaje_galpon_variantes')) {
    /** @return list<string> */
    function sip_grafica_pesaje_galpon_variantes(string $galBind, string $galBind2): array
    {
        $out = [];
        foreach ([$galBind, $galBind2] as $g) {
            $g = trim($g);
            if ($g !== '' && !in_array($g, $out, true)) {
                $out[] = $g;
            }
        }
        $base = $galBind2 !== '' ? $galBind2 : $galBind;
        if ($base !== '' && ctype_digit($base)) {
            $pad2 = str_pad($base, 2, '0', STR_PAD_LEFT);
            if (!in_array($pad2, $out, true)) {
                $out[] = $pad2;
            }
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_codigos_movi_dia1')) {
    /** Códigos movi_zonas E001 para peso inicial M/H. */
    function sip_grafica_pesaje_codigos_movi_dia1(): array
    {
        return ['M' => 'P0001001', 'H' => 'P0001002'];
    }
}

if (!function_exists('sip_grafica_pesaje_peso_dia1_movi_zonas')) {
    /**
     * Peso día 1 (entrada E001) desde movi_zonas por sexo.
     *
     * @return array{m:?float,h:?float}
     */
    function sip_grafica_pesaje_peso_dia1_movi_zonas(
        mysqli $conn,
        string $granja3,
        string $campania3,
        string $galBind,
        string $galBind2
    ): array {
        $out = ['m' => null, 'h' => null];
        if (!sip_grafica_table_exists($conn, 'movi_zonas')) {
            return $out;
        }

        $tcencos = $granja3 . $campania3;
        $galVars = sip_grafica_pesaje_galpon_variantes($galBind, $galBind2);
        if ($galVars === []) {
            return $out;
        }

        $galPh = implode(',', array_fill(0, count($galVars), '?'));
        $sql = "SELECT
                    TRIM(a.`tcodigo`) AS tcodigo,
                    a.`tpeso`
                FROM `movi_zonas` a
                WHERE a.`tcodtra` = 'E001'
                  AND a.`tcencos` = ?
                  AND a.`tcodint` IN ({$galPh})
                  AND a.`tcodigo` IN ('P0001001', 'P0001002')
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tpeso` > 0
                ORDER BY a.`tfectra` ASC, a.`tnumfac` ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return $out;
        }
        $types = 's' . str_repeat('s', count($galVars));
        $params = array_merge([$tcencos], $galVars);
        $st->bind_param($types, ...$params);
        $st->execute();
        $rs = $st->get_result();

        $codigos = sip_grafica_pesaje_codigos_movi_dia1();
        while ($rs && ($r = $rs->fetch_assoc())) {
            $cod = strtoupper(trim((string) ($r['tcodigo'] ?? '')));
            $peso = (float) ($r['tpeso'] ?? 0);
            if ($peso <= 0 || !is_finite($peso)) {
                continue;
            }
            if ($cod === $codigos['M'] && $out['m'] === null) {
                $out['m'] = round($peso, 4);
            } elseif ($cod === $codigos['H'] && $out['h'] === null) {
                $out['h'] = round($peso, 4);
            }
            if ($out['m'] !== null && $out['h'] !== null) {
                break;
            }
        }
        $st->close();

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_linea_inyectar_peso_dia1_movi')) {
    /**
     * Mezcla peso día 1 (movi_zonas E001) en la serie por día.
     *
     * @param array<int, array{m:?float,h:?float,cvM:?float,cvH:?float}> $porTedad
     * @param array{m:?float,h:?float} $pesoDia1
     * @return array<int, array{m:?float,h:?float,cvM:?float,cvH:?float}>
     */
    function sip_grafica_pesaje_linea_inyectar_peso_dia1_movi(array $porTedad, array $pesoDia1): array
    {
        unset($porTedad[0], $porTedad[1]);

        if (($pesoDia1['m'] ?? null) === null && ($pesoDia1['h'] ?? null) === null) {
            ksort($porTedad, SORT_NUMERIC);

            return $porTedad;
        }

        $porTedad[1] = [
            'm' => $pesoDia1['m'],
            'h' => $pesoDia1['h'],
            'cvM' => null,
            'cvH' => null,
        ];

        ksort($porTedad, SORT_NUMERIC);

        return $porTedad;
    }
}

if (!function_exists('sip_grafica_pesaje_muestreo_por_tedad')) {
    /**
     * Pesos y CV por tedad registrada para un lote (granja/campaña/galpón).
     *
     * @return array<int, array{m:?float,h:?float,cvM:?float,cvH:?float}>
     */
    function sip_grafica_pesaje_muestreo_por_tedad(
        mysqli $conn,
        string $granja3,
        string $campania3,
        string $galBind,
        string $galBind2
    ): array {
        if (!sip_grafica_table_exists($conn, 'muestreoaves')) {
            return [];
        }

        $tcencos = $granja3 . $campania3;
        $sql = "SELECT
                    CAST(a.`tedad` AS SIGNED) AS tedad,
                    TRIM(a.`tsexo`) AS tsexo,
                    a.`tpeso`,
                    a.`tcantid`
                FROM `muestreoaves` a
                WHERE TRIM(a.`tcencos`) = ?
                  AND (
                        TRIM(a.`tcodint`) = ?
                        OR CAST(TRIM(a.`tcodint`) AS UNSIGNED) = CAST(? AS UNSIGNED)
                  )
                  AND CAST(a.`tedad` AS SIGNED) >= 2
                  AND a.`tpeso` IS NOT NULL
                  AND a.`tcantid` IS NOT NULL
                  AND a.`tcantid` > 0
                ORDER BY tedad ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('sss', $tcencos, $galBind, $galBind2);
        $st->execute();
        $rs = $st->get_result();

        /** @var array<int, array{M:list<array{0:float,1:float}>,H:list<array{0:float,1:float}>}> $by */
        $by = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $tedad = (int) ($r['tedad'] ?? 0);
            if ($tedad <= 1) {
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
            $dia = sip_grafica_pesaje_tedad_a_dia($tedad);
            if (!isset($by[$dia])) {
                $by[$dia] = ['M' => [], 'H' => []];
            }
            $by[$dia][$sexo][] = [$peso, $cant];
        }
        $st->close();

        $out = [];
        foreach ($by as $dia => $sexos) {
            $out[$dia] = [
                'm' => sip_grafica_pesaje_promedio_from_pairs($sexos['M']),
                'h' => sip_grafica_pesaje_promedio_from_pairs($sexos['H']),
                'cvM' => sip_grafica_pesaje_cv_from_pairs($sexos['M']),
                'cvH' => sip_grafica_pesaje_cv_from_pairs($sexos['H']),
            ];
        }
        ksort($out, SORT_NUMERIC);

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_linea')) {
    /**
     * Línea de pesaje por lote: todos los días registrados en muestreoaves (no san_fact).
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_pesaje_linea(mysqli $conn, array $opts): array
    {
        $cfg = sip_grafica_peso_cloro_cfg('pesaje_pollo');
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado: pesaje_pollo'];
        }

        $granja = trim((string) ($opts['granja'] ?? ''));
        $campania = trim((string) ($opts['campania'] ?? ''));
        $galpon = trim((string) ($opts['galpon'] ?? ''));
        if ($granja === '' || $campania === '' || $galpon === '') {
            return ['success' => false, 'message' => 'Faltan parametros: granja, campania, galpon'];
        }

        [$granja3, $campania3, $galBind, $galBind2] = sip_grafica_normalize_granja_campania($granja, $campania, $galpon);
        $tk = (string) $cfg['tareaKey'];
        $pkDefault = (string) $cfg['parametroKey'];
        $modoEval = (string) $cfg['modoEvaluacion'];
        $unidad = (string) $cfg['unidadDefault'];
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);

        $porTedad = sip_grafica_pesaje_muestreo_por_tedad($conn, $granja3, $campania3, $galBind, $galBind2);
        $pesoDia1Movi = sip_grafica_pesaje_peso_dia1_movi_zonas($conn, $granja3, $campania3, $galBind, $galBind2);
        $porTedad = sip_grafica_pesaje_linea_inyectar_peso_dia1_movi($porTedad, $pesoDia1Movi);
        if ($porTedad === []) {
            return ['success' => false, 'message' => 'Sin datos de pesaje para esa combinacion'];
        }

        $fechaInicio = sip_grafica_fecha_inicio_cab($conn, $granja3, $campania3, $galBind, $galBind2);

        $dataPorDia = [];
        foreach ($porTedad as $dia => $row) {
            [$sMin, $sMax, $uMap] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pkDefault, $dia);
            if ($uMap !== '—' && !preg_match('/^\d/', $uMap)) {
                $unidad = $uMap;
            }
            $refStd = $sMin ?? $sMax;
            $vm = $row['m'];
            $vh = $row['h'];
            $cvM = $row['cvM'];
            $cvH = $row['cvH'];
            $vmOk = ($vm !== null && !sip_grafica_is_nan($vm) && is_finite($vm));
            $vhOk = ($vh !== null && !sip_grafica_is_nan($vh) && is_finite($vh));
            $cvMOk = ($cvM !== null && is_finite($cvM));
            $cvHOk = ($cvH !== null && is_finite($cvH));

            $dataPorDia[] = [
                'dia' => $dia,
                'fecha' => sip_grafica_pesaje_fecha_por_tedad($fechaInicio, $dia),
                'estandar' => [
                    'min' => $sMin ?? $sMax,
                    'max' => $sMax ?? $sMin,
                ],
                'macho' => [
                    'valor' => $vmOk ? $vm : null,
                    'cv' => $cvMOk ? $cvM : null,
                    'cumple' => $vmOk ? sip_grafica_eval_range($vm, $refStd, null, 'solo_min') : null,
                ],
                'hembra' => [
                    'valor' => $vhOk ? $vh : null,
                    'cv' => $cvHOk ? $cvH : null,
                    'cumple' => $vhOk ? sip_grafica_eval_range($vh, $refStd, null, 'solo_min') : null,
                ],
            ];
        }

        $dataPorDia = sip_grafica_pesaje_linea_asegurar_dia_cero(
            $dataPorDia,
            $fechaInicio,
            $stdMap,
            $tk,
            $pkDefault
        );

        return [
            'success' => true,
            'fechaCarga' => $fechaInicio,
            'dataPorDia' => $dataPorDia,
            'unidad' => $unidad,
            'esMh' => true,
            'modoEvaluacion' => $modoEval,
            'tareaKey' => $tk,
            'tipo' => 'pesaje_pollo',
            'nombre' => (string) ($cfg['label'] ?? 'Pesaje de pollo'),
        ];
    }
}

if (!function_exists('sip_grafica_pesaje_dias_permitidos_tipo')) {
    /** @return list<int> */
    function sip_grafica_pesaje_dias_permitidos_tipo(string $tipo): array
    {
        $cat = sip_grafica_pesaje_dias_catalogo_build($tipo);
        if (empty($cat['success'])) {
            return [];
        }

        $out = [];
        foreach ($cat['dias'] as $item) {
            $out[] = (int) ($item['dia'] ?? 0);
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_dia_valido_tipo')) {
    function sip_grafica_pesaje_dia_valido_tipo(string $tipo, int $dia): bool
    {
        return in_array($dia, sip_grafica_pesaje_dias_permitidos_tipo($tipo), true);
    }
}

if (!function_exists('sip_grafica_pesaje_dias_build')) {
    /** @return array{success:bool,tipo:string,dias:list<array{dia:int,semana:?int}>}|array{success:bool,message:string} */
    function sip_grafica_pesaje_dias_build(string $tipo = 'pesaje_pollo'): array
    {
        return sip_grafica_pesaje_dias_catalogo_build($tipo);
    }
}

if (!function_exists('sip_grafica_pesaje_tedades_build')) {
    /** @deprecated Use sip_grafica_pesaje_dias_build() */
    function sip_grafica_pesaje_tedades_build(string $tipo = 'pesaje_pollo'): array
    {
        return sip_grafica_pesaje_dias_build($tipo);
    }
}
