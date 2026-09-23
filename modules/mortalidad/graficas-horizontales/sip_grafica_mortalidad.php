<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';
require_once __DIR__ . '/sip_grafica_std_lib.php';

const SIP_GRAFICA_TK_MORTALIDAD = 'CRIANZA_MORTALIDAD_DIARIA';

if (!function_exists('sip_grafica_mortalidad_linea')) {
    /**
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_mortalidad_linea(mysqli $conn, array $opts): array
    {
        $granja = trim((string) ($opts['granja'] ?? ''));
        $campania = trim((string) ($opts['campania'] ?? ''));
        $galpon = trim((string) ($opts['galpon'] ?? ''));

        if ($granja === '' || $campania === '' || $galpon === '') {
            return ['success' => false, 'message' => 'Faltan parametros: granja, campania, galpon'];
        }

        [$granja3, $campania3, $galBind, $galBind2] = sip_grafica_normalize_granja_campania($granja, $campania, $galpon);
        $tk = SIP_GRAFICA_TK_MORTALIDAD;
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);

        $stockPorDia = [];
        $stockDia1 = null;
        $sqlStk = "SELECT d.`edadDia`, d.`valor` AS stockVal
                   FROM `san_fact_historia_clinica_det` d
                   INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                   WHERE LPAD(LEFT(TRIM(c.`granja`),3),3,'0') = ?
                     AND TRIM(c.`campania`) = ?
                     AND (
                          TRIM(CAST(c.`galpon` AS CHAR)) = ?
                          OR CAST(TRIM(CAST(c.`galpon` AS CHAR)) AS UNSIGNED) = CAST(? AS UNSIGNED)
                     )
                     AND d.`tareaKey` = 'CANTIDAD_POLLOS'
                   ORDER BY d.`edadDia` ASC";
        $stStk = $conn->prepare($sqlStk);
        if ($stStk) {
            $stStk->bind_param('ssss', $granja3, $campania3, $galBind, $galBind2);
            $stStk->execute();
            $rsStk = $stStk->get_result();
            while ($sr = $rsStk->fetch_assoc()) {
                $edDia = (int) ($sr['edadDia'] ?? 0);
                $sv = (string) ($sr['stockVal'] ?? '');
                $decS = @json_decode($sv, true);
                $mVal = null;
                $hVal = null;
                if (is_array($decS) && isset($decS['M'], $decS['H'])) {
                    $mVal = sip_grafica_to_num($decS['M']);
                    $hVal = sip_grafica_to_num($decS['H']);
                } elseif (is_numeric($sv)) {
                    $mVal = (float) $sv;
                    $hVal = (float) $sv;
                }
                $stockPorDia[$edDia] = ['m' => $mVal, 'h' => $hVal];
            }
            $stStk->close();
        }
        if (!empty($stockPorDia)) {
            $stockDia1 = $stockPorDia[min(array_keys($stockPorDia))];
        }

        $sqlDet = "SELECT d.`edadDia`, d.`parametroKey`, d.`valor`, d.`estandarJson`
                   FROM `san_fact_historia_clinica_det` d
                   INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                   WHERE LPAD(LEFT(TRIM(c.`granja`),3),3,'0') = ?
                     AND TRIM(c.`campania`) = ?
                     AND (
                          TRIM(CAST(c.`galpon` AS CHAR)) = ?
                          OR CAST(TRIM(CAST(c.`galpon` AS CHAR)) AS UNSIGNED) = CAST(? AS UNSIGNED)
                     )
                     AND TRIM(d.`tareaKey`) = ?
                   ORDER BY d.`edadDia` ASC";
        $stDet = $conn->prepare($sqlDet);
        if (!$stDet) {
            return ['success' => false, 'message' => 'Error al preparar detalle: ' . $conn->error];
        }
        $stDet->bind_param('sssss', $granja3, $campania3, $galBind, $galBind2, $tk);
        $stDet->execute();
        $rsDet = $stDet->get_result();
        $rows = [];
        while ($r = $rsDet->fetch_assoc()) {
            $rows[] = $r;
        }
        $stDet->close();

        $fechaInicio = sip_grafica_fecha_inicio_cab($conn, $granja3, $campania3, $galBind, $galBind2);

        if (empty($rows)) {
            return ['success' => false, 'message' => 'Sin datos para esa combinacion'];
        }

        $byEdad = [];
        foreach ($rows as $r) {
            $ed = (int) $r['edadDia'];
            if ($ed <= 0) {
                continue;
            }
            if (!isset($byEdad[$ed])) {
                $byEdad[$ed] = [];
            }
            $byEdad[$ed][] = $r;
        }
        ksort($byEdad, SORT_NUMERIC);

        $resolveStd = static function (array $r, int $ed) use ($stdMap, $tk): ?float {
            $pk = trim((string) ($r['parametroKey'] ?? ''));
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
            if ($sm === null && !empty($stdMap)) {
                [, $sMax] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pk, $ed);
                $sm = $sMax;
                if ($sm !== null && $sm > 1.0) {
                    $sm /= 100.0;
                }
            }

            return $sm;
        };

        $stkM1 = sip_grafica_to_num($stockDia1['m'] ?? null);
        $stkH1 = sip_grafica_to_num($stockDia1['h'] ?? null);

        $dataPorDia = [];
        foreach (array_keys($byEdad) as $ed) {
            $rowsEd = $byEdad[$ed];
            $vm = null;
            $vh = null;
            $rawM = null;
            $rawH = null;
            $sm = null;
            $pkUsed = 'MORTALIDAD_DIA';

            foreach ($rowsEd as $r) {
                $pkRow = trim((string) ($r['parametroKey'] ?? ''));
                if ($pkRow !== '') {
                    $pkUsed = $pkRow;
                }
                $rawVal = $r['valor'];
                $val = is_string($rawVal) ? json_decode($rawVal, true) : (is_array($rawVal) ? $rawVal : []);
                if (!is_array($val)) {
                    continue;
                }
                $rawM = sip_grafica_to_num($val['M'] ?? null);
                $rawH = sip_grafica_to_num($val['H'] ?? null);

                if ($stockDia1 !== null) {
                    if ($rawM !== null && $stkM1 !== null && $stkM1 > 0) {
                        $vm = ($rawM / $stkM1) * 100.0;
                    }
                    if ($rawH !== null && $stkH1 !== null && $stkH1 > 0) {
                        $vh = ($rawH / $stkH1) * 100.0;
                    }
                }

                if ($sm === null) {
                    $sm = $resolveStd($r, $ed);
                }
            }
            if ($sm === null) {
                $sm = $resolveStd(['parametroKey' => $pkUsed, 'estandarJson' => ''], $ed);
            }

            $vmOk = ($vm !== null && !sip_grafica_is_nan($vm) && is_finite($vm));
            $vhOk = ($vh !== null && !sip_grafica_is_nan($vh) && is_finite($vh));
            $fecha = sip_grafica_fecha_por_dia($fechaInicio, $ed);

            $dataPorDia[] = [
                'dia' => $ed,
                'fecha' => $fecha,
                'estandar' => [
                    'min' => $sm,
                    'max' => $sm,
                ],
                'macho' => [
                    'valor' => $vmOk ? $vm : null,
                    'cumple' => $vmOk ? sip_grafica_eval_range($vm, null, $sm, 'solo_max') : null,
                ],
                'hembra' => [
                    'valor' => $vhOk ? $vh : null,
                    'cumple' => $vhOk ? sip_grafica_eval_range($vh, null, $sm, 'solo_max') : null,
                ],
            ];
        }

        return [
            'success' => true,
            'dataPorDia' => $dataPorDia,
            'unidad' => '%',
            'esMh' => true,
            'modoEvaluacion' => 'solo_max',
            'tareaKey' => $tk,
        ];
    }
}

if (!function_exists('sip_grafica_mortalidad_causas')) {
    /**
     * @param array{granja:string,campania:string,galpon?:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_mortalidad_causas(mysqli $conn, array $opts): array
    {
        $granja = trim((string) ($opts['granja'] ?? ''));
        $campania = trim((string) ($opts['campania'] ?? ''));
        $galpon = trim((string) ($opts['galpon'] ?? ''));

        $empty = [
            'success' => true,
            'porFecha' => [],
        ];

        if ($granja === '' || $campania === '') {
            return $empty;
        }

        $granja3 = substr(str_pad($granja, 3, '0', STR_PAD_LEFT), 0, 3);
        $camp3 = substr(str_pad(preg_replace('/\s+/', '', $campania), 3, '0', STR_PAD_LEFT), -3);
        $tcencos = $granja3 . $camp3;

        $sql = "
            SELECT
                DATE(b.tdate) AS fechaRegistro,
                m.tcod_mort AS codMortalidad,
                m.tnom_mort AS nomMortalidad,
                SUM(a.tcantid) AS total
            FROM movi_zonas a
            LEFT JOIN cabe_zonas b ON a.treg = b.treg AND a.mark = b.mark
            LEFT JOIN regmotivo_mortalidadgrs m ON a.tcod_mortgrs = m.tcod_mort
            WHERE a.tcencos = '" . mysqli_real_escape_string($conn, $tcencos) . "'
              AND a.tcodigo IN ('P0001001','P0001002')
              AND a.tcodtra = 'S808'
              AND m.tnom_mort IS NOT NULL AND TRIM(m.tnom_mort) <> ''
        ";

        if ($galpon !== '') {
            $sql .= " AND TRIM(a.tcodint) = '" . mysqli_real_escape_string($conn, $galpon) . "' ";
        }

        $sql .= ' GROUP BY DATE(b.tdate), m.tcod_mort, m.tnom_mort ORDER BY DATE(b.tdate) ASC, m.tnom_mort ASC ';

        $result = mysqli_query($conn, $sql);
        if (!$result) {
            return ['success' => false, 'message' => 'Error en consulta'];
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }

        if (empty($rows)) {
            return $empty;
        }

        $porFechaMap = [];

        foreach ($rows as $r) {
            $fecha = (string) ($r['fechaRegistro'] ?? '');
            if ($fecha === '') {
                continue;
            }
            $codigo = (int) ($r['codMortalidad'] ?? 0);
            $nombre = trim((string) ($r['nomMortalidad'] ?? ''));
            $total = (int) ($r['total'] ?? 0);
            if ($nombre === '') {
                continue;
            }

            if (!isset($porFechaMap[$fecha])) {
                $porFechaMap[$fecha] = [];
            }
            $porFechaMap[$fecha][] = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'total' => $total,
            ];
        }

        ksort($porFechaMap, SORT_STRING);

        $porFecha = [];
        $firstDate = null;
        foreach ($porFechaMap as $fecha => $causasDia) {
            if ($firstDate === null) {
                $firstDate = $fecha;
            }
            $dia = null;
            if ($firstDate !== null) {
                try {
                    $base = new DateTime($firstDate);
                    $dt = new DateTime($fecha);
                    $dia = (int) $base->diff($dt)->format('%r%a') + 1;
                } catch (Exception $e) {
                    $dia = null;
                }
            }

            usort($causasDia, static function (array $a, array $b): int {
                return $b['total'] <=> $a['total'];
            });

            $totalDia = 0;
            foreach ($causasDia as $c) {
                $totalDia += (int) ($c['total'] ?? 0);
            }

            $porFecha[] = [
                'fecha' => $fecha,
                'dia' => $dia,
                'total' => $totalDia,
                'causas' => $causasDia,
            ];
        }

        return [
            'success' => true,
            'porFecha' => $porFecha,
        ];
    }
}

if (!function_exists('sip_grafica_mortalidad_pareto_totales')) {
    /**
     * @param list<array<string,mixed>> $porFecha
     * @return list<array{codigo:int,nombre:string,total:int}>
     */
    function sip_grafica_mortalidad_pareto_totales(array $porFecha): array
    {
        $map = [];
        foreach ($porFecha as $dia) {
            foreach ($dia['causas'] ?? [] as $c) {
                $nombre = trim((string) ($c['nombre'] ?? ''));
                if ($nombre === '') {
                    continue;
                }
                if (!isset($map[$nombre])) {
                    $map[$nombre] = [
                        'codigo' => (int) ($c['codigo'] ?? 0),
                        'nombre' => $nombre,
                        'total' => 0,
                    ];
                }
                $map[$nombre]['total'] += (int) ($c['total'] ?? 0);
            }
        }
        $totales = array_values($map);
        usort($totales, static function (array $a, array $b): int {
            return $b['total'] <=> $a['total'];
        });

        return $totales;
    }
}

if (!function_exists('sip_grafica_mortalidad_pareto')) {
    /**
     * @param array{granja:string,campania:string,galpon?:string} $opts
     * @param list<array<string,mixed>> $porFechaDado porFecha ya consultado (evita re-ejecutar causas)
     * @return array<string,mixed>
     */
    function sip_grafica_mortalidad_pareto(mysqli $conn, array $opts, array $porFechaDado = []): array
    {
        if ($porFechaDado === []) {
            $causas = sip_grafica_mortalidad_causas($conn, $opts);
            if (empty($causas['success'])) {
                return $causas;
            }
            $porFechaDado = $causas['porFecha'] ?? [];
        }

        $totales = sip_grafica_mortalidad_pareto_totales($porFechaDado);
        $grandTotal = 0;
        foreach ($totales as $t) {
            $grandTotal += (int) ($t['total'] ?? 0);
        }

        $items = [];
        $acum = 0.0;
        foreach ($totales as $t) {
            $total = (int) ($t['total'] ?? 0);
            $pct = $grandTotal > 0 ? round(($total / $grandTotal) * 100.0, 2) : 0.0;
            $acum += $pct;
            $items[] = [
                'codigo' => (int) ($t['codigo'] ?? 0),
                'nombre' => (string) ($t['nombre'] ?? ''),
                'total' => $total,
                'pct' => $pct,
                'pctAcum' => round($acum, 2),
            ];
        }

        return [
            'success' => true,
            'items' => $items,
            'total' => $grandTotal,
        ];
    }
}
