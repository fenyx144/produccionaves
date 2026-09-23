<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_acumulado_semanal_lib.php';
require_once __DIR__ . '/../../../core/lib/sip_estandares_tree_repository.php';

if (!function_exists('sip_grafica_liq_tarea_key')) {
    function sip_grafica_liq_tarea_key(): string
    {
        return 'PARAMETROS_LIQUIDACION';
    }
}

if (!function_exists('sip_grafica_liq_tipo_a_parametro')) {
    function sip_grafica_liq_tipo_a_parametro(string $tipo): ?string
    {
        $map = [
            'ica_2_4' => 'ICA_2_4',
            'iep_2_4' => 'IEP_2_4',
        ];
        $t = strtolower(trim($tipo));

        return $map[$t] ?? null;
    }
}

if (!function_exists('sip_grafica_liq_norm_granja')) {
    function sip_grafica_liq_norm_granja(string $granja): string
    {
        return substr(str_pad(trim($granja), 3, '0', STR_PAD_LEFT), 0, 3);
    }
}

if (!function_exists('sip_grafica_liq_es_granja_6')) {
    function sip_grafica_liq_es_granja_6(string $granja3): bool
    {
        $g = sip_grafica_liq_norm_granja($granja3);

        return $g !== '' && $g[0] === '6';
    }
}

if (!function_exists('sip_grafica_liq_parse_float')) {
    function sip_grafica_liq_parse_float($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if ($s === '' || $s === '—' || strcasecmp($s, 'null') === 0) {
            return null;
        }
        $s = str_replace(',', '.', preg_replace('/[^\d.,\-]/', '', $s) ?? '');
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}

if (!function_exists('sip_grafica_liq_decode_valor_celdas')) {
    /** @return list<string> */
    function sip_grafica_liq_decode_valor_celdas(?string $valorJson): array
    {
        if ($valorJson === null || trim($valorJson) === '') {
            return [];
        }
        $dec = json_decode($valorJson, true);
        if (!is_array($dec)) {
            return [];
        }
        $payload = isset($dec['current']) && is_array($dec['current']) ? $dec['current'] : $dec;
        if (isset($payload['m']) && is_array($payload['m'])) {
            return array_values(array_map(static function ($v): string {
                return trim((string) $v);
            }, $payload['m']));
        }
        if (is_array($payload) && ($payload === [] || array_keys($payload) === range(0, count($payload) - 1))) {
            return array_values(array_map(static function ($v): string {
                return is_scalar($v) ? trim((string) $v) : '—';
            }, $payload));
        }

        return [];
    }
}

if (!function_exists('sip_grafica_liq_valor_desde_det')) {
    function sip_grafica_liq_valor_desde_det(string $valRaw): ?float
    {
        $valRaw = trim($valRaw);
        if ($valRaw === '') {
            return null;
        }
        if (($valRaw[0] ?? '') === '{' || ($valRaw[0] ?? '') === '[') {
            $cells = sip_grafica_liq_decode_valor_celdas($valRaw);
            if ($cells !== []) {
                return sip_grafica_liq_parse_float($cells[0]);
            }

            return null;
        }

        return sip_grafica_liq_parse_float($valRaw);
    }
}

if (!function_exists('sip_grafica_liquidacion_horizontal_build')) {
    /**
     * Barras horizontales IEP/ICA por galpón al cierre de campaña (fecha_fin).
     *
     * @param array{tipo:string,fechaInicio:string,fechaFin:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_liquidacion_horizontal_build(mysqli $conn, array $opts): array
    {
        $tipo = strtolower(trim((string) ($opts['tipo'] ?? '')));
        $pk = sip_grafica_liq_tipo_a_parametro($tipo);
        if ($pk === null) {
            return ['success' => false, 'message' => 'Tipo liquidacion no soportado: ' . $tipo];
        }

        $labels = [
            'ica_2_4' => 'ICA 2.4',
            'iep_2_4' => 'IEP 2.4',
        ];
        $nombre = $labels[$tipo] ?? strtoupper($tipo);

        $fechaInicio = trim((string) ($opts['fechaInicio'] ?? ''));
        $fechaFin = trim((string) ($opts['fechaFin'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
            return ['success' => false, 'message' => 'Faltan o son invalidos fechaInicio/fechaFin (YYYY-MM-DD)'];
        }
        if ($fechaInicio > $fechaFin) {
            [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
        }

        if (!sip_est2_repo_table_exists($conn, 'san_fact_historia_clinica_cab')
            || !sip_est2_repo_table_exists($conn, 'san_fact_historia_clinica_det')) {
            return ['success' => false, 'message' => 'Tablas san_fact no disponibles'];
        }

        $tk = sip_grafica_liq_tarea_key();
        $sql = 'SELECT TRIM(c.`granja`) AS granja, TRIM(c.`campania`) AS campania,'
            . ' TRIM(CAST(c.`galpon` AS CHAR)) AS galpon,'
            . ' DATE(c.`fecha_fin`) AS fecha_fin, TRIM(COALESCE(d.`valor`, \'\')) AS val'
            . ' FROM `san_fact_historia_clinica_cab` c'
            . ' INNER JOIN `san_fact_historia_clinica_det` d ON d.`cabId` = c.`id`'
            . ' WHERE TRIM(d.`tareaKey`) = ? AND TRIM(d.`parametroKey`) = ?'
            . ' AND c.`fecha_fin` IS NOT NULL AND TRIM(c.`fecha_fin`) <> \'\' AND c.`fecha_fin` <> \'0000-00-00\''
            . ' AND DATE(c.`fecha_fin`) >= ? AND DATE(c.`fecha_fin`) <= ?'
            . ' AND TRIM(CAST(c.`galpon` AS CHAR)) <> \'\' AND TRIM(CAST(c.`galpon` AS CHAR)) <> \'0\''
            . ' ORDER BY c.`fecha_fin`, c.`granja`, c.`campania`, c.`galpon`';

        $st = $conn->prepare($sql);
        if (!$st) {
            return ['success' => false, 'message' => 'Error preparando consulta liquidacion'];
        }
        $st->bind_param('ssss', $tk, $pk, $fechaInicio, $fechaFin);
        $st->execute();
        $rs = $st->get_result();

        $registros = [];
        while ($rs && ($row = $rs->fetch_assoc())) {
            $granja = sip_grafica_liq_norm_granja((string) ($row['granja'] ?? ''));
            if (!sip_grafica_liq_es_granja_6($granja)) {
                continue;
            }
            $campania = trim((string) ($row['campania'] ?? ''));
            $galpon = sip_grafica_acum_galpon_norm($row['galpon'] ?? '');
            if ($campania === '' || $galpon === '') {
                continue;
            }
            $valor = sip_grafica_liq_valor_desde_det((string) ($row['val'] ?? ''));
            if ($valor === null) {
                continue;
            }
            $fechaFinRow = trim((string) ($row['fecha_fin'] ?? ''));
            $registros[] = [
                'granja' => $granja,
                'granjaNombre' => sip_grafica_acum_nombre_granja($granja),
                'campania' => $campania,
                'galpon' => $galpon,
                'fechaCarga' => $fechaFinRow !== '' ? $fechaFinRow : null,
                'fechaSemana' => $fechaFinRow !== '' ? $fechaFinRow : null,
                'valorM' => round($valor, 4),
                'valorH' => round($valor, 4),
                'estandarM' => null,
                'estandarH' => null,
            ];
        }
        $st->close();

        return [
            'success' => true,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'esLiquidacion' => true,
            'periodo' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
            'unidad' => '',
            'totalRegistros' => count($registros),
            'registros' => $registros,
        ];
    }
}
