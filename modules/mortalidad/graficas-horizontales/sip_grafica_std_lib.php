<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';

if (!function_exists('sip_grafica_table_exists')) {
    function sip_grafica_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        $key = spl_object_hash($conn) . '|' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safe = $conn->real_escape_string($table);
        $rs = @$conn->query("SHOW TABLES LIKE '{$safe}'");
        $cache[$key] = (bool) ($rs && $rs->num_rows > 0);

        return $cache[$key];
    }
}

if (!function_exists('sip_grafica_std_map_desde_san_estandares')) {
    /** @return array<string, array{stdMin:string,stdMax:string,unidades:string,edadDiaExpr:string}> */
    function sip_grafica_std_map_desde_san_estandares(mysqli $conn): array
    {
        $map = [];
        if (!sip_grafica_table_exists($conn, 'san_estandares_parametros')
            || !sip_grafica_table_exists($conn, 'san_estandares_nodos')) {
            return $map;
        }
        $rs = $conn->query(
            "SELECT TRIM(n.`actividadKey`) AS tk, TRIM(p.`parametroKey`) AS pk,
                    TRIM(COALESCE(p.`stdMin`,'')) AS stdMin, TRIM(COALESCE(p.`stdMax`,'')) AS stdMax,
                    TRIM(COALESCE(p.`unidades`,'')) AS unidades,
                    TRIM(COALESCE(p.`edadDiaExpr`,'')) AS edadDiaExpr
             FROM `san_estandares_parametros` p
             INNER JOIN `san_estandares_nodos` n ON n.`id` = p.`nodoId`
             WHERE n.`actividadKey` IS NOT NULL AND n.`actividadKey` <> ''
               AND p.`parametroKey` IS NOT NULL AND p.`parametroKey` <> ''
             ORDER BY n.`actividadKey` ASC, p.`parametroKey` ASC, p.`id` ASC"
        );
        if (!$rs) {
            return $map;
        }
        while ($row = $rs->fetch_assoc()) {
            $tk = strtoupper(trim((string) ($row['tk'] ?? '')));
            $pk = strtoupper(trim((string) ($row['pk'] ?? '')));
            $ex = trim((string) ($row['edadDiaExpr'] ?? ''));
            if ($tk === '' || $pk === '') {
                continue;
            }
            $key = $tk . '|' . $pk . '|' . $ex;
            if (!isset($map[$key])) {
                $map[$key] = [
                    'stdMin' => (string) ($row['stdMin'] ?? ''),
                    'stdMax' => (string) ($row['stdMax'] ?? ''),
                    'unidades' => (string) ($row['unidades'] ?? ''),
                    'edadDiaExpr' => $ex,
                ];
            }
        }
        $rs->free();

        return $map;
    }
}

if (!function_exists('sip_grafica_std_rango_desde_map')) {
    /** @return array{0:?float,1:?float,2:string} */
    function sip_grafica_std_rango_desde_map(array $stdMap, string $tareaKey, string $parametroKey, int $edadDia): array
    {
        $tk = strtoupper(trim($tareaKey));
        $pk = strtoupper(trim($parametroKey));
        $row = $stdMap[$tk . '|' . $pk . '|' . $edadDia]
            ?? $stdMap[$tk . '|' . $pk . '|']
            ?? null;
        if (!is_array($row)) {
            foreach ($stdMap as $k => $r) {
                if (strpos((string) $k, $tk . '|' . $pk . '|') !== 0) {
                    continue;
                }
                $expr = trim((string) ($r['edadDiaExpr'] ?? ''));
                if ($expr === '') {
                    $row = $r;
                    break;
                }
                if (is_numeric($expr) && (int) $expr === $edadDia) {
                    $row = $r;
                    break;
                }
                if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $expr, $m)
                    && $edadDia >= (int) $m[1] && $edadDia <= (int) $m[2]) {
                    $row = $r;
                    break;
                }
            }
        }
        if (!is_array($row)) {
            return [null, null, '—'];
        }
        $minS = trim((string) ($row['stdMin'] ?? ''));
        $maxS = trim((string) ($row['stdMax'] ?? ''));
        $min = ($minS !== '' && is_numeric($minS)) ? (float) $minS : null;
        $max = ($maxS !== '' && is_numeric($maxS)) ? (float) $maxS : null;
        $unidades = trim((string) ($row['unidades'] ?? ''));

        return [$min, $max, $unidades !== '' ? $unidades : '—'];
    }
}

if (!function_exists('sip_grafica_fecha_inicio_cab')) {
    function sip_grafica_fecha_inicio_cab(
        mysqli $conn,
        string $granja3,
        string $campania,
        string $galBind,
        string $galBind2
    ): ?string {
        // Ancla de edad de etl_11: ingreso real (MIN E001) por galpón.
        $galNorm = ltrim($galBind, '0');
        if ($galNorm === '') {
            $galNorm = $galBind2;
        }
        $gPrefijo = $granja3 . '%';
        $sqlE001 = "SELECT MIN(DATE(tfectra)) AS fi
                    FROM movi_zonas
                    WHERE tcencos LIKE ?
                      AND RIGHT(tcencos, 3) = ?
                      AND tcodtra = 'E001' AND tline = '001'
                      AND tcodint = ?
                      AND tcodint <> ''
                    GROUP BY tcodint";
        foreach (array_unique(array_filter([$galBind, $galNorm])) as $gpTry) {
            $st = $conn->prepare($sqlE001);
            if (!$st) {
                continue;
            }
            $st->bind_param('sss', $gPrefijo, $campania, $gpTry);
            $st->execute();
            $rs = $st->get_result();
            $row = $rs ? $rs->fetch_assoc() : null;
            $st->close();
            if ($row && !empty($row['fi'])) {
                return (string) $row['fi'];
            }
        }

        // Fallback: fecha_inicio de la cabecera.
        $sql = "SELECT DATE(`fecha_inicio`) AS fecha_inicio
                FROM `san_fact_historia_clinica_cab`
                WHERE LPAD(LEFT(TRIM(`granja`),3),3,'0') = ?
                  AND TRIM(`campania`) = ?
                  AND (
                       TRIM(CAST(`galpon` AS CHAR)) = ?
                       OR CAST(TRIM(CAST(`galpon` AS CHAR)) AS UNSIGNED) = CAST(? AS UNSIGNED)
                  )
                ORDER BY `id` DESC LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) {
            return null;
        }
        $st->bind_param('ssss', $granja3, $campania, $galBind, $galBind2);
        $st->execute();
        $rs = $st->get_result();
        $cab = $rs ? $rs->fetch_assoc() : null;
        $st->close();
        if (!$cab || empty($cab['fecha_inicio'])) {
            return null;
        }

        return (string) $cab['fecha_inicio'];
    }
}

if (!function_exists('sip_grafica_normalize_granja_campania')) {
    /** @return array{0:string,1:string,2:string,3:string} [granja3, campania, galBind, galBind2] */
    function sip_grafica_normalize_granja_campania(string $granja, string $campania, string $galpon): array
    {
        $granjaDigits = preg_replace('/[^0-9]/', '', $granja);
        $granja3 = str_pad(substr((string) $granjaDigits, 0, 3), 3, '0', STR_PAD_LEFT);
        $campDigits = preg_replace('/\s+/', '', trim($campania));
        $campania3 = str_pad(substr((string) $campDigits, -3), 3, '0', STR_PAD_LEFT);
        $galpon = trim($galpon);
        $galponNorm = ltrim($galpon, '0');
        if ($galponNorm === '') {
            $galponNorm = $galpon !== '' ? $galpon : '';
        }
        $galBind = ($galpon !== '') ? $galpon : $galponNorm;
        $galBind2 = ($galponNorm !== '') ? $galponNorm : $galBind;

        return [$granja3, $campania3, $galBind, $galBind2];
    }
}

if (!function_exists('sip_grafica_stock_por_dia_fetch')) {
    /**
     * @return array{stockPorDia:array<int,array{m:?float,h:?float}>,stockDia1:?array{m:?float,h:?float}}
     */
    function sip_grafica_stock_por_dia_fetch(
        mysqli $conn,
        string $granja3,
        string $campania3,
        string $galBind,
        string $galBind2
    ): array {
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
                $parsed = sip_grafica_parse_valor_mh($sr['stockVal'] ?? null, 0);
                if ($parsed['esMh']) {
                    $mVal = $parsed['m'];
                    $hVal = $parsed['h'];
                } elseif ($parsed['unified'] !== null) {
                    $mVal = $parsed['unified'];
                    $hVal = $parsed['unified'];
                } else {
                    $mVal = $parsed['m'];
                    $hVal = $parsed['h'];
                }
                $stockPorDia[$edDia] = ['m' => $mVal, 'h' => $hVal];
            }
            $stStk->close();
        }
        if ($stockPorDia !== []) {
            $stockDia1 = $stockPorDia[min(array_keys($stockPorDia))];
        }

        return ['stockPorDia' => $stockPorDia, 'stockDia1' => $stockDia1];
    }
}

if (!function_exists('sip_grafica_stock_resolver')) {
    /** @param array<int,array{m:?float,h:?float}> $stockPorDia */
    function sip_grafica_stock_resolver(int $edad, array $stockPorDia, ?array $stockDia1): ?array
    {
        if (isset($stockPorDia[$edad])) {
            return $stockPorDia[$edad];
        }
        if ($stockPorDia !== []) {
            $bestKey = null;
            $bestDiff = PHP_INT_MAX;
            foreach (array_keys($stockPorDia) as $k) {
                $diff = abs($k - $edad);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestKey = $k;
                }
            }
            if ($bestKey !== null) {
                return $stockPorDia[$bestKey];
            }
        }

        return $stockDia1;
    }
}

if (!function_exists('sip_grafica_fecha_por_dia')) {
    function sip_grafica_fecha_por_dia(?string $fechaInicio, int $dia): ?string
    {
        if ($fechaInicio === null || $fechaInicio === '' || $dia <= 0) {
            return null;
        }
        try {
            $dt = new DateTime($fechaInicio);
            if ($dia > 1) {
                $dt->modify('+' . ($dia - 1) . ' days');
            }

            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }
}
