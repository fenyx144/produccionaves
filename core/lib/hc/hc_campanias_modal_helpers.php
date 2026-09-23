<?php
declare(strict_types=1);

/**
 * Campañas por granja/fecha — san_fact_historia_clinica_cab (modal HC).
 *
 * Regla única de listado:
 * - Solo cab con fecha_inicio real (sin inicio = cab incompleto, no se lista).
 * - fecha_fin NULL / vacía = lote abierto (en curso).
 * - Con periodo: solapa [fecha_inicio, fecha_fin] ∩ [desde, hasta].
 * - updated_at no interviene (un ETL reciente no “revive” campañas cerradas).
 */

if (!function_exists('hc_campania_norm_display')) {
    function hc_campania_norm_display(string $raw): string
    {
        $c = trim($raw);
        if ($c === '') {
            return '';
        }
        $digits = preg_replace('/\s+/', '', $c);
        if ($digits !== '' && ctype_digit($digits)) {
            return substr(str_pad($digits, 3, '0', STR_PAD_LEFT), -3);
        }

        return $c;
    }
}

if (!function_exists('hc_bulk_append_campania')) {
    function hc_bulk_append_campania(array &$byGranja, string $g3, string $campRaw): void
    {
        $g3 = substr(str_pad(trim($g3), 3, '0', STR_PAD_LEFT), 0, 3);
        if ($g3 === '') {
            return;
        }
        $c = hc_campania_norm_display($campRaw);
        if ($c === '' || $c === '000') {
            return;
        }
        if (!isset($byGranja[$g3])) {
            $byGranja[$g3] = [];
        }
        foreach ($byGranja[$g3] as $row) {
            $ex = hc_campania_norm_display((string) ($row['campania'] ?? ''));
            if ($ex === $c) {
                return;
            }
        }
        $byGranja[$g3][] = ['campania' => $c];
    }
}

if (!function_exists('hc_campanias_tabla_existe')) {
    function hc_campanias_tabla_existe(mysqli $conn, string $tabla): bool
    {
        static $cache = [];
        $k = spl_object_hash($conn) . '|' . $tabla;
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        if (function_exists('tabla_existe')) {
            return $cache[$k] = tabla_existe($conn, $tabla);
        }
        if (function_exists('sip_est2_repo_table_exists')) {
            return $cache[$k] = sip_est2_repo_table_exists($conn, $tabla);
        }
        $t = $conn->real_escape_string($tabla);
        $rs = @$conn->query("SHOW TABLES LIKE '{$t}'");

        return $cache[$k] = ($rs && $rs->num_rows > 0);
    }
}

if (!function_exists('hc_merge_campanias_desde_necropsias')) {
    function hc_merge_campanias_desde_necropsias(mysqli $conn, array &$byGranja, array $keys, ?array $rango): void
    {
        if ($keys === [] || !hc_campanias_tabla_existe($conn, 't_regnecropsia')) {
            return;
        }
        $chkTc = @$conn->query("SHOW COLUMNS FROM t_regnecropsia LIKE 'tcencos'");
        $tieneTcencos = $chkTc && $chkTc->num_rows > 0;
        $campExpr = $tieneTcencos
            ? 'TRIM(COALESCE(NULLIF(TRIM(tcampania), \'\'), CASE WHEN CHAR_LENGTH(TRIM(tcencos)) >= 6 THEN RIGHT(TRIM(tcencos), 3) ELSE \'\' END))'
            : 'TRIM(COALESCE(NULLIF(TRIM(tcampania), \'\'), \'\'))';
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT DISTINCT LPAD(LEFT(TRIM(tgranja), 3), 3, \'0\') AS g3,
            ' . $campExpr . ' AS campania_raw
            FROM t_regnecropsia
            WHERE LPAD(LEFT(TRIM(tgranja), 3), 3, \'0\') IN (' . $ph . ')';
        $bind = $keys;
        $types = str_repeat('s', count($keys));
        if ($rango !== null) {
            $sql .= ' AND DATE(tfectra) >= ? AND DATE(tfectra) <= ?';
            $bind[] = $rango['desde'];
            $bind[] = $rango['hasta'];
            $types .= 'ss';
        }
        $st = $conn->prepare($sql);
        if (!$st) {
            return;
        }
        $params = array_merge([$types], $bind);
        $refs = [];
        foreach ($params as $i => $_) {
            $refs[$i] = &$params[$i];
        }
        call_user_func_array([$st, 'bind_param'], $refs);
        $st->execute();
        $rs = $st->get_result();
        while ($rs && ($row = $rs->fetch_assoc())) {
            $g3 = trim((string) ($row['g3'] ?? ''));
            hc_bulk_append_campania($byGranja, $g3, (string) ($row['campania_raw'] ?? ''));
        }
        $st->close();
    }
}

if (!function_exists('hc_merge_campanias_desde_solicitud_det')) {
    function hc_merge_campanias_desde_solicitud_det(mysqli $conn, array &$byGranja, array $keys, ?array $rango): void
    {
        if ($keys === [] || !hc_campanias_tabla_existe($conn, 'san_fact_solicitud_det')) {
            return;
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $ref = 'RIGHT(LPAD(TRIM(CAST(codRef AS CHAR)), 8, \'0\'), 8)';
        $sql = 'SELECT DISTINCT LPAD(LEFT(' . $ref . ', 3), 3, \'0\') AS g3,
            SUBSTRING(' . $ref . ', 4, 3) AS campania
            FROM san_fact_solicitud_det
            WHERE TRIM(codRef) <> \'\'
              AND CHAR_LENGTH(TRIM(CAST(codRef AS CHAR))) >= 8
              AND LPAD(LEFT(' . $ref . ', 3), 3, \'0\') IN (' . $ph . ')';
        $bind = $keys;
        $types = str_repeat('s', count($keys));
        if ($rango !== null) {
            $chkF = @$conn->query("SHOW COLUMNS FROM san_fact_solicitud_det LIKE 'fecToma'");
            if ($chkF && $chkF->num_rows > 0) {
                $sql .= ' AND DATE(fecToma) >= ? AND DATE(fecToma) <= ?';
                $bind[] = $rango['desde'];
                $bind[] = $rango['hasta'];
                $types .= 'ss';
            }
        }
        $st = $conn->prepare($sql);
        if (!$st) {
            return;
        }
        $params = array_merge([$types], $bind);
        $refs = [];
        foreach ($params as $i => $_) {
            $refs[$i] = &$params[$i];
        }
        call_user_func_array([$st, 'bind_param'], $refs);
        $st->execute();
        $rs = $st->get_result();
        while ($rs && ($row = $rs->fetch_assoc())) {
            hc_bulk_append_campania($byGranja, (string) ($row['g3'] ?? ''), (string) ($row['campania'] ?? ''));
        }
        $st->close();
    }
}

if (!function_exists('hc_cab_sql_fecha_valida')) {
    /** Predicado SQL: columna fecha usable (no NULL / vacío / 0000-00-00). */
    function hc_cab_sql_fecha_valida(string $col): string
    {
        return "({$col} IS NOT NULL AND TRIM({$col}) <> '' AND {$col} <> '0000-00-00')";
    }
}

if (!function_exists('hc_cab_sql_lote_solapa_periodo')) {
    /**
     * Solape de lote con periodo. Placeholders en orden: hasta, desde
     * (fecha_inicio &lt;= hasta AND (fin abierto OR fecha_fin &gt;= desde)).
     */
    function hc_cab_sql_lote_solapa_periodo(): string
    {
        return hc_cab_sql_fecha_valida('`fecha_inicio`')
            . ' AND DATE(`fecha_inicio`) <= ?'
            . ' AND (NOT ' . hc_cab_sql_fecha_valida('`fecha_fin`') . ' OR DATE(`fecha_fin`) >= ?)';
    }
}

if (!function_exists('hc_campanias_stmt_append_g3_campania')) {
    /**
     * @param list<string> $bind
     */
    function hc_campanias_stmt_append_g3_campania(
        mysqli $conn,
        string $sql,
        string $types,
        array $bind,
        array &$byGranja
    ): void {
        $st = $conn->prepare($sql);
        if (!$st) {
            return;
        }
        if ($types !== '' && $bind !== []) {
            $params = array_merge([$types], $bind);
            $refs = [];
            foreach ($params as $i => $_) {
                $refs[$i] = &$params[$i];
            }
            call_user_func_array([$st, 'bind_param'], $refs);
        }
        $st->execute();
        $rs = $st->get_result();
        while ($rs && ($row = $rs->fetch_assoc())) {
            hc_bulk_append_campania(
                $byGranja,
                (string) ($row['g3'] ?? ''),
                (string) ($row['campania'] ?? '')
            );
        }
        $st->close();
    }
}

if (!function_exists('hc_campania_intervalo_solapa')) {
    /**
     * ¿El intervalo de lote [fi, ff] solapa [desde, hasta]?
     * Sin fecha_inicio → no listable / no solapa.
     * Sin fecha_fin (o $ffAbierta) → lote abierto hasta el infinito.
     */
    function hc_campania_intervalo_solapa(?string $fi, ?string $ff, string $desde, string $hasta, bool $ffAbierta = false): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            return false;
        }
        $fiN = null;
        if ($fi !== null && $fi !== '' && $fi !== '0000-00-00') {
            $fiN = preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($fi), $m) ? $m[1] : null;
        }
        if ($fiN === null) {
            return false;
        }
        $ffN = null;
        if (!$ffAbierta && $ff !== null && $ff !== '' && $ff !== '0000-00-00') {
            $ffN = preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($ff), $m) ? $m[1] : null;
        }
        $end = ($ffAbierta || $ffN === null) ? '9999-12-31' : $ffN;

        return $fiN <= $hasta && $end >= $desde;
    }
}

if (!function_exists('hc_merge_campanias_desde_hc_cab_todas')) {
    /** @param list<string> $keys */
    function hc_merge_campanias_desde_hc_cab_todas(mysqli $conn, array &$byGranja, array $keys): void
    {
        if ($keys === [] || !function_exists('sip_hc_cab_tabla_disponible') || !sip_hc_cab_tabla_disponible($conn)) {
            return;
        }
        $t = sip_hc_cab_tabla_lista();
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT DISTINCT LPAD(LEFT(TRIM(`granja`), 3), 3, \'0\') AS g3, TRIM(`campania`) AS campania
            FROM `' . $t . '`
            WHERE LPAD(LEFT(TRIM(`granja`), 3), 3, \'0\') IN (' . $ph . ')
              AND TRIM(`campania`) <> \'\'
              AND TRIM(`campania`) <> \'000\'';
        if (function_exists('sip_hc_cab_tiene_columnas_fecha') && sip_hc_cab_tiene_columnas_fecha($conn)) {
            $sql .= ' AND ' . hc_cab_sql_fecha_valida('`fecha_inicio`');
        }
        hc_campanias_stmt_append_g3_campania($conn, $sql, str_repeat('s', count($keys)), $keys, $byGranja);
    }
}

if (!function_exists('hc_merge_campanias_desde_hc_cab_rango')) {
    /** @param list<string> $keys */
    function hc_merge_campanias_desde_hc_cab_rango(mysqli $conn, array &$byGranja, array $keys, string $desde, string $hasta): void
    {
        if ($keys === [] || !function_exists('sip_hc_cab_tabla_disponible') || !sip_hc_cab_tabla_disponible($conn)) {
            return;
        }
        $t = sip_hc_cab_tabla_lista();
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT DISTINCT LPAD(LEFT(TRIM(`granja`), 3), 3, \'0\') AS g3, TRIM(`campania`) AS campania
            FROM `' . $t . '`
            WHERE LPAD(LEFT(TRIM(`granja`), 3), 3, \'0\') IN (' . $ph . ')
              AND TRIM(`campania`) <> \'\'
              AND TRIM(`campania`) <> \'000\'
              AND ' . hc_cab_sql_lote_solapa_periodo();
        $bind = array_merge($keys, [$hasta, $desde]);
        hc_campanias_stmt_append_g3_campania(
            $conn,
            $sql,
            str_repeat('s', count($keys)) . 'ss',
            $bind,
            $byGranja
        );

        if (!sip_hc_cab_tiene_columnas_fecha($conn)) {
            return;
        }

        /* Solo granjas aún vacías: complemento agrupado (cab/movi) con la misma regla de solape. */
        $faltan = [];
        foreach ($keys as $k) {
            if (empty($byGranja[$k])) {
                $faltan[] = $k;
            }
        }
        if ($faltan === []) {
            return;
        }

        $grupos = sip_hc_cab_fetch_agrupado_por_granja_campania($conn, $faltan);
        foreach ($grupos as $gk => $camps) {
            $g3 = sip_hc_cab_key_granja_parse((string) $gk);
            foreach ($camps as $item) {
                $camp = (string) ($item['campania'] ?? '');
                if ($camp === '' || $camp === '000') {
                    continue;
                }
                if (hc_campania_intervalo_solapa(
                    isset($item['fecha_inicio']) ? (string) $item['fecha_inicio'] : null,
                    isset($item['fecha_fin']) ? (string) $item['fecha_fin'] : null,
                    $desde,
                    $hasta
                )) {
                    hc_bulk_append_campania($byGranja, $g3, $camp);
                }
            }
        }
    }
}

if (!function_exists('hc_merge_campanias_desde_hc_cab')) {
    /**
     * Campañas desde san_fact_historia_clinica_cab (misma capa que cronograma / evidencias / temperatura).
     *
     * @param list<string> $keys
     * @param array{desde:string,hasta:string}|null $rango null = periodo TODOS
     */
    function hc_merge_campanias_desde_hc_cab(mysqli $conn, array &$byGranja, array $keys, ?array $rango): void
    {
        if ($keys === []) {
            return;
        }
        $hcLib = dirname(__DIR__) . '/sip_historia_clinica_cab_lib.php';
        if (!is_file($hcLib)) {
            return;
        }
        require_once $hcLib;
        if (!sip_hc_cab_tabla_disponible($conn)) {
            return;
        }

        if ($rango === null) {
            hc_merge_campanias_desde_hc_cab_todas($conn, $byGranja, $keys);

            return;
        }

        $desde = trim((string) ($rango['desde'] ?? ''));
        $hasta = trim((string) ($rango['hasta'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            return;
        }

        if (sip_hc_cab_tiene_columnas_fecha($conn)) {
            hc_merge_campanias_desde_hc_cab_rango($conn, $byGranja, $keys, $desde, $hasta);

            return;
        }

        foreach ([$desde, $hasta] as $fechaRef) {
            $porHc = sip_hc_cab_campanias_por_granja_en_fecha($conn, $fechaRef, [
                'granjas' => $keys,
                'solo_granja_6' => false,
                'max_campanias_por_granja' => null,
            ]);
            foreach ($porHc as $gk => $rows) {
                $g3 = sip_hc_cab_key_granja_parse((string) $gk);
                foreach ($rows as $row) {
                    hc_bulk_append_campania($byGranja, $g3, (string) ($row['campania'] ?? ''));
                }
            }
        }
    }
}

if (!function_exists('hc_merge_campanias_desde_reg_ingcascarilla')) {
    function hc_merge_campanias_desde_reg_ingcascarilla(mysqli $conn, array &$byGranja, array $keys, ?array $rango = null): void
    {
        if ($keys === [] || !hc_campanias_tabla_existe($conn, 'reg_ingcascarilla')) {
            return;
        }
        $chk = @$conn->query("SHOW COLUMNS FROM reg_ingcascarilla LIKE 'tepre'");
        if (!$chk || $chk->num_rows === 0) {
            return;
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = 'SELECT DISTINCT LPAD(LEFT(TRIM(tcencos), 3), 3, \'0\') AS g3,
            TRIM(RIGHT(TRIM(tcencos), 3)) AS campania
            FROM reg_ingcascarilla
            WHERE UPPER(TRIM(tepre)) = \'RS\'
              AND TRIM(tcencos) <> \'\'
              AND CHAR_LENGTH(TRIM(tcencos)) >= 6
              AND LPAD(LEFT(TRIM(tcencos), 3), 3, \'0\') IN (' . $ph . ')';
        $bind = $keys;
        $types = str_repeat('s', count($keys));
        if ($rango !== null) {
            $chkF = @$conn->query("SHOW COLUMNS FROM reg_ingcascarilla LIKE 'tfecha'");
            if ($chkF && $chkF->num_rows > 0) {
                $sql .= ' AND DATE(tfecha) >= ? AND DATE(tfecha) <= ?';
                $bind[] = $rango['desde'];
                $bind[] = $rango['hasta'];
                $types .= 'ss';
            }
        }
        $st = $conn->prepare($sql);
        if (!$st) {
            return;
        }
        $params = array_merge([$types], $bind);
        $refs = [];
        foreach ($params as $i => $_) {
            $refs[$i] = &$params[$i];
        }
        call_user_func_array([$st, 'bind_param'], $refs);
        $st->execute();
        $rs = $st->get_result();
        while ($rs && ($row = $rs->fetch_assoc())) {
            hc_bulk_append_campania($byGranja, (string) ($row['g3'] ?? ''), (string) ($row['campania'] ?? ''));
        }
        $st->close();
    }
}

if (!function_exists('hc_campanias_by_granjas_en_fecha')) {
    /**
     * @param list<string> $keysGranja3 códigos granja 3 dígitos
     * @return array<string, list<array{campania:string}>>
     */
    function hc_campanias_by_granjas_en_fecha(mysqli $conn, array $keysGranja3, string $fechaYmd): array
    {
        $keys = [];
        foreach ($keysGranja3 as $p) {
            $g3 = substr(str_pad(trim((string) $p), 3, '0', STR_PAD_LEFT), 0, 3);
            if ($g3 !== '' && $g3[0] === '6') {
                $keys[$g3] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);
        $byGranja = [];
        foreach ($keys as $k) {
            $byGranja[$k] = [];
        }
        if ($keys === [] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd)) {
            return $byGranja;
        }

        $rango = ['desde' => $fechaYmd, 'hasta' => $fechaYmd];
        hc_merge_campanias_desde_hc_cab($conn, $byGranja, $keys, $rango);

        foreach ($byGranja as $gk => $rows) {
            usort($byGranja[$gk], static function (array $a, array $b): int {
                return strcmp((string) ($a['campania'] ?? ''), (string) ($b['campania'] ?? ''));
            });
        }

        return $byGranja;
    }
}
