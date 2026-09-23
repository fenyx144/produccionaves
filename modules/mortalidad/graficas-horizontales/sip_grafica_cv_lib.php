<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_pesaje_lib.php';
require_once __DIR__ . '/sip_grafica_acum_lote_live_lib.php';

if (!function_exists('sip_grafica_cv_tipos')) {
    /** @return list<string> */
    function sip_grafica_cv_tipos(): array
    {
        return ['cv_peso', 'cv_ganancia'];
    }
}

if (!function_exists('sip_grafica_es_cv')) {
    function sip_grafica_es_cv(string $tipo): bool
    {
        return in_array(strtolower(trim($tipo)), sip_grafica_cv_tipos(), true);
    }
}

if (!function_exists('sip_grafica_cv_cfg')) {
    /**
     * @return array{label:string,unidad:string}|null
     */
    function sip_grafica_cv_cfg(string $tipo): ?array
    {
        static $map = [
            'cv_peso' => [
                'label' => 'CV de pesaje de pollo',
                'unidad' => '%',
            ],
            'cv_ganancia' => [
                'label' => 'CV de ganancia',
                'unidad' => '%',
            ],
        ];

        return $map[strtolower(trim($tipo))] ?? null;
    }
}

if (!function_exists('sip_grafica_cv_linea')) {
    /**
     * Línea de CV por lote: todos los días registrados en muestreoaves.
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_cv_linea(mysqli $conn, array $opts, string $tipo): array
    {
        $tipoNorm = strtolower(trim($tipo));
        $cfg = sip_grafica_cv_cfg($tipoNorm);
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado: ' . $tipo];
        }

        $granja = trim((string) ($opts['granja'] ?? ''));
        $campania = trim((string) ($opts['campania'] ?? ''));
        $galpon = trim((string) ($opts['galpon'] ?? ''));
        if ($granja === '' || $campania === '' || $galpon === '') {
            return ['success' => false, 'message' => 'Faltan parametros: granja, campania, galpon'];
        }

        [$granja3, $campania3, $galBind, $galBind2] = sip_grafica_normalize_granja_campania($granja, $campania, $galpon);

        $porTedad = sip_grafica_pesaje_muestreo_por_tedad($conn, $granja3, $campania3, $galBind, $galBind2);
        if ($porTedad === []) {
            return ['success' => false, 'message' => 'Sin datos de muestreo para esa combinacion'];
        }

        $fechaInicio = sip_grafica_fecha_inicio_cab($conn, $granja3, $campania3, $galBind, $galBind2);

        $dataPorDia = [];

        if ($tipoNorm === 'cv_ganancia') {
            foreach (sip_grafica_pesaje_tedades_canonicas() as $tedad) {
                if ($tedad <= 0) {
                    continue;
                }
                $diaApi = sip_grafica_pesaje_tedad_a_dia_api($tedad);
                $row = $porTedad[$tedad] ?? $porTedad[sip_grafica_pesaje_tedad_a_dia($tedad)] ?? null;
                if ($row === null) {
                    continue;
                }
                $cvM = $row['cvM'];
                $cvH = $row['cvH'];
                $cvMOk = ($cvM !== null && is_finite($cvM));
                $cvHOk = ($cvH !== null && is_finite($cvH));
                if (!$cvMOk && !$cvHOk) {
                    continue;
                }
                $dataPorDia[] = [
                    'dia' => $diaApi,
                    'semana' => sip_grafica_pesaje_dia_semana_catalogo($diaApi),
                    'fecha' => sip_grafica_pesaje_fecha_por_tedad($fechaInicio, $tedad),
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
        } else {
            foreach ($porTedad as $dia => $row) {
                $cvM = $row['cvM'];
                $cvH = $row['cvH'];
                $cvMOk = ($cvM !== null && is_finite($cvM));
                $cvHOk = ($cvH !== null && is_finite($cvH));

                $diaApi = $dia <= 0 ? 1 : (int) $dia;
                $dataPorDia[] = [
                    'dia' => $diaApi,
                    'semana' => sip_grafica_pesaje_dia_semana_catalogo($diaApi),
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

            $dataPorDia = sip_grafica_cv_linea_asegurar_dia_cero($dataPorDia, $fechaInicio);
        }

        if ($dataPorDia === []) {
            return ['success' => false, 'message' => 'Sin CV calculable para esa combinacion'];
        }

        return [
            'success' => true,
            'fechaCarga' => $fechaInicio,
            'dataPorDia' => $dataPorDia,
            'unidad' => (string) $cfg['unidad'],
            'esMh' => true,
            'modoEvaluacion' => null,
            'tipo' => $tipoNorm,
            'nombre' => (string) $cfg['label'],
        ];
    }
}

if (!function_exists('sip_grafica_acum_cv_build')) {
    /**
     * Acumulado horizontal de CV por tedad canónica (muestreoaves).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    function sip_grafica_acum_cv_build(mysqli $conn, array $opts, string $tipo): array
    {
        $tipoNorm = strtolower(trim($tipo));
        $cfg = sip_grafica_cv_cfg($tipoNorm);
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado: ' . $tipo];
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
            return ['success' => false, 'message' => 'Tabla muestreoaves no disponible'];
        }

        $dia = (int) ($opts['dia'] ?? 1);
        $tedad = (int) ($opts['tedad'] ?? sip_grafica_pesaje_dia_api_a_tedad($dia));
        $diasPermitidos = sip_grafica_pesaje_dias_permitidos_tipo($tipoNorm);
        if ($diasPermitidos === [] || !sip_grafica_pesaje_dia_valido_tipo($tipoNorm, $dia)) {
            return [
                'success' => false,
                'message' => 'Parametro dia invalido. Valores permitidos: ' . implode(', ', $diasPermitidos),
            ];
        }

        $combos = sip_grafica_acum_pesaje_combos_periodo($conn, $fechaInicio, $fechaFin, $tedad);
        $aggPorCombo = sip_grafica_acum_muestreo_agg_por_combo($conn, $tedad, $fechaInicio, $fechaFin);
        $semCat = sip_grafica_pesaje_dia_semana_catalogo($dia);

        $registros = [];
        foreach ($combos as $combo) {
            $key = $combo['key'];
            $row = $aggPorCombo[$key] ?? ['cvM' => null, 'cvH' => null];
            $fechaCarga = $combo['fechaCarga'] !== '' ? $combo['fechaCarga'] : null;
            $fechaPesaje = sip_grafica_acum_fecha_por_edad($combo['fechaInicio'], $tedad);
            $registros[] = [
                'granja' => $combo['granja'],
                'granjaNombre' => sip_grafica_acum_nombre_granja($combo['granja']),
                'campania' => $combo['campania'],
                'galpon' => $combo['galpon'],
                'fechaCarga' => $fechaCarga,
                'fechaSemana' => $fechaPesaje,
                'valorM' => $row['cvM'],
                'valorH' => $row['cvH'],
                'dia' => $dia,
                'semana' => $semCat,
            ];
        }

        return [
            'success' => true,
            'tipo' => $tipoNorm,
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

if (!function_exists('sip_grafica_acum_cv_ganancia_build')) {
    /**
     * CV de ganancia horizontal por día canónico (muestreoaves).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    function sip_grafica_acum_cv_ganancia_build(mysqli $conn, array $opts): array
    {
        return sip_grafica_acum_cv_build($conn, $opts, 'cv_ganancia');
    }
}
