<?php

declare(strict_types=1);

/**
 * Librería de gráficas de mortalidad.
 *
 * Fuente de datos:
 *   - movi_zonas  : movimientos por día (tcodtra 'S808' => mortalidad, 'S700' => venta).
 *   - maes_zonas  : fecha de ingreso del lote (fec_ing) para calcular el "día de campaña".
 *
 * La unidad de gráfica es el LOTE (granja + campaña + galpón). Cada lote
 * genera su propia serie diaria desde el día 1, replicando el comportamiento
 * de la gráfica de mortalidad de sanidad (sip_grafica_mortalidad.php) pero
 * con mortalidad en barras (S808) y venta en línea (S700).
 */

if (!function_exists('mort_graficas_codigos_operacion')) {
    /**
     * Códigos de operación consultados en movi_zonas (tcodtra).
     *
     * @return array<string, array{codigo: string, label: string}>
     */
    function mort_graficas_codigos_operacion(): array
    {
        return [
            'mortalidad' => ['codigo' => 'S808', 'label' => 'Mortalidad'],
            'venta' => ['codigo' => 'S700', 'label' => 'Venta'],
        ];
    }
}

if (!function_exists('mort_graficas_filtros_defecto')) {
    /**
     * @return array<string, mixed>
     */
    function mort_graficas_filtros_defecto(): array
    {
        return [
            'periodoTipo' => 'TODOS',
            'fechaUnica' => '',
            'fechaInicio' => '',
            'fechaFin' => '',
            'mesUnico' => '',
            'mesInicio' => date('Y-01'),
            'mesFin' => date('Y-12'),
            'operacion' => 'todos', // todos | mortalidad | venta
        ];
    }
}

if (!function_exists('mort_graficas_parse_filtros')) {
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    function mort_graficas_parse_filtros(array $input): array
    {
        $f = mort_graficas_filtros_defecto();
        foreach (array_keys($f) as $k) {
            if (!array_key_exists($k, $input)) {
                continue;
            }
            $val = $input[$k];
            if (is_array($val)) {
                $f[$k] = '';
                continue;
            }
            $f[$k] = trim((string) $val);
        }

        $f['operacion'] = strtolower(trim((string) ($f['operacion'] ?? 'todos')));
        if (!in_array($f['operacion'], ['todos', 'mortalidad', 'venta'], true)) {
            $f['operacion'] = 'todos';
        }

        return $f;
    }
}

if (!function_exists('mort_graficas_rango_desde_filtros')) {
    /**
     * Resuelve el rango de fechas desde los filtros de periodo.
     *
     * @param array<string, mixed> $filtros
     * @return array{desde: string, hasta: string}|null
     */
    function mort_graficas_rango_desde_filtros(array $filtros): ?array
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
}

if (!function_exists('mort_graficas_parse_lotes')) {
    /**
     * Parsea la lista de lotes enviada por el cliente (JSON).
     *
     * Formato esperado: [{"granja":"621","campania":"192","galpones":["1","2"],"sexo":"macho"}, ...]
     * Si galpones viene vacío => se consideran todos los galpones del cenco.
     * sexo: todos | macho | hembra (opcional, por defecto 'todos').
     *
     * @param array<string, mixed> $input
     * @return list<array{granja: string, campania: string, galpones: list<string>, sexo: string}>
     */
    function mort_graficas_parse_lotes(array $input): array
    {
        $raw = $input['lotes'] ?? '';
        if (is_array($raw)) {
            $raw = (string) json_encode($raw, JSON_UNESCAPED_UNICODE);
        } else {
            $raw = trim((string) $raw);
        }
        if ($raw === '') {
            return [];
        }

        $dec = json_decode($raw, true);
        if (!is_array($dec)) {
            return [];
        }

        $lotes = [];
        foreach ($dec as $item) {
            if (!is_array($item)) {
                continue;
            }
            $gDigits = preg_replace('/\D/', '', (string) ($item['granja'] ?? '')) ?? '';
            $cDigits = preg_replace('/\D/', '', (string) ($item['campania'] ?? '')) ?? '';
            if ($gDigits === '' || $cDigits === '') {
                continue;
            }
            $granja = substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3);
            $campania = substr(str_pad($cDigits, 3, '0', STR_PAD_LEFT), 0, 3);

            $galpones = [];
            if (isset($item['galpones']) && is_array($item['galpones'])) {
                foreach ($item['galpones'] as $gp) {
                    $gpT = trim((string) $gp);
                    if ($gpT !== '') {
                        $galpones[] = $gpT;
                    }
                }
            }
            $galpones = array_values(array_unique($galpones));
            sort($galpones, SORT_NUMERIC);

            $sexo = strtolower(trim((string) ($item['sexo'] ?? 'todos')));
            if (!in_array($sexo, ['todos', 'macho', 'hembra'], true)) {
                $sexo = 'todos';
            }

            $lotes[] = [
                'granja' => $granja,
                'campania' => $campania,
                'galpones' => $galpones,
                'sexo' => $sexo,
            ];
        }

        return $lotes;
    }
}

if (!function_exists('mort_graficas_fecha_inicio_lote')) {
    /**
     * Fecha de ingreso (fec_ing) del lote desde maes_zonas.
     *
     * @param array{granja: string, campania: string, galpones: list<string>} $lote
     */
    function mort_graficas_fecha_inicio_lote(mysqli $conn, array $lote): ?string
    {
        $cenco = (string) $lote['granja'] . (string) $lote['campania'];
        $sql = "SELECT MIN(DATE(m.fec_ing)) AS fec_ing
                FROM maes_zonas m
                WHERE m.tcodigo IN ('P0001001','P0001002')
                  AND TRIM(m.tcencos) = '{$cenco}'
                  AND m.fec_ing IS NOT NULL
                  AND m.fec_ing > '1900-01-01'";

        $galpones = (array) ($lote['galpones'] ?? []);
        if (count($galpones) === 1) {
            $galEsc = mysqli_real_escape_string($conn, (string) $galpones[0]);
            $sql .= " AND TRIM(CAST(m.tcodint AS CHAR)) = '{$galEsc}' ";
        } elseif (count($galpones) > 1) {
            $gParts = [];
            foreach ($galpones as $gp) {
                $gParts[] = "'" . mysqli_real_escape_string($conn, (string) $gp) . "'";
            }
            $sql .= ' AND TRIM(CAST(m.tcodint AS CHAR)) IN (' . implode(',', $gParts) . ') ';
        }

        $res = @mysqli_query($conn, $sql);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $fec = trim((string) ($row['fec_ing'] ?? ''));
            if ($fec !== '' && $fec !== '0000-00-00') {
                return $fec;
            }
        }

        // Fallback: fecha más temprana de movimientos del lote.
        $sqlFb = "SELECT MIN(DATE(mz.tfectra)) AS fec
                  FROM movi_zonas mz
                  WHERE TRIM(mz.tcencos) = '{$cenco}'
                    AND COALESCE(mz.tcantid, 0) > 0";
        if (count($galpones) === 1) {
            $galEsc = mysqli_real_escape_string($conn, (string) $galpones[0]);
            $sqlFb .= " AND TRIM(CAST(mz.tcodint AS CHAR)) = '{$galEsc}' ";
        } elseif (count($galpones) > 1) {
            $gParts = [];
            foreach ($galpones as $gp) {
                $gParts[] = "'" . mysqli_real_escape_string($conn, (string) $gp) . "'";
            }
            $sqlFb .= ' AND TRIM(CAST(mz.tcodint AS CHAR)) IN (' . implode(',', $gParts) . ') ';
        }
        $resFb = @mysqli_query($conn, $sqlFb);
        if ($resFb && ($rowFb = mysqli_fetch_assoc($resFb))) {
            $fec = trim((string) ($rowFb['fec'] ?? ''));
            if ($fec !== '' && $fec !== '0000-00-00') {
                return $fec;
            }
        }

        return null;
    }
}

if (!function_exists('mort_graficas_fetch_serie_lote')) {
    /**
     * Serie diaria del lote desde el día 1 (día de campaña).
     *
     * El día se calcula como DATEDIFF(tfectra, fec_ing) + 1, de modo que la
     * gráfica siempre arranca en el día 1, igual que sip_grafica_mortalidad.php.
     *
     * @param array{granja: string, campania: string, galpones: list<string>, sexo: string} $lote
     * @param array{desde: string, hasta: string}|null $rango
     * @return array{fechaInicio: ?string, puntos: list<array{dia: int, mortalidad: int, venta: int}>}
     */
    function mort_graficas_fetch_serie_lote(mysqli $conn, array $lote, ?array $rango, string $operacion = 'todos'): array
    {
        $granja = (string) $lote['granja'];
        $campania = (string) $lote['campania'];
        $galpones = (array) ($lote['galpones'] ?? []);
        $sexo = strtolower(trim((string) ($lote['sexo'] ?? 'todos')));

        $fechaInicio = mort_graficas_fecha_inicio_lote($conn, $lote);
        if ($fechaInicio === null) {
            return ['fechaInicio' => null, 'puntos' => []];
        }

        $where = " TRIM(mz.tcencos) = '{$granja}{$campania}'
                   AND COALESCE(mz.tcantid, 0) > 0 ";

        if (count($galpones) === 1) {
            $galEsc = mysqli_real_escape_string($conn, (string) $galpones[0]);
            $where .= " AND TRIM(CAST(mz.tcodint AS CHAR)) = '{$galEsc}' ";
        } elseif (count($galpones) > 1) {
            $gParts = [];
            foreach ($galpones as $gp) {
                $gParts[] = "'" . mysqli_real_escape_string($conn, (string) $gp) . "'";
            }
            $where .= ' AND TRIM(CAST(mz.tcodint AS CHAR)) IN (' . implode(',', $gParts) . ') ';
        }

        // Filtro por sexo: en movi_zonas el sexo se identifica por el producto
        // (P0001001 = Macho, P0001002 = Hembra), tanto en S808 como en S700.
        if ($sexo === 'macho') {
            $where .= " AND TRIM(mz.tcodigo) = 'P0001001' ";
        } elseif ($sexo === 'hembra') {
            $where .= " AND TRIM(mz.tcodigo) = 'P0001002' ";
        }

        if ($operacion === 'mortalidad') {
            $tcodIn = ['S808'];
        } elseif ($operacion === 'venta') {
            $tcodIn = ['S700'];
        } else {
            $tcodIn = ['S808', 'S700'];
        }
        $tcodParts = [];
        foreach ($tcodIn as $tc) {
            $tcodParts[] = "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }
        $tcodInSql = implode(',', $tcodParts);

        $sql = "SELECT
                    DATEDIFF(DATE(mz.tfectra), '{$fechaInicio}') + 1 AS dia,
                    TRIM(mz.tcodtra) AS tcodtra,
                    CASE
                        WHEN TRIM(COALESCE(mz.tcategoria, '')) = 'PlantaIncubacion' THEN 'incubacion'
                        WHEN TRIM(COALESCE(mz.tcategoria, '')) = 'Transporte' THEN 'transporte'
                        WHEN TRIM(COALESCE(mz.tcategoria, '')) IN ('P','Produccion') THEN 'produccion'
                        WHEN (TRIM(COALESCE(mz.tcategoria, '')) = 'Despacho' OR TRIM(COALESCE(mz.flujo, '')) = 'Despacho') THEN 'despacho'
                        ELSE 'otro'
                    END AS tipo_mort,
                    COALESCE(SUM(mz.tcantid), 0) AS cantidad
                FROM movi_zonas mz
                WHERE {$where}
                  AND mz.tcodtra IN ({$tcodInSql}) ";

        if ($rango !== null) {
            $desde = mysqli_real_escape_string($conn, $rango['desde']);
            $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
            $sql .= " AND DATE(mz.tfectra) >= '{$desde}' AND DATE(mz.tfectra) <= '{$hasta}' ";
        }

        $sql .= " GROUP BY dia, TRIM(mz.tcodtra), tipo_mort ORDER BY dia ASC";

        $map = [];
        $maxDia = 0;
        $res = @mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $dia = (int) ($row['dia'] ?? 0);
                if ($dia <= 0) {
                    continue;
                }
                $cant = (int) ($row['cantidad'] ?? 0);
                $tc = strtoupper(trim((string) ($row['tcodtra'] ?? '')));
                if (!isset($map[$dia])) {
                    $map[$dia] = ['dia' => $dia, 'mortalidad' => 0, 'mIncubacion' => 0, 'mTransporte' => 0, 'mProduccion' => 0, 'mDespacho' => 0, 'mOtro' => 0, 'venta' => 0];
                }
                if ($tc === 'S808') {
                    $map[$dia]['mortalidad'] += $cant;
                    $tipo = trim((string) ($row['tipo_mort'] ?? 'otro'));
                    switch ($tipo) {
                        case 'incubacion':
                            $map[$dia]['mIncubacion'] += $cant;
                            break;
                        case 'transporte':
                            $map[$dia]['mTransporte'] += $cant;
                            break;
                        case 'produccion':
                            $map[$dia]['mProduccion'] += $cant;
                            break;
                        case 'despacho':
                            $map[$dia]['mDespacho'] += $cant;
                            break;
                        default:
                            $map[$dia]['mOtro'] += $cant;
                            break;
                    }
                } elseif ($tc === 'S700') {
                    $map[$dia]['venta'] += $cant;
                }
                if ($dia > $maxDia) {
                    $maxDia = $dia;
                }
            }
        }

        // La gráfica siempre debe mostrar desde el día 1 hasta el día 47
        // (aunque no haya registros en algunos días).
        $maxDia = max(47, $maxDia);

        // Completar la serie desde el día 1 hasta el último día con datos.
        $puntos = [];
        $dtBase = new DateTime($fechaInicio);
        for ($d = 1; $d <= $maxDia; $d++) {
            $fila = $map[$d] ?? ['dia' => $d, 'mortalidad' => 0, 'mIncubacion' => 0, 'mTransporte' => 0, 'mProduccion' => 0, 'mDespacho' => 0, 'mOtro' => 0, 'venta' => 0];
            $fila['fecha'] = $dtBase->format('Y-m-d');
            $puntos[] = $fila;
            $dtBase->modify('+1 day');
        }

        return ['fechaInicio' => $fechaInicio, 'puntos' => $puntos];
    }
}

if (!function_exists('mort_graficas_texto_filtros_aplicados')) {
    /**
     * Texto legible de los filtros aplicados (para la franja de contexto).
     *
     * @param array<string, mixed> $filtros
     * @param list<array{granja: string, campania: string, galpones: list<string>}> $lotes
     * @param array<string, string> $nombresGranja mapa granja3 => nombre
     */
    function mort_graficas_texto_filtros_aplicados(array $filtros, array $lotes, array $nombresGranja = []): string
    {
        $partes = [];

        $rango = mort_graficas_rango_desde_filtros($filtros);
        $pt = strtoupper(trim((string) ($filtros['periodoTipo'] ?? 'TODOS')));
        if ($rango !== null && $pt !== 'TODOS') {
            $fmt = static function (string $d): string {
                return date('d/m/Y', strtotime($d));
            };
            $partes[] = 'Período: ' . $fmt($rango['desde']) . ' — ' . $fmt($rango['hasta']);
        } else {
            $partes[] = 'Período: Todos';
        }

        $nLotes = count($lotes);
        if ($nLotes > 0) {
            $vistos = [];
            $detalle = [];
            foreach ($lotes as $l) {
                $g = (string) $l['granja'];
                $nom = trim((string) ($nombresGranja[$g] ?? ''));
                $key = $g . (($nom !== '' && $nom !== $g) ? ' ' . $nom : '');
                $vistos[$key] = true;
                $galps = (array) ($l['galpones'] ?? []);
                $detalle[] = $key . ' / C' . (string) $l['campania'] . (count($galps) === 1 ? ' / G' . (string) $galps[0] : (count($galps) > 1 ? ' / G' . implode(',', $galps) : ' / todos los galpones'));
            }
            $partes[] = $nLotes . ' lote(s): ' . implode('; ', $detalle);
        } else {
            $partes[] = 'Granja/campaña: sin selección';
        }

        $operacion = strtolower(trim((string) ($filtros['operacion'] ?? 'todos')));
        $labels = mort_graficas_codigos_operacion();
        if ($operacion === 'todos') {
            $partes[] = 'Operación: Mortalidad y venta';
        } else {
            $partes[] = 'Operación: ' . ($labels[$operacion]['label'] ?? $operacion);
        }

        return implode(' · ', $partes);
    }
}
