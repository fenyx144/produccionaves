<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';
require_once __DIR__ . '/sip_grafica_std_lib.php';
require_once __DIR__ . '/sip_grafica_pesaje_lib.php';
require_once __DIR__ . '/sip_grafica_ganancia_lib.php';
require_once __DIR__ . '/sip_grafica_acumulado_semanal_lib.php';
require_once __DIR__ . '/sip_grafica_pesaje_fact_lib.php';

if (!function_exists('sip_grafica_acum_fact_pesaje_fetch')) {
    /**
     * Filas fact (PESAJE_POLLO_DIA / GANANCIA_PESO_DIA) por combo y día.
     *
     * @return array{combos:list<array<string,mixed>>,agg:array<string,array<int,array{m:?float,h:?float,cvM:?float,cvH:?float}>>}
     */
    function sip_grafica_acum_fact_pesaje_fetch(
        mysqli $conn,
        string $tareaKey,
        string $parametroKey,
        string $desde,
        string $hasta
    ): array {
        if (!sip_grafica_table_exists($conn, 'san_fact_historia_clinica_cab')
            || !sip_grafica_table_exists($conn, 'san_fact_historia_clinica_det')) {
            return ['combos' => [], 'agg' => []];
        }

        $sql = "SELECT
                    LPAD(LEFT(TRIM(c.`granja`),3),3,'0') AS granja3,
                    TRIM(c.`campania`) AS campania,
                    TRIM(CAST(c.`galpon` AS CHAR)) AS galpon,
                    DATE(c.`fecha_inicio`) AS fechaInicio,
                    d.`edadDia`,
                    d.`valor`
                FROM `san_fact_historia_clinica_det` d
                INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                WHERE d.`tareaKey` = ?
                  AND d.`parametroKey` = ?
                  AND LPAD(LEFT(TRIM(c.`granja`),3),3,'0') NOT IN ('624','640','641')
                  AND c.`fecha_inicio` IS NOT NULL AND TRIM(c.`fecha_inicio`) <> ''
                  AND c.`fecha_inicio` <> '0000-00-00'
                  AND c.`granja` IS NOT NULL AND TRIM(c.`granja`) <> ''
                  AND TRIM(c.`campania`) <> '' AND TRIM(c.`campania`) <> '000'
                  AND DATE_ADD(DATE(c.`fecha_inicio`), INTERVAL (CAST(d.`edadDia` AS SIGNED) - 1) DAY) BETWEEN ? AND ?
                ORDER BY c.`granja` ASC, c.`campania` ASC, c.`galpon` ASC,
                         CAST(d.`edadDia` AS SIGNED) ASC, d.`id` ASC";

        $st = $conn->prepare($sql);
        if (!$st) {
            return ['combos' => [], 'agg' => []];
        }
        $st->bind_param('ssss', $tareaKey, $parametroKey, $desde, $hasta);
        $st->execute();
        $rs = $st->get_result();

        $fechaCargaMap = sip_grafica_acum_fecha_carga_map($conn);
        $combosByKey = [];
        $agg = [];
        while ($rs && ($r = $rs->fetch_assoc())) {
            $g = trim((string) ($r['granja3'] ?? ''));
            $c = trim((string) ($r['campania'] ?? ''));
            $gp = trim((string) ($r['galpon'] ?? ''));
            $fi = trim((string) ($r['fechaInicio'] ?? ''));
            $dia = (int) ($r['edadDia'] ?? 0);
            if ($g === '' || $c === '' || $gp === '' || $fi === '' || $fi === '0000-00-00' || $dia <= 0) {
                continue;
            }
            $key = sip_grafica_acum_combo_key($g, $c, $gp);
            if (!isset($combosByKey[$key])) {
                $fProj = $fechaCargaMap[$key] ?? '';
                $combosByKey[$key] = [
                    'key' => $key,
                    'granja' => $g,
                    'campania' => $c,
                    'galpon' => sip_grafica_acum_galpon_norm($gp),
                    'fechaInicio' => $fi,
                    'fechaCarga' => $fProj !== '' ? $fProj : $fi,
                ];
            }
            $v = sip_grafica_pesaje_fact_valor_json($r['valor'] ?? null);
            if ($v['m'] === null && $v['h'] === null && $v['cvM'] === null && $v['cvH'] === null) {
                continue;
            }
            $agg[$key][$dia] = $v;
        }
        $st->close();

        $combos = array_values($combosByKey);
        usort($combos, static function (array $a, array $b): int {
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

        return ['combos' => $combos, 'agg' => $agg];
    }
}

if (!function_exists('sip_grafica_acum_fact_pesaje_ganancia_build')) {
    /**
     * Pesaje/ganancia/CV horizontal desde san_fact (formato diario del ETL 23).
     * Maneja multi-día (`dias`) y día único (`dia`).
     *
     * @param array<string,mixed> $opts tipo, fechaInicio, fechaFin, dias, dia
     * @return array<string,mixed>
     */
    function sip_grafica_acum_fact_pesaje_ganancia_build(mysqli $conn, array $opts): array
    {
        $tipo = strtolower(trim((string) ($opts['tipo'] ?? '')));
        $factCfg = sip_grafica_pesaje_fact_cfg($tipo);
        if ($factCfg === null) {
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

        $dias = [];
        foreach ((array) ($opts['dias'] ?? []) as $d) {
            $di = (int) $d;
            if ($di > 0) {
                $dias[$di] = $di;
            }
        }
        if ($dias === []) {
            $dia = (int) ($opts['dia'] ?? 0);
            if ($dia > 0) {
                $dias[$dia] = $dia;
            }
        }
        if ($dias === []) {
            foreach (sip_grafica_pesaje_dias_permitidos_tipo($tipo) as $di) {
                $dias[$di] = $di;
            }
        }
        $dias = array_values($dias);
        sort($dias, SORT_NUMERIC);

        $tk = (string) $factCfg['tareaKey'];
        $factPk = (string) $factCfg['factParametroKey'];
        $fetched = sip_grafica_acum_fact_pesaje_fetch($conn, $tk, $factPk, $fechaInicio, $fechaFin);
        $combos = $fetched['combos'];
        $agg = $fetched['agg'];

        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);
        $cfgPeso = sip_grafica_acum_cfg_tipo('pesaje_pollo');
        $cfgGan = sip_grafica_peso_cloro_cfg('ganancia_peso');

        $items = [];
        foreach ($dias as $dia) {
            $semCat = sip_grafica_pesaje_dia_semana_catalogo($dia);

            $std = ['m' => null, 'h' => null];
            if ($tipo === 'pesaje_pollo' && $cfgPeso !== null) {
                $std = sip_grafica_acum_pesaje_std($dia, $cfgPeso, $stdMap);
            } elseif ($tipo === 'ganancia_peso' && $cfgGan !== null) {
                $edadStd = sip_grafica_ganancia_std_edad($dia, $dia);
                [$sMin, $sMax] = sip_grafica_std_rango_desde_map(
                    $stdMap,
                    (string) $cfgGan['tareaKey'],
                    (string) $cfgGan['parametroKey'],
                    $edadStd
                );
                $std = ['m' => $sMin ?? $sMax, 'h' => $sMax ?? $sMin];
            }

            $registros = [];
            foreach ($combos as $combo) {
                $row = $agg[$combo['key']][$dia] ?? null;
                if ($row === null) {
                    continue;
                }
                if ($tipo === 'cv_peso' || $tipo === 'cv_ganancia') {
                    $valM = $row['cvM'];
                    $valH = $row['cvH'];
                    $cvM = null;
                    $cvH = null;
                    if ($valM === null && $valH === null) {
                        continue;
                    }
                } else {
                    $valM = $row['m'];
                    $valH = $row['h'];
                    $cvM = $row['cvM'];
                    $cvH = $row['cvH'];
                    if ($valM === null && $valH === null) {
                        continue;
                    }
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
                    'estandarM' => $std['m'],
                    'estandarH' => $std['h'],
                    'cvM' => $cvM,
                    'cvH' => $cvH,
                    'dia' => $dia,
                    'semana' => $semCat,
                ];
            }

            $items[] = [
                'success' => true,
                'tipo' => $tipo,
                'nombre' => (string) ($factCfg['label'] ?? $tipo),
                'dia' => $dia,
                'semana' => $semCat,
                'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
                'unidad' => (string) ($factCfg['unidad'] ?? ''),
                'totalRegistros' => count($registros),
                'registros' => $registros,
            ];
        }

        return [
            'success' => true,
            'tipo' => $tipo,
            'nombre' => (string) ($factCfg['label'] ?? $tipo),
            'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
            'items' => $items,
        ];
    }
}
