<?php
declare(strict_types=1);

if (!function_exists('hc_granjas_listar_para_selector')) {
    /**
     * Lista granjas (código 6xx) con nombre y zona/subzona, alineado al listado del CRUD estándares granja.
     *
     * @return list<array{granja:string,nombre:string,zona:string,subzona:string}>
     */
    function hc_granjas_listar_para_selector(mysqli $conn, string $zonaFiltro = ''): array
    {
        $excluidas = "('624','640','641')";
        $zonaFiltro = trim($zonaFiltro);
        $sql = "SELECT
            TRIM(p.granja) AS granja,
            COALESCE(NULLIF(MAX(TRIM(rg.nombre)), ''), MAX(TRIM(p.granja))) AS nombre_granja,
            COALESCE(NULLIF(MAX(TRIM(zs.zona)), ''), '') AS zona,
            COALESCE(NULLIF(MAX(TRIM(zs.subzona)), ''), '') AS subzona
        FROM (
            SELECT DISTINCT TRIM(id_granja) AS granja
            FROM pi_dim_detalles
            WHERE TRIM(id_granja) <> ''
              AND TRIM(id_granja) LIKE '6%'
              AND TRIM(id_granja) NOT IN {$excluidas}
        ) p
        LEFT JOIN (
            SELECT LEFT(TRIM(tcencos), 3) AS codigo, MAX(TRIM(tnomcen)) AS nombre
            FROM regcencosgalpones WHERE TRIM(tcencos) <> '' GROUP BY LEFT(TRIM(tcencos), 3)
        ) rg ON CONVERT(rg.codigo USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(LEFT(TRIM(p.granja),3) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        LEFT JOIN (
            SELECT
                TRIM(det.id_granja) AS codigo,
                MAX(CASE WHEN UPPER(TRIM(car.nombre)) = 'ZONA' THEN TRIM(det.dato) END) AS zona,
                MAX(CASE WHEN UPPER(TRIM(car.nombre)) = 'SUBZONA' THEN TRIM(det.dato) END) AS subzona
            FROM pi_dim_detalles det
            INNER JOIN pi_dim_caracteristicas car ON car.id = det.id_caracteristica
            WHERE TRIM(det.id_granja) <> ''
              AND TRIM(det.id_granja) LIKE '6%'
              AND TRIM(det.id_granja) NOT IN {$excluidas}
              AND UPPER(TRIM(car.nombre)) IN ('ZONA', 'SUBZONA')
            GROUP BY TRIM(det.id_granja)
        ) zs ON CONVERT(TRIM(zs.codigo) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(p.granja) USING utf8mb4) COLLATE utf8mb4_unicode_ci";

        if ($zonaFiltro !== '') {
            $zEsc = $conn->real_escape_string($zonaFiltro);
            $sql .= "
        INNER JOIN (
            SELECT TRIM(det.id_granja) AS codigo
            FROM pi_dim_detalles det
            INNER JOIN pi_dim_caracteristicas car ON car.id = det.id_caracteristica
            WHERE UPPER(TRIM(car.nombre)) = 'ZONA'
              AND CONVERT(TRIM(det.dato) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT('{$zEsc}' USING utf8mb4) COLLATE utf8mb4_unicode_ci
        ) fz ON CONVERT(TRIM(fz.codigo) USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(TRIM(p.granja) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        }
        $sql .= ' GROUP BY TRIM(p.granja) ORDER BY TRIM(p.granja)';

        $res = $conn->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $data[] = [
                    'granja' => trim((string) ($r['granja'] ?? '')),
                    'nombre' => trim((string) ($r['nombre_granja'] ?? $r['granja'] ?? '')),
                    'zona' => trim((string) ($r['zona'] ?? '')),
                    'subzona' => trim((string) ($r['subzona'] ?? '')),
                ];
            }
        }

        return $data;
    }
}