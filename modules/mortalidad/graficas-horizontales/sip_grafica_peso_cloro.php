<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_eval_lib.php';
require_once __DIR__ . '/sip_grafica_std_lib.php';

if (!function_exists('sip_grafica_peso_cloro_cfg')) {
    /**
     * @return array<string,mixed>|null
     */
    function sip_grafica_peso_cloro_cfg(string $tipo): ?array
    {
        static $map = [
            'pesaje_pollo' => [
                'tareaKey' => 'CRIANZA_PESAJE_DE_POLLO',
                'parametroKey' => 'PESAJE_POLLO_SEMANA',
                'label' => 'Pesaje de pollo',
                'unidadDefault' => 'Kg',
                'modoEvaluacion' => 'solo_min',
                'esSemanal' => true,
            ],
            'ganancia_peso' => [
                'tareaKey' => 'CRIANZA_GANANCIA_DE_PESO',
                'parametroKey' => 'GANANCIA_PESO_SEMANA',
                'label' => 'Ganancia de peso',
                'unidadDefault' => 'g',
                'modoEvaluacion' => 'solo_min',
                'esSemanal' => true,
            ],
            'nivel_cloro' => [
                'tareaKey' => 'CRIANZA_NIVEL_DE_CLORO',
                'parametroKey' => 'NIVEL_CLORO_DIA',
                'label' => 'Nivel de cloro',
                'unidadDefault' => 'ppm',
                'modoEvaluacion' => 'rango_cerrado',
                'esSemanal' => false,
            ],
        ];

        return $map[strtolower(trim($tipo))] ?? null;
    }
}

if (!function_exists('sip_grafica_peso_cloro_tipos')) {
    /** @return list<string> */
    function sip_grafica_peso_cloro_tipos(): array
    {
        return ['pesaje_pollo', 'ganancia_peso', 'nivel_cloro'];
    }
}

if (!function_exists('sip_grafica_semana_a_edad_std')) {
    /** Semana en fact → día en estándares (misma regla que HC). */
    function sip_grafica_semana_a_edad_std(string $parametroKey, int $semana): int
    {
        if ($semana <= 0) {
            return 0;
        }
        $pk = strtoupper(trim($parametroKey));
        if ($pk === 'GANANCIA_PESO_SEMANA') {
            return ($semana * 7) + 1;
        }
        if ($pk === 'PESAJE_POLLO_SEMANA') {
            return $semana * 7;
        }

        return $semana;
    }
}

if (!function_exists('sip_grafica_peso_std_desde_row')) {
    /**
     * @param array<string,mixed> $r
     * @return array{0:?float,1:?float}
     */
    function sip_grafica_peso_std_desde_row(array $r, string $modoEvaluacion): array
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

if (!function_exists('sip_grafica_cloro_texto_a_lineas')) {
    /** @return list<string> */
    function sip_grafica_cloro_texto_a_lineas(string $raw): array
    {
        $t = trim($raw);
        if ($t === '' || $t === '—') {
            return [];
        }
        $plain = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = preg_split('/\R/u', $plain) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '' && $p !== '—') {
                $out[] = $p;
            }
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_cloro_lineas_desde_valor')) {
    /**
     * Extrae líneas "HORA 06:00: valor" desde valor en san_fact.
     *
     * @return list<string>
     */
    function sip_grafica_cloro_lineas_desde_valor($rawVal): array
    {
        $exported = sip_grafica_valor_fact_export($rawVal);
        if (is_array($exported)) {
            if (isset($exported['current']) && is_array($exported['current'])) {
                $cur = $exported['current'];
                if (sip_grafica_json_is_vector($cur)) {
                    $lines = [];
                    foreach ($cur as $cell) {
                        if (is_string($cell) && (stripos($cell, 'HORA') !== false || stripos($cell, '<') !== false)) {
                            $lines = array_merge($lines, sip_grafica_cloro_texto_a_lineas($cell));
                        } elseif (is_string($cell) && trim($cell) !== '') {
                            $lines[] = trim($cell);
                        }
                    }

                    return $lines;
                }
            }
            if (sip_grafica_json_is_vector($exported)) {
                $lines = [];
                foreach ($exported as $cell) {
                    if (is_string($cell)) {
                        $lines = array_merge($lines, sip_grafica_cloro_texto_a_lineas($cell));
                    }
                }

                return $lines;
            }
        }
        if (is_string($exported)) {
            return sip_grafica_cloro_texto_a_lineas($exported);
        }

        return [];
    }
}

if (!function_exists('sip_grafica_cloro_parse_lecturas')) {
    /**
     * @param list<string> $lineas
     * @return list<array{hora:?string,label:?string,valor:?float,cumple:?bool}>
     */
    function sip_grafica_cloro_parse_lecturas(array $lineas, ?float $sMin, ?float $sMax, string $modoEval): array
    {
        $horaOrder = ['06:00' => 1, '12:00' => 2, '16:00' => 3];
        $out = [];
        foreach ($lineas as $ln) {
            $ln = trim((string) $ln);
            if ($ln === '') {
                continue;
            }
            $hora = null;
            $label = null;
            $valor = null;
            if (preg_match('/HORA\s*(\d{1,2}:\d{2})\s*:\s*(.+)$/ui', $ln, $m)) {
                $hora = $m[1];
                if (strlen($hora) === 4) {
                    $hora = '0' . $hora;
                }
                $label = 'HORA ' . $hora;
                $valor = sip_grafica_to_num(trim($m[2]));
            } else {
                $valor = sip_grafica_to_num($ln);
            }
            $cumple = ($valor !== null)
                ? sip_grafica_eval_range($valor, $sMin, $sMax, $modoEval)
                : null;
            $out[] = [
                'hora' => $hora,
                'label' => $label,
                'valor' => $valor,
                'cumple' => $cumple,
            ];
        }
        usort($out, static function (array $a, array $b) use ($horaOrder): int {
            $oa = $horaOrder[$a['hora'] ?? ''] ?? 99;
            $ob = $horaOrder[$b['hora'] ?? ''] ?? 99;

            return $oa <=> $ob;
        });

        return $out;
    }
}

if (!function_exists('sip_grafica_cloro_cumple_dia')) {
    /** @param list<array{cumple:?bool}> $lecturas */
    function sip_grafica_cloro_cumple_dia(array $lecturas): ?bool
    {
        $any = false;
        foreach ($lecturas as $l) {
            if ($l['cumple'] === null) {
                continue;
            }
            $any = true;
            if ($l['cumple'] === false) {
                return false;
            }
        }

        return $any ? true : null;
    }
}

if (!function_exists('sip_grafica_cloro_dia_desde_titulo')) {
    function sip_grafica_cloro_dia_desde_titulo(string $titulo): int
    {
        if (preg_match('/D[ií]a\s+(\d+)/ui', $titulo, $m)) {
            return max(1, (int) $m[1]);
        }

        return 0;
    }
}

if (!function_exists('sip_grafica_cloro_legacy_blocks')) {
    /**
     * Snapshot legacy: tabla HTML con bloques "Día N" + líneas HORA en una sola celda.
     *
     * @return list<array{titulo:string,lineas:list<string>}>
     */
    function sip_grafica_cloro_legacy_blocks(string $html): array
    {
        $htmlTrim = trim($html);
        if ($htmlTrim === '' || $htmlTrim === '—' || stripos($htmlTrim, '<table') === false) {
            return [];
        }
        if (!preg_match_all('#<tr>\s*<td([^>]*)>(.*?)</td>\s*</tr>#is', $htmlTrim, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $blocks = [];
        $curTitulo = '';
        /** @var list<string> $curLineas */
        $curLineas = [];
        $flush = static function () use (&$blocks, &$curTitulo, &$curLineas): void {
            if ($curTitulo !== '') {
                $blocks[] = ['titulo' => $curTitulo, 'lineas' => $curLineas];
                $curTitulo = '';
                $curLineas = [];
            }
        };
        foreach ($matches as $m) {
            $attrs = strtolower((string) ($m[1] ?? ''));
            $txt = trim(html_entity_decode(strip_tags((string) ($m[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($txt === '') {
                continue;
            }
            $isBoldTitulo = strpos($attrs, 'font-weight:600') !== false
                || strpos($attrs, 'font-weight:700') !== false
                || strpos($attrs, 'font-weight:bold') !== false;
            if ($isBoldTitulo) {
                $flush();
                $curTitulo = $txt;
                $curLineas = [];
            } elseif ($curTitulo !== '') {
                $curLineas[] = $txt;
            }
        }
        $flush();

        return $blocks;
    }
}

if (!function_exists('sip_grafica_cloro_hora_sort_key')) {
    /** @param array<string,mixed> $row */
    function sip_grafica_cloro_hora_sort_key(array $row): int
    {
        $tt = trim((string) ($row['ttipo'] ?? ''));
        if ($tt !== '' && ctype_digit($tt)) {
            return (int) $tt;
        }
        $tn = strtoupper(trim((string) ($row['tnomtipo'] ?? '')));
        $map = ['HORA 06:00' => 1, 'HORA 12:00' => 2, 'HORA 16:00' => 3];

        return $map[$tn] ?? 99;
    }
}

if (!function_exists('sip_grafica_cloro_hora_etiqueta')) {
    /** @param array<string,mixed> $row */
    function sip_grafica_cloro_hora_etiqueta(array $row): string
    {
        $tn = trim((string) ($row['tnomtipo'] ?? ''));
        if ($tn !== '') {
            return $tn;
        }
        $map = ['1' => 'HORA 06:00', '2' => 'HORA 12:00', '3' => 'HORA 16:00'];
        $tt = trim((string) ($row['ttipo'] ?? ''));

        return $map[$tt] ?? ('HORA ' . ($tt !== '' ? $tt : '?'));
    }
}

if (!function_exists('sip_grafica_cloro_galpon_key')) {
    function sip_grafica_cloro_galpon_key(string $galpon): string
    {
        $g = trim($galpon);
        if ($g === '') {
            return '';
        }
        if (ctype_digit($g)) {
            return (string) (int) $g;
        }

        return $g;
    }
}

if (!function_exists('sip_grafica_cloro_fetch_fact_rows')) {
    /**
     * @return list<array<string,mixed>>
     */
    function sip_grafica_cloro_fetch_fact_rows(
        mysqli $conn,
        string $granja3,
        string $campania3,
        string $galBind,
        string $galBind2,
        string $tk,
        bool $allGalpones = false
    ): array {
        $sql = "SELECT TRIM(CAST(c.`galpon` AS CHAR)) AS galpon,
                       d.`edadDia`, d.`parametroKey`, d.`valor`, d.`estandarJson`
                FROM `san_fact_historia_clinica_det` d
                INNER JOIN `san_fact_historia_clinica_cab` c ON c.`id` = d.`cabId`
                WHERE LPAD(LEFT(TRIM(c.`granja`),3),3,'0') = ?
                  AND TRIM(c.`campania`) = ?
                  AND TRIM(d.`tareaKey`) = ?";
        if (!$allGalpones) {
            $sql .= " AND (
                          TRIM(CAST(c.`galpon` AS CHAR)) = ?
                          OR CAST(TRIM(CAST(c.`galpon` AS CHAR)) AS UNSIGNED) = CAST(? AS UNSIGNED)
                     )";
        }
        $sql .= ' ORDER BY CAST(c.`galpon` AS UNSIGNED), CAST(d.`edadDia` AS UNSIGNED) ASC, d.`id` ASC';

        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        if ($allGalpones) {
            $st->bind_param('sss', $granja3, $campania3, $tk);
        } else {
            $st->bind_param('sssss', $granja3, $campania3, $tk, $galBind, $galBind2);
        }
        $st->execute();
        $rs = $st->get_result();
        $rows = [];
        while ($r = $rs->fetch_assoc()) {
            $rows[] = $r;
        }
        $st->close();

        return $rows;
    }
}

if (!function_exists('sip_grafica_cloro_resolve_fact_rows')) {
    /**
     * Cloro de granja/campaña: si un galpón tiene las lecturas, aplican a todos (como HC).
     *
     * @param list<array<string,mixed>> $allRows
     * @return list<array<string,mixed>>
     */
    function sip_grafica_cloro_resolve_fact_rows(
        array $allRows,
        string $galBind,
        string $galBind2
    ): array {
        if ($allRows === []) {
            return [];
        }

        /** @var array<string, list<array<string,mixed>>> $byGalpon */
        $byGalpon = [];
        foreach ($allRows as $r) {
            $gk = sip_grafica_cloro_galpon_key((string) ($r['galpon'] ?? ''));
            if ($gk === '') {
                continue;
            }
            $byGalpon[$gk][] = $r;
        }

        $reqKey = sip_grafica_cloro_galpon_key($galBind);
        if ($reqKey === '' && $galBind2 !== '') {
            $reqKey = sip_grafica_cloro_galpon_key($galBind2);
        }

        $reqRows = $byGalpon[$reqKey] ?? [];
        if ($byGalpon === []) {
            return $reqRows;
        }

        $bestKey = '';
        $bestCount = 0;
        foreach ($byGalpon as $gk => $rowsGp) {
            $cnt = count($rowsGp);
            if ($cnt > $bestCount) {
                $bestCount = $cnt;
                $bestKey = $gk;
            }
        }

        if ($bestKey === '') {
            return $reqRows;
        }

        if ($reqRows === [] || count($reqRows) < $bestCount) {
            return $byGalpon[$bestKey];
        }

        return $reqRows;
    }
}

if (!function_exists('sip_grafica_cloro_entries_from_regno')) {
    /**
     * Fallback: lecturas en vivo desde regnocontable (como HC cuando fact está incompleto).
     *
     * @return list<array{sort:int,edadAve:int,fecha:?string,row:?array,lineas:list<string>}>
     */
    function sip_grafica_cloro_entries_from_regno(
        mysqli $conn,
        string $granja3,
        string $campania3,
        string $galBind,
        string $galBind2,
        bool $allGalpones = false
    ): array {
        $fec = "COALESCE(NULLIF(DATE(tfec_ini),'1000-01-01'), DATE(tfectra))";
        $sql = "SELECT TRIM(tcodint) AS galpon,
                       {$fec} AS fecha_ref,
                       TRIM(CAST(COALESCE(tcantidad,'') AS CHAR)) AS valor_raw,
                       TRIM(COALESCE(CAST(ttipo AS CHAR),'')) AS ttipo,
                       TRIM(COALESCE(tnomtipo,'')) AS tnomtipo
                FROM regnocontable
                WHERE UPPER(TRIM(tproceso)) = 'CLORO'
                  AND LEFT(TRIM(tcencos), 3) = ?
                  AND RIGHT(TRIM(tcencos), 3) = ?";
        if (!$allGalpones) {
            $sql .= " AND (
                        TRIM(tcodint) = ?
                        OR CAST(TRIM(tcodint) AS UNSIGNED) = CAST(? AS UNSIGNED)
                     )";
        }
        $sql .= " AND TRIM(COALESCE(tcantidad,'')) <> ''
                  ORDER BY galpon ASC, fecha_ref ASC,
                           CAST(COALESCE(NULLIF(CAST(ttipo AS CHAR),''),'0') AS UNSIGNED) ASC";
        $st = $conn->prepare($sql);
        if (!$st) {
            return [];
        }
        if ($allGalpones) {
            $st->bind_param('ss', $granja3, $campania3);
        } else {
            $st->bind_param('ssss', $granja3, $campania3, $galBind, $galBind2);
        }
        $st->execute();
        $rs = $st->get_result();
        $flat = [];
        while ($r = $rs->fetch_assoc()) {
            $flat[] = $r;
        }
        $st->close();

        if ($flat === []) {
            return [];
        }

        if (!$allGalpones) {
            $flat = sip_grafica_cloro_resolve_fact_rows($flat, $galBind, $galBind2);
        } else {
            $byGalpon = [];
            foreach ($flat as $r) {
                $gk = sip_grafica_cloro_galpon_key((string) ($r['galpon'] ?? ''));
                if ($gk !== '') {
                    $byGalpon[$gk][] = $r;
                }
            }
            $bestKey = '';
            $bestCount = 0;
            foreach ($byGalpon as $gk => $rowsGp) {
                if (count($rowsGp) > $bestCount) {
                    $bestCount = count($rowsGp);
                    $bestKey = $gk;
                }
            }
            $flat = $bestKey !== '' ? ($byGalpon[$bestKey] ?? $flat) : $flat;
        }

        /** @var array<string, list<array<string,mixed>>> $porFecha */
        $porFecha = [];
        foreach ($flat as $r) {
            $fr = trim((string) ($r['fecha_ref'] ?? ''));
            $v = trim((string) ($r['valor_raw'] ?? ''));
            if ($fr === '' || $v === '') {
                continue;
            }
            $porFecha[$fr][] = $r;
        }
        if ($porFecha === []) {
            return [];
        }
        ksort($porFecha, SORT_STRING);
        $out = [];
        $diaOrd = 0;
        foreach ($porFecha as $fecha => $items) {
            ++$diaOrd;
            usort($items, static function (array $a, array $b): int {
                return sip_grafica_cloro_hora_sort_key($a) <=> sip_grafica_cloro_hora_sort_key($b);
            });
            $lineas = [];
            $seen = [];
            foreach ($items as $it) {
                $hk = (string) sip_grafica_cloro_hora_sort_key($it);
                if (isset($seen[$hk])) {
                    continue;
                }
                $seen[$hk] = true;
                $ln = sip_grafica_cloro_hora_etiqueta($it) . ': ' . trim((string) ($it['valor_raw'] ?? ''));
                if (trim((string) ($it['valor_raw'] ?? '')) !== '') {
                    $lineas[] = $ln;
                }
            }
            if ($lineas === []) {
                continue;
            }
            $out[] = [
                'sort' => $diaOrd,
                'edadAve' => 0,
                'fecha' => $fecha,
                'row' => null,
                'lineas' => $lineas,
            ];
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_cloro_entries_from_fact')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{sort:int,edadAve:int,fecha:?string,row:?array,lineas:?list<string>,titulo?:string}>
     */
    function sip_grafica_cloro_entries_from_fact(array $rows): array
    {
        /** @var array<int, array{sort:int,edadAve:int,fecha:?string,row:?array,lineas:?list<string>,titulo?:string}> $bySort */
        $bySort = [];
        $legacySeq = 0;

        foreach ($rows as $r) {
            $edadAve = (int) ($r['edadDia'] ?? 0);
            $lineasRow = sip_grafica_cloro_lineas_desde_valor($r['valor']);

            if ($edadAve > 0 && $lineasRow !== []) {
                if (!isset($bySort[$edadAve]) || count($lineasRow) >= count(sip_grafica_cloro_lineas_desde_valor($bySort[$edadAve]['row']['valor'] ?? ''))) {
                    $bySort[$edadAve] = [
                        'sort' => $edadAve,
                        'edadAve' => $edadAve,
                        'fecha' => null,
                        'row' => $r,
                        'lineas' => null,
                    ];
                }
                continue;
            }

            $exported = sip_grafica_valor_fact_export($r['valor']);
            $rawHtml = is_string($exported) ? $exported : (is_string($r['valor'] ?? null) ? (string) $r['valor'] : '');
            $blocks = sip_grafica_cloro_legacy_blocks($rawHtml);
            if ($blocks === []) {
                continue;
            }
            foreach ($blocks as $block) {
                $titulo = trim((string) ($block['titulo'] ?? ''));
                $lineas = is_array($block['lineas'] ?? null) ? $block['lineas'] : [];
                if ($lineas === []) {
                    continue;
                }
                $diaTit = sip_grafica_cloro_dia_desde_titulo($titulo);
                if ($diaTit > 0) {
                    $sort = $diaTit;
                } else {
                    ++$legacySeq;
                    $sort = 100000 + $legacySeq;
                }
                $bySort[$sort] = [
                    'sort' => $sort,
                    'edadAve' => $diaTit > 0 ? $diaTit : 0,
                    'fecha' => null,
                    'row' => $r,
                    'lineas' => $lineas,
                    'titulo' => $titulo,
                ];
            }
        }

        ksort($bySort, SORT_NUMERIC);

        return array_values($bySort);
    }
}

if (!function_exists('sip_grafica_cloro_entry_a_dia')) {
    /**
     * @param array{sort:int,edadAve:int,fecha:?string,row:?array,lineas:?list<string>} $entry
     * @return array<string,mixed>|null
     */
    function sip_grafica_cloro_entry_a_dia(
        array $entry,
        int $diaOrd,
        string $pkDefault,
        string $tk,
        string $modoEval,
        array $stdMap,
        ?string $fechaInicio
    ): ?array {
        $row = $entry['row'] ?? null;
        $lineas = is_array($entry['lineas'] ?? null)
            ? $entry['lineas']
            : ($row ? sip_grafica_cloro_lineas_desde_valor($row['valor']) : []);
        if ($lineas === []) {
            return null;
        }

        $edadAve = (int) ($entry['edadAve'] ?? 0);
        $pkUsed = $pkDefault;
        $estJson = '';
        if (is_array($row)) {
            $pkRow = trim((string) ($row['parametroKey'] ?? ''));
            if ($pkRow !== '') {
                $pkUsed = $pkRow;
            }
            $estJson = trim((string) ($row['estandarJson'] ?? ''));
        }

        $stdRow = is_array($row) ? $row : ['estandarJson' => $estJson];
        [$sMin, $sMax] = sip_grafica_peso_std_desde_row($stdRow, $modoEval);
        $stdEdad = $edadAve > 0 ? $edadAve : $diaOrd;
        if ($sMin === null && $sMax === null) {
            [$sMin, $sMax, $uMap] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pkUsed, $stdEdad);
        } else {
            $uMap = '—';
        }

        $lecturas = sip_grafica_cloro_parse_lecturas($lineas, $sMin, $sMax, $modoEval);
        $fecha = trim((string) ($entry['fecha'] ?? ''));
        if ($fecha === '' && $edadAve > 0 && $fechaInicio !== null && $fechaInicio !== '') {
            $fecha = sip_grafica_fecha_por_dia($fechaInicio, $edadAve) ?? '';
        }

        $out = [
            'dia' => $diaOrd,
            'fecha' => $fecha !== '' ? $fecha : null,
            'estandar' => [
                'min' => $sMin ?? $sMax,
                'max' => $sMax ?? $sMin,
            ],
            'lecturas' => $lecturas,
            'cumple' => sip_grafica_cloro_cumple_dia($lecturas),
        ];
        if ($edadAve > 0) {
            $out['edadAve'] = $edadAve;
        }

        return $out;
    }
}

if (!function_exists('sip_grafica_peso_linea')) {
    /**
     * Línea semanal M/H: pesaje o ganancia de peso.
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_peso_linea(mysqli $conn, array $opts, string $tipo): array
    {
        $cfg = sip_grafica_peso_cloro_cfg($tipo);
        if ($cfg === null || empty($cfg['esSemanal'])) {
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
        $modoEval = (string) $cfg['modoEvaluacion'];
        $unidad = (string) $cfg['unidadDefault'];
        $stdMap = sip_grafica_std_map_desde_san_estandares($conn);

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
        foreach (array_keys($byEdad) as $semana) {
            $rowsEd = $byEdad[$semana];
            $vm = null;
            $vh = null;
            $factM = null;
            $factH = null;
            $valorFactStored = null;
            $sMin = null;
            $sMax = null;
            $pkUsed = $pkDefault;
            $edadStd = sip_grafica_semana_a_edad_std($pkDefault, $semana);

            foreach ($rowsEd as $r) {
                $pkRow = trim((string) ($r['parametroKey'] ?? ''));
                if ($pkRow !== '') {
                    $pkUsed = $pkRow;
                }
                $valorFactStored = sip_grafica_valor_fact_export($r['valor']);
                $parsed = sip_grafica_parse_valor_mh($r['valor'], 0);
                if ($parsed['esMh']) {
                    $factM = $parsed['m'];
                    $factH = $parsed['h'];
                    $vm = $parsed['m'];
                    $vh = $parsed['h'];
                } elseif ($parsed['unified'] !== null) {
                    $factM = $parsed['unified'];
                    $factH = $parsed['unified'];
                    $vm = $parsed['unified'];
                    $vh = $parsed['unified'];
                }
                if ($sMin === null && $sMax === null) {
                    [$sMin, $sMax] = sip_grafica_peso_std_desde_row($r, $modoEval);
                }
            }

            if ($sMin === null && $sMax === null) {
                [$sMin, $sMax, $uMap] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pkUsed, $edadStd);
                if ($uMap !== '—' && !preg_match('/^\d/', $uMap)) {
                    $unidad = $uMap;
                }
            }

            $refStd = $sMin ?? $sMax;
            $vmOk = ($vm !== null && !sip_grafica_is_nan($vm) && is_finite($vm));
            $vhOk = ($vh !== null && !sip_grafica_is_nan($vh) && is_finite($vh));
            $cumpleM = $vmOk ? sip_grafica_eval_range($vm, $refStd, null, 'solo_min') : null;
            $cumpleH = $vhOk ? sip_grafica_eval_range($vh, $refStd, null, 'solo_min') : null;

            $dataPorDia[] = [
                'semana' => $semana,
                'edadStd' => $edadStd,
                'dia' => $semana,
                'fecha' => sip_grafica_fecha_por_dia($fechaInicio, $edadStd > 0 ? $edadStd : $semana),
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
                ],
                'hembra' => [
                    'valor' => $vhOk ? $vh : null,
                    'valorFact' => $factH,
                    'cumple' => $cumpleH,
                ],
            ];
        }

        return [
            'success' => true,
            'dataPorDia' => $dataPorDia,
            'unidad' => $unidad,
            'esMh' => true,
            'esSemanal' => true,
            'modoEvaluacion' => $modoEval,
            'tareaKey' => $tk,
            'tipo' => strtolower(trim($tipo)),
            'nombre' => (string) ($cfg['label'] ?? $tipo),
        ];
    }
}

if (!function_exists('sip_grafica_cloro_linea')) {
    /**
     * Nivel de cloro: por día de lectura, 3 horas (06/12/16) en `lecturas`.
     *
     * @param array{granja:string,campania:string,galpon:string} $opts
     * @return array<string,mixed>
     */
    function sip_grafica_cloro_linea(mysqli $conn, array $opts): array
    {
        $cfg = sip_grafica_peso_cloro_cfg('nivel_cloro');
        if ($cfg === null) {
            return ['success' => false, 'message' => 'Tipo no soportado'];
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

        $allRows = sip_grafica_cloro_fetch_fact_rows(
            $conn,
            $granja3,
            $campania3,
            $galBind,
            $galBind2,
            $tk,
            true
        );
        $rows = sip_grafica_cloro_resolve_fact_rows($allRows, $galBind, $galBind2);

        $fechaInicio = sip_grafica_fecha_inicio_cab($conn, $granja3, $campania3, $galBind, $galBind2);

        $entries = sip_grafica_cloro_entries_from_fact($rows);
        if (count($entries) <= 1) {
            $regnoEntries = sip_grafica_cloro_entries_from_regno(
                $conn,
                $granja3,
                $campania3,
                $galBind,
                $galBind2,
                true
            );
            if (count($regnoEntries) > count($entries)) {
                $entries = $regnoEntries;
            }
        }

        if ($entries === []) {
            return ['success' => false, 'message' => 'Sin datos para esa combinacion'];
        }

        $dataPorDia = [];
        $diaOrd = 0;
        foreach ($entries as $entry) {
            ++$diaOrd;
            $diaLabel = (int) ($entry['edadAve'] ?? 0);
            $diaOut = $diaLabel > 0 ? $diaLabel : $diaOrd;
            $diaPayload = sip_grafica_cloro_entry_a_dia(
                $entry,
                $diaOut,
                $pkDefault,
                $tk,
                $modoEval,
                $stdMap,
                $fechaInicio
            );
            if ($diaPayload === null) {
                --$diaOrd;
                continue;
            }
            if ($unidad === (string) $cfg['unidadDefault']) {
                $stdEdad = $diaLabel > 0 ? $diaLabel : $diaOrd;
                [, , $uMap] = sip_grafica_std_rango_desde_map($stdMap, $tk, $pkDefault, $stdEdad);
                if ($uMap !== '—' && !preg_match('/^\d/', $uMap)) {
                    $unidad = $uMap;
                }
            }
            $dataPorDia[] = $diaPayload;
        }

        if ($dataPorDia === []) {
            return ['success' => false, 'message' => 'Sin lecturas de cloro parseables'];
        }

        return [
            'success' => true,
            'dataPorDia' => $dataPorDia,
            'unidad' => $unidad,
            'horasAplicacion' => ['06:00', '12:00', '16:00'],
            'modoEvaluacion' => $modoEval,
            'tareaKey' => $tk,
            'tipo' => 'nivel_cloro',
            'nombre' => (string) ($cfg['label'] ?? 'Nivel de cloro'),
        ];
    }
}
