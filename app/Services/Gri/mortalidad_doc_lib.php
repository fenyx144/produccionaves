<?php
declare(strict_types=1);

if (!function_exists('mort_tipo_a_digito')) {
    function mort_tipo_a_digito(string $tipoMortalidad): string
    {
        static $map = [
            'incubacion' => '1',
            'transporte' => '2',
            'produccion' => '3',
            'despacho' => '4',
        ];
        $tipo = strtolower(trim($tipoMortalidad));
        if (!isset($map[$tipo])) {
            throw new InvalidArgumentException('tipoMortalidad inválido: ' . $tipoMortalidad);
        }
        return $map[$tipo];
    }
}

if (!function_exists('mort_normalizar_granja3')) {
    function mort_normalizar_granja3(string $granja): string
    {
        $digits = preg_replace('/\D/', '', $granja) ?? '';
        if (strlen($digits) >= 3) {
            return substr($digits, 0, 3);
        }
        return str_pad($digits, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('mort_generar_serie')) {
    function mort_generar_serie(string $granja, string $tipoMortalidad): string
    {
        $g3 = mort_normalizar_granja3($granja);
        return $g3 . '-' . mort_tipo_a_digito($tipoMortalidad);
    }
}

if (!function_exists('mort_codigo_display')) {
    function mort_codigo_display(string $doc, string $serie, string $numero): string
    {
        return trim($doc) . ' ' . trim($serie) . '-' . trim($numero);
    }
}

if (!function_exists('mort_serie_a_tserie')) {
    /**
     * Serie GI (ej. 621-1) → tserie cabe_zonas/movi_zonas (4 chars, sin guión).
     * (Copia local de mort_zonas_tserie para no crear dependencia circular.)
     */
    function mort_serie_a_tserie(string $serieGi): string
    {
        $s = strtoupper(str_replace(['-', ' '], '', trim($serieGi)));

        return substr($s, 0, 4);
    }
}

if (!function_exists('mort_serie_desde_tserie')) {
    /**
     * tserie (ej. 6211) → serie GI con guión (ej. 621-1).
     */
    function mort_serie_desde_tserie(string $tserie): string
    {
        $s = trim($tserie);
        if ($s === '') {
            return '';
        }
        if (strlen($s) <= 3) {
            return $s;
        }

        return substr($s, 0, 3) . '-' . substr($s, 3);
    }
}

if (!function_exists('mort_numero_desde_tnumfac')) {
    /**
     * tnumfac = tserie (4 chars) + correlativo (7 dígitos).
     * Devuelve el correlativo en el formato histórico del fact (6 dígitos).
     */
    function mort_numero_desde_tnumfac($tnumfac): string
    {
        $s = trim((string) $tnumfac);
        $num = substr($s, -7);
        $num = ltrim($num, '0');
        if ($num === '') {
            $num = '0';
        }

        return str_pad($num, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('mort_siguiente_numero')) {
    /**
     * Correlativo por (doc, serie) calculado desde cabe_zonas (única fuente de verdad).
     * Usa LOCK TABLES (MyISAM-compatible).
     */
    function mort_siguiente_numero(mysqli $conn, string $doc = 'GI', string $serie = ''): string
    {
        $doc = trim($doc) !== '' ? trim($doc) : 'GI';
        if ($serie === '') {
            throw new InvalidArgumentException('serie requerida para correlativo');
        }
        $tserie = mort_serie_a_tserie($serie);

        $locked = @mysqli_query($conn, 'LOCK TABLES cabe_zonas WRITE');
        try {
            $stmt = $conn->prepare(
                'SELECT MAX(CAST(RIGHT(CAST(tnumfac AS CHAR), 7) AS UNSIGNED)) AS mx
                 FROM cabe_zonas WHERE tdoc = ? AND tserie = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Error prepare correlativo: ' . $conn->error);
            }
            $stmt->bind_param('ss', $doc, $tserie);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            $max = isset($row['mx']) ? (int) $row['mx'] : 0;
            $next = $max + 1;

            return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        } finally {
            if ($locked) {
                @mysqli_query($conn, 'UNLOCK TABLES');
            }
        }
    }
}

if (!function_exists('mort_fecha_hora_desde_record')) {
    function mort_fecha_hora_desde_record(array $record): string
    {
        $fecha = trim((string) ($record['tdate_trans'] ?? ''));
        $hora = trim((string) ($record['ttime_trans'] ?? ''));
        if ($fecha !== '' && $hora !== '') {
            return $fecha . ' ' . $hora;
        }
        if ($fecha !== '') {
            return $fecha . ' 00:00:00';
        }
        return date('Y-m-d H:i:s');
    }
}
