<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';
require_once __DIR__ . '/sip_grafica_std_lib.php';

if (!function_exists('sip_grafica_consumo_cfg')) {
    /**
     * @return array<string,mixed>|null
     */
    function sip_grafica_consumo_cfg(string $tipo): ?array
    {
        static $map = [
            'consumo_alimento' => [
                'tareaKey' => 'CRIANZA_CONSUMO_DE_ALIMENTO',
                'parametroKey' => 'CONSUMO_ALIMENTO_DIA',
                'label' => 'Consumo de alimento',
                'unidadDefault' => 'kg/ave',
                'modoEvaluacion' => 'solo_min',
                'transform' => 'alimento',
            ],
            'consumo_gas' => [
                'tareaKey' => 'CRIANZA_CONSUMO_DE_GAS',
                'parametroKey' => 'CONSUMO_GAS_DIA',
                'label' => 'Consumo de gas',
                'unidadDefault' => 'm3 x 1000 aves',
                'modoEvaluacion' => 'solo_max',
                'transform' => 'gas_agua',
            ],
            'consumo_agua' => [
                'tareaKey' => 'CRIANZA_CONSUMO_DE_AGUA',
                'parametroKey' => 'CONSUMO_AGUA_DIA',
                'label' => 'Consumo de agua',
                'unidadDefault' => 'L x 1000 aves',
                'modoEvaluacion' => 'solo_max',
                'transform' => 'gas_agua',
            ],
        ];

        return $map[strtolower(trim($tipo))] ?? null;
    }
}

if (!function_exists('sip_grafica_consumo_tipos')) {
    /** @return list<string> */
    function sip_grafica_consumo_tipos(): array
    {
        return ['consumo_alimento', 'consumo_gas', 'consumo_agua'];
    }
}

if (!function_exists('sip_grafica_consumo_std_desde_row')) {
    /**
     * @param array<string,mixed> $r
     * @return array{0:?float,1:?float}
     */
    function sip_grafica_consumo_std_desde_row(array $r, string $modoEvaluacion): array
    {
        $sMin = null;
        $sMax = null;
        $ej = trim((string) ($r['estandarJson'] ?? ''));
        if ($ej !== '') {
            $est = @json_decode($ej, true);
            if (is_array($est)) {
                if (isset($est['stdMin']) && is_numeric($est['stdMin'])) {
                    $sMin = (float) $est['stdMin'];
                }
                if (isset($est['stdMax']) && is_numeric($est['stdMax'])) {
                    $sMax = (float) $est['stdMax'];
                }
                if ($sMin === null && $sMax === null && isset($est['valor']) && is_numeric($est['valor'])) {
                    $v = (float) $est['valor'];
                    $sMin = $v;
                    $sMax = $v;
                }
            } elseif (is_numeric($ej)) {
                $v = (float) $ej;
                $sMin = $v;
                $sMax = $v;
            }
        }

        if ($modoEvaluacion === 'solo_min' && $sMin === null && $sMax !== null) {
            $sMin = $sMax;
        }
        if ($modoEvaluacion === 'solo_max' && $sMax === null && $sMin !== null) {
            $sMax = $sMin;
        }

        return [$sMin, $sMax];
    }
}

if (!function_exists('sip_grafica_consumo_std_desde_map')) {
    /** @return array{0:?float,1:?float,2:string} */
    function sip_grafica_consumo_std_desde_map(
        array $stdMap,
        string $tareaKey,
        string $parametroKey,
        int $edadDia
    ): array {
        [$min, $max, $unidades] = sip_grafica_std_rango_desde_map($stdMap, $tareaKey, $parametroKey, $edadDia);

        return [$min, $max, $unidades];
    }
}

if (!function_exists('sip_grafica_consumo_transform_valor')) {
    function sip_grafica_consumo_transform_valor(
        ?float $raw,
        ?float $stockPrimary,
        ?float $stockSecondary,
        string $transform
    ): ?float {
        if ($raw === null) {
            return null;
        }
        $stockTotal = 0.0;
        $hasTotal = false;
        if ($stockPrimary !== null && $stockPrimary > 0) {
            $stockTotal += $stockPrimary;
            $hasTotal = true;
        }
        if ($stockSecondary !== null && $stockSecondary > 0) {
            $stockTotal += $stockSecondary;
            $hasTotal = true;
        }

        if ($transform === 'alimento') {
            if ($stockPrimary !== null && $stockPrimary > 0) {
                return $raw / $stockPrimary;
            }
            if ($stockSecondary !== null && $stockSecondary > 0) {
                return $raw / $stockSecondary;
            }
            if ($hasTotal && $stockTotal > 0) {
                return $raw / $stockTotal;
            }

            return null;
        }

        if ($transform === 'gas_agua') {
            if ($stockPrimary !== null && $stockPrimary > 0) {
                return ($raw / $stockPrimary) * 1000.0;
            }
            if ($stockSecondary !== null && $stockSecondary > 0) {
                return ($raw / $stockSecondary) * 1000.0;
            }
            if ($hasTotal && $stockTotal > 0) {
                return ($raw / $stockTotal) * 1000.0;
            }

            return null;
        }

        return null;
    }
}

if (!function_exists('sip_grafica_consumo_pollos_usados')) {
    /** Pollos (stock) usados al transformar el valor crudo, mismo criterio que transform_valor. */
    function sip_grafica_consumo_pollos_usados(
        ?float $stockPrimary,
        ?float $stockSecondary,
        bool $forHembra = false
    ): ?float {
        $primary = $forHembra ? $stockSecondary : $stockPrimary;
        $secondary = $forHembra ? $stockPrimary : $stockSecondary;
        if ($primary !== null && $primary > 0) {
            return $primary;
        }
        if ($secondary !== null && $secondary > 0) {
            return $secondary;
        }
        $total = 0.0;
        if ($stockPrimary !== null && $stockPrimary > 0) {
            $total += $stockPrimary;
        }
        if ($stockSecondary !== null && $stockSecondary > 0) {
            $total += $stockSecondary;
        }

        return $total > 0 ? $total : null;
    }
}

if (!function_exists('sip_grafica_consumo_linea')) {
    /**
     * Línea M/H para consumo alimento, gas o agua (san_fact_historia_clinica).
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_consumo_linea(mysqli $conn, array $opts, string $tipo): array
    {
        $cfg = sip_grafica_consumo_cfg($tipo);
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
        $tk = (string) $cfg['tareaKey'];
        $pkDefault = (string) $cfg['parametroKey'];
        $transform = (string) $cfg['transform'];
        $modoEval = (string) $cfg['modoEvaluacion'];
        $unidad = (string) $cfg['unidadDefault'];

        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);
        $stockPack = sip_grafica_stock_por_dia_fetch($conn, $granja3, $campania3, $galBind, $galBind2);
        $stockPorDia = $stockPack['stockPorDia'];
        $stockDia1 = $stockPack['stockDia1'];

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

        if ($rows === []) {
            return ['success' => false, 'message' => 'Sin datos para esa combinacion'];
        }

        $byEdad = [];
        foreach ($rows as $r) {
            $ed = (int) ($r['edadDia'] ?? 0);
            if ($ed <= 0) {
                continue;
            }
            $byEdad[$ed][] = $r;
        }
        ksort($byEdad, SORT_NUMERIC);

        $dataPorDia = [];
        foreach (array_keys($byEdad) as $ed) {
            $rowsEd = $byEdad[$ed];
            $vm = null;
            $vh = null;
            $pollosM = null;
            $pollosH = null;
            $factM = null;
            $factH = null;
            $valorFactStored = null;
            $sMin = null;
            $sMax = null;
            $pkUsed = $pkDefault;

            foreach ($rowsEd as $r) {
                $pkRow = trim((string) ($r['parametroKey'] ?? ''));
                if ($pkRow !== '') {
                    $pkUsed = $pkRow;
                }
                $valorFactStored = sip_grafica_valor_fact_export($r['valor']);
                $parsed = sip_grafica_parse_valor_mh($r['valor'], 0);

                $stkDia = sip_grafica_stock_resolver($ed, $stockPorDia, $stockDia1);
                $stkM = sip_grafica_to_num($stkDia['m'] ?? null);
                $stkH = sip_grafica_to_num($stkDia['h'] ?? null);
                $pollosM = sip_grafica_consumo_pollos_usados($stkM, $stkH, false);
                $pollosH = sip_grafica_consumo_pollos_usados($stkM, $stkH, true);

                if ($parsed['esMh']) {
                    $factM = $parsed['m'];
                    $factH = $parsed['h'];
                    if ($parsed['m'] !== null) {
                        $vm = sip_grafica_consumo_transform_valor($parsed['m'], $stkM, $stkH, $transform);
                    }
                    if ($parsed['h'] !== null) {
                        $vh = sip_grafica_consumo_transform_valor($parsed['h'], $stkH, $stkM, $transform);
                    }
                } elseif ($parsed['unified'] !== null) {
                    $factM = $parsed['unified'];
                    $factH = $parsed['unified'];
                    $tr = sip_grafica_consumo_transform_valor($parsed['unified'], $stkM, $stkH, $transform);
                    $vm = $tr;
                    $vh = $tr;
                }

                if ($sMin === null && $sMax === null) {
                    [$sMin, $sMax] = sip_grafica_consumo_std_desde_row($r, $modoEval);
                }
            }

            if ($sMin === null && $sMax === null) {
                [$sMin, $sMax, $uMap] = sip_grafica_consumo_std_desde_map($stdMap, $tk, $pkUsed, $ed);
                if ($uMap !== '—' && !preg_match('/^\d/', $uMap)) {
                    $unidad = $uMap;
                }
            }

            if ($sMin === null && $sMax === null) {
                [$sMin, $sMax] = sip_grafica_consumo_std_desde_row(
                    ['parametroKey' => $pkUsed, 'estandarJson' => ''],
                    $modoEval
                );
            }

            $vmOk = ($vm !== null && !sip_grafica_is_nan($vm) && is_finite($vm));
            $vhOk = ($vh !== null && !sip_grafica_is_nan($vh) && is_finite($vh));

            if ($modoEval === 'solo_min') {
                $refStd = $sMin ?? $sMax;
                $cumpleM = $vmOk ? sip_grafica_eval_range($vm, $refStd, null, 'solo_min') : null;
                $cumpleH = $vhOk ? sip_grafica_eval_range($vh, $refStd, null, 'solo_min') : null;
            } else {
                $cumpleM = $vmOk ? sip_grafica_gas_cumple($vm, $sMin, $sMax) : null;
                $cumpleH = $vhOk ? sip_grafica_gas_cumple($vh, $sMin, $sMax) : null;
            }

            $dataPorDia[] = [
                'dia' => $ed,
                'fecha' => sip_grafica_fecha_por_dia($fechaInicio, $ed),
                'fact' => [
                    'valor' => $valorFactStored,
                ],
                'estandar' => [
                    'min' => $sMin ?? $sMax,
                    'max' => $sMax ?? $sMin,
                ],
                'macho' => [
                    'valor' => $vmOk ? $vm : null,
                    'valorFact' => $factM,
                    'cumple' => $cumpleM,
                    'pollos' => $pollosM,
                ],
                'hembra' => [
                    'valor' => $vhOk ? $vh : null,
                    'valorFact' => $factH,
                    'cumple' => $cumpleH,
                    'pollos' => $pollosH,
                ],
            ];
        }

        return [
            'success' => true,
            'dataPorDia' => $dataPorDia,
            'unidad' => $unidad,
            'esMh' => true,
            'modoEvaluacion' => $modoEval,
            'tareaKey' => $tk,
            'tipo' => strtolower(trim($tipo)),
            'nombre' => (string) ($cfg['label'] ?? $tipo),
        ];
    }
}
