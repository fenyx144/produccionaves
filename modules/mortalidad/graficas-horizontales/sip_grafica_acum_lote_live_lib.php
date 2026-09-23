<?php

declare(strict_types=1);

if (!function_exists('sip_grafica_acum_usa_edad_muestreo')) {
    /** Tipos horizontales que filtran por edad de pesaje (no semana). */
    function sip_grafica_acum_usa_edad_muestreo(string $tipo): bool
    {
        $t = strtolower(trim($tipo));

        return $t === 'pesaje_pollo' || $t === 'cv_peso' || $t === 'ganancia_peso' || $t === 'cv_ganancia';
    }
}

if (!function_exists('sip_grafica_acum_edad_muestreo_ganancia_semana')) {
    /** Semana de ganancia → edad del muestreo (7n+1), misma regla que el ETL. */
    function sip_grafica_acum_edad_muestreo_ganancia_semana(int $semana): int
    {
        if ($semana < 1) {
            return 0;
        }

        return ($semana * 7) + 1;
    }
}

if (!function_exists('sip_grafica_acum_movi_zonas_fechas_subquery')) {
    /**
     * Fechas de lote desde movi_zonas (E001 ingreso / S700 liquidación).
     * Misma lógica que sincronizar_cabeceras en etl_11.php.
     */
    function sip_grafica_acum_movi_zonas_fechas_subquery(): string
    {
        return "(SELECT
                    TRIM(`tcencos`) AS tcencos,
                    TRIM(`tcodint`) AS galpon,
                    DATE(MAX(IF(`tcodtra` = 'E001' AND `tline` = '001', `tfectra`, NULL))) AS fecha_inicio,
                    DATE(MAX(IF(`tcodtra` = 'S700' AND `tline` = '001' AND `tliquidar` = 'S', `tfectra`, NULL))) AS fecha_fin
                FROM `movi_zonas`
                WHERE `tcodtra` IN ('E001', 'S700')
                  AND `tline` = '001'
                  AND LENGTH(TRIM(`tcencos`)) = 6
                  AND LEFT(TRIM(`tcencos`), 1) = '6'
                  AND LEFT(TRIM(`tcencos`), 3) <> '650'
                  AND RIGHT(TRIM(`tcencos`), 3) <> '000'
                GROUP BY TRIM(`tcencos`), TRIM(`tcodint`))";
    }
}

if (!function_exists('sip_grafica_acum_muestreo_lote_join')) {
    /** Join muestreoaves → cabecera fact o, si no existe, fechas live desde movi_zonas. */
    function sip_grafica_acum_muestreo_lote_join(string $aliasA = 'a', string $aliasC = 'c', string $aliasMz = 'mz'): string
    {
        $mzSub = sip_grafica_acum_movi_zonas_fechas_subquery();

        return "LEFT JOIN `san_fact_historia_clinica_cab` {$aliasC}
                    ON TRIM({$aliasA}.`tcencos`) = CONCAT(TRIM({$aliasC}.`granja`), TRIM({$aliasC}.`campania`))
                   AND (
                        TRIM({$aliasA}.`tcodint`) = TRIM(CAST({$aliasC}.`galpon` AS CHAR))
                        OR CAST(TRIM({$aliasA}.`tcodint`) AS UNSIGNED) = CAST(TRIM(CAST({$aliasC}.`galpon` AS CHAR)) AS UNSIGNED)
                   )
                LEFT JOIN {$mzSub} {$aliasMz}
                    ON TRIM({$aliasA}.`tcencos`) = {$aliasMz}.`tcencos`
                   AND TRIM({$aliasA}.`tcodint`) = {$aliasMz}.`galpon`";
    }
}

if (!function_exists('sip_grafica_acum_lote_fecha_inicio_sql')) {
    function sip_grafica_acum_lote_fecha_inicio_sql(string $aliasC = 'c', string $aliasMz = 'mz'): string
    {
        return "DATE(COALESCE(NULLIF({$aliasC}.`fecha_inicio`, '0000-00-00'), {$aliasMz}.`fecha_inicio`))";
    }
}

if (!function_exists('sip_grafica_acum_lote_fecha_fin_sql')) {
    function sip_grafica_acum_lote_fecha_fin_sql(string $aliasC = 'c', string $aliasMz = 'mz'): string
    {
        return "DATE(COALESCE(NULLIF(NULLIF({$aliasC}.`fecha_fin`, ''), '0000-00-00'), {$aliasMz}.`fecha_fin`))";
    }
}

if (!function_exists('sip_grafica_acum_lote_tiene_fecha_sql')) {
    function sip_grafica_acum_lote_tiene_fecha_sql(string $aliasC = 'c', string $aliasMz = 'mz'): string
    {
        $fi = sip_grafica_acum_lote_fecha_inicio_sql($aliasC, $aliasMz);

        return "({$fi} IS NOT NULL AND {$fi} <> '0000-00-00')";
    }
}

if (!function_exists('sip_grafica_acum_lote_sql_overlap')) {
    /**
     * Solape del lote con el periodo usando cab fact o movi_zonas.
     * Placeholders: hasta, desde.
     */
    function sip_grafica_acum_lote_sql_overlap(string $aliasC = 'c', string $aliasMz = 'mz'): string
    {
        $fi = sip_grafica_acum_lote_fecha_inicio_sql($aliasC, $aliasMz);
        $ff = sip_grafica_acum_lote_fecha_fin_sql($aliasC, $aliasMz);

        return sip_grafica_acum_lote_tiene_fecha_sql($aliasC, $aliasMz) . "
            AND {$fi} <= ?
            AND ({$ff} IS NULL OR {$ff} = '0000-00-00' OR {$ff} >= ?)";
    }
}

if (!function_exists('sip_grafica_acum_muestreo_fecha_pesaje_sql')) {
    function sip_grafica_acum_muestreo_fecha_pesaje_sql(string $aliasA = 'a', string $aliasC = 'c', string $aliasMz = 'mz'): string
    {
        $fi = sip_grafica_acum_lote_fecha_inicio_sql($aliasC, $aliasMz);

        return "DATE_ADD({$fi}, INTERVAL (CASE WHEN CAST({$aliasA}.`tedad` AS SIGNED) <= 0 THEN 0 ELSE CAST({$aliasA}.`tedad` AS SIGNED) - 1 END) DAY)";
    }
}

if (!function_exists('sip_grafica_acum_muestreo_tablas_disponibles')) {
    function sip_grafica_acum_muestreo_tablas_disponibles(mysqli $conn): bool
    {
        if (!sip_grafica_table_exists($conn, 'muestreoaves')) {
            return false;
        }

        return sip_grafica_table_exists($conn, 'san_fact_historia_clinica_cab')
            || sip_grafica_table_exists($conn, 'movi_zonas');
    }
}
