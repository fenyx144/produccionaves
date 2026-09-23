<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';
require_once __DIR__ . '/sip_grafica_std_lib.php';
require_once __DIR__ . '/sip_grafica_pesaje_lib.php';
require_once __DIR__ . '/sip_grafica_ganancia_lib.php';

if (!function_exists('sip_grafica_pesaje_fact_cfg')) {
    /**
     * Configuración para lectura desde san_fact (formato diario del ETL 23).
     *
     * @return array{tareaKey:string,factParametroKey:string,stdParametroKey:string,label:string,unidad:string,modoEvaluacion:?string}|null
     */
    function sip_grafica_pesaje_fact_cfg(string $tipo): ?array
    {
        static $map = [
            'pesaje_pollo' => [
                'tareaKey' => 'CRIANZA_PESAJE_DE_POLLO',
                'factParametroKey' => 'PESAJE_POLLO_DIA',
                'stdParametroKey' => 'PESAJE_POLLO_SEMANA',
                'label' => 'Pesaje de pollo',
                'unidad' => 'Kg',
                'modoEvaluacion' => 'solo_min',
            ],
            'ganancia_peso' => [
                'tareaKey' => 'CRIANZA_GANANCIA_DE_PESO',
                'factParametroKey' => 'GANANCIA_PESO_DIA',
                'stdParametroKey' => 'GANANCIA_PESO_SEMANA',
                'label' => 'Ganancia de peso',
                'unidad' => 'g',
                'modoEvaluacion' => 'solo_min',
            ],
            'cv_peso' => [
                'tareaKey' => 'CRIANZA_PESAJE_DE_POLLO',
                'factParametroKey' => 'PESAJE_POLLO_DIA',
                'stdParametroKey' => 'PESAJE_POLLO_SEMANA',
                'label' => 'CV de pesaje de pollo',
                'unidad' => '%',
                'modoEvaluacion' => null,
            ],
            'cv_ganancia' => [
                'tareaKey' => 'CRIANZA_GANANCIA_DE_PESO',
                'factParametroKey' => 'GANANCIA_PESO_DIA',
                'stdParametroKey' => 'GANANCIA_PESO_SEMANA',
                'label' => 'CV de ganancia',
                'unidad' => '%',
                'modoEvaluacion' => null,
            ],
        ];

        return $map[strtolower(trim($tipo))] ?? null;
    }
}

if (!function_exists('sip_grafica_pesaje_fact_valor_json')) {
    /**
     * Decodifica `valor` de san_fact_historia_clinica_det (formato ETL 23).
     *
     * @return array{m:?float,h:?float,cvM:?float,cvH:?float}
     */
    function sip_grafica_pesaje_fact_valor_json($rawVal): array
    {
        $out = ['m' => null, 'h' => null, 'cvM' => null, 'cvH' => null];
        $dec = sip_grafica_valor_fact_export($rawVal);
        if (!is_array($dec)) {
            return $out;
        }
        if (isset($dec['current']) && is_array($dec['current'])) {
            $dec = $dec['current'];
        }
        $out['m'] = sip_grafica_to_num($dec['M'] ?? $dec['m'] ?? null);
        $out['h'] = sip_grafica_to_num($dec['H'] ?? $dec['h'] ?? null);
        $out['cvM'] = sip_grafica_to_num($dec['cvM'] ?? null);
        $out['cvH'] = sip_grafica_to_num($dec['cvH'] ?? null);

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_fact_por_dia')) {
    /**
     * Pesaje/ganancia/CV por día (edadDia) desde san_fact_historia_clinica_cab/det.
     *
     * @return array<int, array{m:?float,h:?float,cvM:?float,cvH:?float}>
     */
    function sip_grafica_pesaje_fact_por_dia(
        mysqli $conn,
        string $granja3,
        string $campania3,
        string $galBind,
        string $galBind2,
        string $tareaKey,
        string $parametroKey
    ): array {
        if (!sip_grafica_table_exists($conn, 'san_fact_historia_clinica_cab')
            || !sip_grafica_table_exists($conn, 'san_fact_historia_clinica_det')) {
            return [];
        }

        $sql = "SELECT d.`edadDia`, d.`valor`
                FROM `san_fact_historia_clinica_det` d
                INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                WHERE LPAD(LEFT(TRIM(c.`granja`),3),3,'0') = ?
                  AND TRIM(c.`campania`) = ?
                  AND (
                       TRIM(CAST(c.`galpon` AS CHAR)) = ?
                       OR CAST(TRIM(CAST(c.`galpon` AS CHAR)) AS UNSIGNED) = CAST(? AS UNSIGNED)
                  )
                  AND TRIM(d.`tareaKey`) = ?
                  AND TRIM(d.`parametroKey`) = ?
                ORDER BY CAST(d.`edadDia` AS UNSIGNED) ASC, d.`id` ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        $st->bind_param('ssssss', $granja3, $campania3, $galBind, $galBind2, $tareaKey, $parametroKey);
        $st->execute();
        $rs = $st->get_result();

        $out = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $dia = (int) ($r['edadDia'] ?? 0);
            if ($dia <= 0) {
                continue;
            }
            $v = sip_grafica_pesaje_fact_valor_json($r['valor'] ?? null);
            if ($v['m'] === null && $v['h'] === null && $v['cvM'] === null && $v['cvH'] === null) {
                continue;
            }
            $out[$dia] = $v;
        }
        $st->close();

        ksort($out, SORT_NUMERIC);

        return $out;
    }
}

if (!function_exists('sip_grafica_pesaje_fact_contexto')) {
    /**
     * Valida parámetros y prepara el contexto común de lectura desde fact.
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array{cfg:array,granja3:string,campania3:string,galBind:string,galBind2:string,fechaInicio:?string,porDia:array<int,array<string,mixed>>}|array{error:string}
     */
    function sip_grafica_pesaje_fact_contexto(mysqli $conn, array $opts, string $tipo): array
    {
        $cfg = sip_grafica_pesaje_fact_cfg($tipo);
        if ($cfg === null) {
            return ['error' => 'Tipo no soportado: ' . $tipo];
        }

        $granja = trim((string) ($opts['granja'] ?? ''));
        $campania = trim((string) ($opts['campania'] ?? ''));
        $galpon = trim((string) ($opts['galpon'] ?? ''));
        if ($granja === '' || $campania === '' || $galpon === '') {
            return ['error' => 'Faltan parametros: granja, campania, galpon'];
        }

        [$granja3, $campania3, $galBind, $galBind2] = sip_grafica_normalize_granja_campania($granja, $campania, $galpon);

        $porDia = sip_grafica_pesaje_fact_por_dia(
            $conn,
            $granja3,
            $campania3,
            $galBind,
            $galBind2,
            (string) $cfg['tareaKey'],
            (string) $cfg['factParametroKey']
        );

        return [
            'cfg' => $cfg,
            'granja3' => $granja3,
            'campania3' => $campania3,
            'galBind' => $galBind,
            'galBind2' => $galBind2,
            'fechaInicio' => sip_grafica_fecha_inicio_cab($conn, $granja3, $campania3, $galBind, $galBind2),
            'porDia' => $porDia,
        ];
    }
}

if (!function_exists('sip_grafica_pesaje_fact_linea')) {
    /**
     * Línea de pesaje de pollo desde san_fact (formato diario del ETL 23).
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_pesaje_fact_linea(mysqli $conn, array $opts): array
    {
        $ctx = sip_grafica_pesaje_fact_contexto($conn, $opts, 'pesaje_pollo');
        if (isset($ctx['error'])) {
            return ['success' => false, 'message' => $ctx['error']];
        }
        $porDia = $ctx['porDia'];
        if ($porDia === []) {
            return ['success' => false, 'message' => 'Sin datos de pesaje (fact) para esa combinacion'];
        }

        $cfg = $ctx['cfg'];
        $tk = (string) $cfg['tareaKey'];
        $stdPk = (string) $cfg['stdParametroKey'];
        $modoEval = (string) $cfg['modoEvaluacion'];
        $unidad = (string) $cfg['unidad'];
        $fechaInicio = $ctx['fechaInicio'];
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);

        $dataPorDia = [];
        foreach ($porDia as $dia => $row) {
            [$sMin, $sMax, $uMap] = sip_grafica_std_rango_desde_map($stdMap, $tk, $stdPk, $dia);
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

        return [
            'success' => true,
            'fechaCarga' => $fechaInicio,
            'dataPorDia' => $dataPorDia,
            'unidad' => $unidad,
            'esMh' => true,
            'modoEvaluacion' => $modoEval,
            'tareaKey' => $tk,
            'tipo' => 'pesaje_pollo',
            'origen' => 'fact',
            'nombre' => (string) $cfg['label'],
        ];
    }
}

if (!function_exists('sip_grafica_ganancia_fact_linea')) {
    /**
     * Línea de ganancia de peso desde san_fact (formato diario del ETL 23).
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_ganancia_fact_linea(mysqli $conn, array $opts): array
    {
        $ctx = sip_grafica_pesaje_fact_contexto($conn, $opts, 'ganancia_peso');
        if (isset($ctx['error'])) {
            return ['success' => false, 'message' => $ctx['error']];
        }
        $porDia = $ctx['porDia'];
        if ($porDia === []) {
            return ['success' => false, 'message' => 'Sin datos de ganancia (fact) para esa combinacion'];
        }

        $cfg = $ctx['cfg'];
        $tk = (string) $cfg['tareaKey'];
        $stdPk = (string) $cfg['stdParametroKey'];
        $modoEval = (string) $cfg['modoEvaluacion'];
        $unidad = (string) $cfg['unidad'];
        $fechaInicio = $ctx['fechaInicio'];
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);

        $dataPorDia = [];
        foreach ($porDia as $dia => $row) {
            $semCat = sip_grafica_pesaje_dia_semana_catalogo($dia);
            $edadStd = sip_grafica_ganancia_std_edad($dia, $dia);
            [$sMin, $sMax, $uMap] = sip_grafica_std_rango_desde_map($stdMap, $tk, $stdPk, $edadStd);
            if ($uMap !== '—' && !preg_match('/^\d/', $uMap)) {
                $unidad = $uMap;
            }
            $refStd = $sMin ?? $sMax;
            $vm = $row['m'];
            $vh = $row['h'];
            $cvM = $row['cvM'];
            $cvH = $row['cvH'];
            $vmOk = ($vm !== null && is_finite($vm));
            $vhOk = ($vh !== null && is_finite($vh));
            $cvMOk = ($cvM !== null && is_finite($cvM));
            $cvHOk = ($cvH !== null && is_finite($cvH));

            $dataPorDia[] = [
                'dia' => $dia,
                'semana' => $semCat,
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

        return [
            'success' => true,
            'fechaCarga' => $fechaInicio,
            'dataPorDia' => $dataPorDia,
            'unidad' => $unidad,
            'esMh' => true,
            'modoEvaluacion' => $modoEval,
            'tareaKey' => $tk,
            'tipo' => 'ganancia_peso',
            'origen' => 'fact',
            'nombre' => (string) $cfg['label'],
        ];
    }
}

if (!function_exists('sip_grafica_cv_fact_linea')) {
    /**
     * Línea de CV (pesaje o ganancia) desde san_fact (formato diario del ETL 23).
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_cv_fact_linea(mysqli $conn, array $opts, string $tipo): array
    {
        $tipoNorm = strtolower(trim($tipo));
        $ctx = sip_grafica_pesaje_fact_contexto($conn, $opts, $tipoNorm);
        if (isset($ctx['error'])) {
            return ['success' => false, 'message' => $ctx['error']];
        }
        $porDia = $ctx['porDia'];
        if ($porDia === []) {
            return ['success' => false, 'message' => 'Sin CV (fact) para esa combinacion'];
        }

        $cfg = $ctx['cfg'];
        $fechaInicio = $ctx['fechaInicio'];

        $dataPorDia = [];
        foreach ($porDia as $dia => $row) {
            $cvM = $row['cvM'];
            $cvH = $row['cvH'];
            $cvMOk = ($cvM !== null && is_finite($cvM));
            $cvHOk = ($cvH !== null && is_finite($cvH));
            if (!$cvMOk && !$cvHOk) {
                continue;
            }

            $dataPorDia[] = [
                'dia' => $dia,
                'semana' => sip_grafica_pesaje_dia_semana_catalogo($dia),
                'fecha' => sip_grafica_pesaje_fecha_por_tedad($fechaInicio, $dia),
                'macho' => [
                    'valor' => $cvMOk ? $cvM : null,
                    'cumple' => null,
                ],
                'hembra' => [
                    'valor' => $cvHOk ? $cvH : null,
                    'cumple' => null,
                ],
            ];
        }

        if ($dataPorDia === []) {
            return ['success' => false, 'message' => 'Sin CV (fact) calculable para esa combinacion'];
        }

        return [
            'success' => true,
            'fechaCarga' => $fechaInicio,
            'dataPorDia' => $dataPorDia,
            'unidad' => (string) $cfg['unidad'],
            'esMh' => true,
            'modoEvaluacion' => null,
            'tipo' => $tipoNorm,
            'origen' => 'fact',
            'nombre' => (string) $cfg['label'],
        ];
    }
}
