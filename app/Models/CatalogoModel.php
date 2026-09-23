<?php

declare(strict_types=1);

namespace App\Models;

/** Catálogos y consultas de lectura para la app de mortalidad. */
final class CatalogoModel
{
    private static function filas(\mysqli $conn, string $sql): array
    {
        $res = mysqli_query($conn, $sql);
        $rows = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** Tablas de catálogo para descarga offline (filtro por lista de tablas). */
    public static function tablasDescarga(\mysqli $conn, array $tablas): array
    {
        $data = [];
        $incluye = static function (string $tabla) use ($tablas): bool {
            return $tablas === [] || in_array($tabla, $tablas, true);
        };

        if ($incluye('laboratorios')) {
            $data['laboratorios'] = self::filas($conn, 'SELECT codigo, nombre FROM san_dim_laboratorio ORDER BY nombre DESC');
        }
        if ($incluye('emp_trans')) {
            $data['emp_trans'] = self::filas($conn, 'SELECT codigo, nombre FROM san_dim_emptrans ORDER BY nombre DESC');
        }
        if ($incluye('usuarios')) {
            $data['usuarios'] = self::filas($conn, "SELECT codigo, nombre, libtri, HEX(password) AS password_hex FROM usuario WHERE estado = 'A'");
        }
        if ($incluye('conempre')) {
            $data['conempre'] = self::filas($conn, 'SELECT epre, enom FROM conempre');
        }
        if ($incluye('muestras')) {
            $data['muestras'] = self::filas($conn, 'SELECT * FROM san_dim_tipo_muestra ORDER BY codigo ASC');
        }
        if ($incluye('paquetes')) {
            $data['paquetes'] = self::filas($conn, 'SELECT * FROM san_dim_paquete ORDER BY codigo DESC');
        }
        if ($incluye('analisis')) {
            $data['analisis'] = self::filas($conn, 'SELECT * FROM san_dim_analisis ORDER BY codigo DESC');
        }
        return $data;
    }

    /** Motivos de mortalidad agrupados por tipo (transporte, produccion, despacho). */
    public static function motivos(\mysqli $conn): array
    {
        $codigosPorTipo = [
            'transporte' => ['01', '02'],
            'produccion' => ['03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '15', '16'],
            'despacho' => ['17', '18', '19', '05', '15'],
        ];
        $rows = self::filas($conn, 'SELECT tcod_mort, tnom_mort FROM regmotivo_mortalidadgrs ORDER BY tnom_mort');

        $motivosPorTipo = [
            'transporte' => [],
            'produccion' => [],
            'despacho' => [],
        ];
        $motivos = [];
        $seenUnion = [];
        foreach ($rows as $row) {
            $code = str_pad(trim((string) ($row['tcod_mort'] ?? '')), 2, '0', STR_PAD_LEFT);
            $item = [
                'tcod_mort' => $code,
                'tnom_mort' => mb_convert_encoding((string) ($row['tnom_mort'] ?? ''), 'UTF-8', 'ISO-8859-1'),
            ];
            foreach ($codigosPorTipo as $tipo => $codigos) {
                if (in_array($code, $codigos, true)) {
                    $motivosPorTipo[$tipo][] = $item;
                }
            }
            if (!isset($seenUnion[$code])) {
                $seenUnion[$code] = true;
                $motivos[] = $item;
            }
        }
        return [
            'motivos_por_tipo' => $motivosPorTipo,
            'motivos' => $motivos,
            'total' => count($motivos),
        ];
    }

    /** Cencos con galpones y edad estimada por fecha de ingreso. */
    public static function cencosGalpones(\mysqli $conn): array
    {
        $sql = "
            SELECT c.codigo, c.nombre, g.tcodint,
                COALESCE(m1.fec_ing) AS fec_ing_min
            FROM ccos AS c
                INNER JOIN regcencosgalpones AS g ON LEFT(c.codigo, 3) = g.tcencos
                LEFT JOIN (
                    SELECT tcencos, tcodint, MAX(fec_ing) AS fec_ing
                    FROM maes_zonas USE INDEX (tcodigo)
                    WHERE tcodigo IN ('P0001001', 'P0001002')
                      AND fec_ing IS NOT NULL
                    GROUP BY tcencos, tcodint
                ) AS m1 ON c.codigo = m1.tcencos AND g.tcodint = m1.tcodint
            WHERE LEFT(c.codigo, 1) = '6'
              AND RIGHT(c.codigo, 3) <> '000'
              AND c.swac = 'A'
              AND CHAR_LENGTH(c.codigo) = 6
              AND LEFT(c.codigo, 3) NOT IN ('668', '669', '650', '680')
            ORDER BY c.codigo ASC, g.tcodint ASC
        ";
        $res = mysqli_query($conn, $sql);
        if (!$res) {
            throw new \RuntimeException('Error al obtener granjas');
        }

        $cencosMap = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $codigo = (string) ($row['codigo'] ?? '');
            if ($codigo === '') {
                continue;
            }
            if (!isset($cencosMap[$codigo])) {
                $cencosMap[$codigo] = [
                    'codigo' => $codigo,
                    'nombre' => mb_convert_encoding((string) ($row['nombre'] ?? ''), 'UTF-8', 'ISO-8859-1'),
                    'edad' => '0',
                    'galpones' => [],
                ];
            }
            if (($row['tcodint'] ?? '') !== '') {
                $fecIngMin = $row['fec_ing_min'] ?? null;
                if ($fecIngMin && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecIngMin)) {
                    if ((int) substr((string) $fecIngMin, 0, 4) <= 1900) {
                        $fecIngMin = null;
                    }
                }
                $galponEdad = '0';
                if ($fecIngMin) {
                    $diff = date_diff(date_create((string) $fecIngMin), date_create('now'));
                    $galponEdad = (string) ($diff->days + 1);
                }
                $cencosMap[$codigo]['galpones'][] = [
                    'tcodint' => (string) $row['tcodint'],
                    'fec_ing_min' => $fecIngMin,
                    'edad' => $galponEdad,
                ];
            }
        }

        foreach ($cencosMap as &$cenco) {
            $edades = array_values(array_filter(
                array_map(static function ($g) {
                    return (int) ($g['edad'] ?? 0);
                }, $cenco['galpones']),
                static function ($e) {
                    return $e > 0;
                }
            ));
            if ($edades !== []) {
                $promedio = array_sum($edades) / count($edades);
                $cenco['edad'] = (string) (int) round($promedio);
            }
        }
        unset($cenco);

        $cencos = array_values($cencosMap);
        usort($cencos, static function ($a, $b) {
            return strcmp($a['nombre'] ?? '', $b['nombre'] ?? '');
        });
        return ['cencos' => $cencos, 'total' => count($cencos)];
    }

    /** Stock de pollos por cenco/galpón en una fecha (usa mort_cantidad_pollos_query). */
    public static function cantidadPollos(\mysqli $conn, string $tcencos, string $fecha, ?string $galpon): array
    {
        $items = \mort_cantidad_pollos_query($conn, $tcencos, $fecha, $galpon);
        return ['items' => $items, 'total' => count($items)];
    }

    /** Stock de pollos por varios cencos en una ventana de 7 días. */
    public static function cantidadPollosBatch(\mysqli $conn, array $tcencosList, string $fechaCentral): array
    {
        $fechas = [];
        $baseDt = \DateTime::createFromFormat('Y-m-d', $fechaCentral);
        if (!$baseDt) {
            $baseDt = new \DateTime();
        }
        for ($d = 0; $d <= 7; $d++) {
            $dt = clone $baseDt;
            if ($d > 0) {
                $dt->modify("+{$d} days");
            }
            $fechas[] = $dt->format('Y-m-d');
        }

        $allItems = [];
        foreach ($tcencosList as $tcencos) {
            foreach ($fechas as $fecha) {
                try {
                    $rows = \mort_cantidad_pollos_query($conn, $tcencos, $fecha, null);
                } catch (\Throwable $e) {
                    continue;
                }
                foreach ($rows as $row) {
                    $tcodint = (string) ($row['tcodint'] ?? '');
                    if ($tcodint === '') {
                        continue;
                    }
                    $key = "{$tcencos}_{$tcodint}_{$fecha}";
                    if (!isset($allItems[$key])) {
                        $allItems[$key] = [];
                    }
                    $allItems[$key][] = $row;
                }
            }
        }
        return [
            'items' => empty($allItems) ? new \stdClass() : $allItems,
            'total_tcencos' => count($tcencosList),
            'total_fechas' => count($fechas),
        ];
    }

    /** Bloques de liquidación de auditoría (san_mortalidad_auditoria). */
    public static function liquidacionBloques(\mysqli $conn, array $filtros): array
    {
        $where = '';
        $params = [];
        $types = '';

        if (($filtros['usuario'] ?? '') !== '') {
            $where .= ' AND a.usuarioRegistro = ? ';
            $params[] = $filtros['usuario'];
            $types .= 's';
        }
        if (($filtros['fecha_desde'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filtros['fecha_desde'])) {
            $where .= ' AND a.fecha >= ? ';
            $params[] = $filtros['fecha_desde'];
            $types .= 's';
        }
        if (($filtros['fecha_hasta'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filtros['fecha_hasta'])) {
            $where .= ' AND a.fecha <= ? ';
            $params[] = $filtros['fecha_hasta'];
            $types .= 's';
        }

        $sql = "
            SELECT
                a.id,
                a.usuarioRegistro,
                a.registros,
                a.totalRegistros,
                a.totalAves,
                a.observaciones,
                a.evidencia,
                a.fecha,
                a.fechaHoraRegistro
            FROM san_mortalidad_auditoria a
            WHERE 1=1 {$where}
            ORDER BY a.fechaHoraRegistro DESC
            LIMIT 200
        ";

        if ($params === []) {
            $res = mysqli_query($conn, $sql);
            if (!$res) {
                throw new \RuntimeException('Error SQL: ' . mysqli_error($conn));
            }
        } else {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException('Error preparando consulta: ' . $conn->error);
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Error ejecutando consulta: ' . $stmt->error);
            }
            $res = $stmt->get_result();
            if (!$res) {
                throw new \RuntimeException('Error obteniendo resultado: ' . $stmt->error);
            }
        }

        $bloquesRaw = [];
        $allUuids = [];
        $bloqueUuidsMap = [];
        while ($row = $res->fetch_assoc()) {
            $auditId = (string) ($row['id'] ?? '');
            $regs = self::decodificarRegistros($row['registros'] ?? '[]');
            $uuids = [];
            foreach ($regs as $grupo) {
                if (!is_array($grupo)) {
                    continue;
                }
                $rawUuids = $grupo['registro_uuids'] ?? [];
                if (is_array($rawUuids)) {
                    foreach ($rawUuids as $u) {
                        $uuid = trim((string) $u);
                        if ($uuid !== '') {
                            $uuids[] = $uuid;
                            $allUuids[$uuid] = true;
                        }
                    }
                }
            }
            $bloqueUuidsMap[$auditId] = $uuids;
            $bloquesRaw[] = $row;
        }

        // Nombre legible de usuario (código -> nombre) desde la tabla usuario.
        $usuarioNombreMap = [];
        $usuariosUnicos = [];
        foreach ($bloquesRaw as $filaBloque) {
            $uc = strtoupper(trim((string) ($filaBloque['usuarioRegistro'] ?? '')));
            if ($uc !== '') {
                $usuariosUnicos[$uc] = true;
            }
        }
        if (!empty($usuariosUnicos)) {
            $inUsuarios = self::inSql($conn, array_keys($usuariosUnicos));
            $qUsuarios = mysqli_query($conn, "SELECT codigo, nombre FROM usuario WHERE codigo IN ({$inUsuarios})");
            if ($qUsuarios) {
                while ($rUsu = mysqli_fetch_assoc($qUsuarios)) {
                    $cUsu = strtoupper(trim((string) ($rUsu['codigo'] ?? '')));
                    $nUsu = trim((string) ($rUsu['nombre'] ?? ''));
                    if ($cUsu !== '' && $nUsu !== '') {
                        $usuarioNombreMap[$cUsu] = $nUsu;
                    }
                }
            }
        }

        $uuidSexoMap = [];
        $allUuidList = array_keys($allUuids);
        if (!empty($allUuidList)) {
            $uuidInDet = self::inSql($conn, $allUuidList);
            $qDet = mysqli_query($conn, "
                SELECT cz.external_id AS cabId, mz.tcodigo AS sexo, SUM(mz.tcantid) AS total
                FROM movi_zonas mz
                INNER JOIN cabe_zonas cz ON mz.mark = cz.mark AND mz.treg = cz.treg
                    AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
                WHERE cz.external_id IN ({$uuidInDet})
                GROUP BY cz.external_id, mz.tcodigo
            ");
            if ($qDet) {
                while ($r = mysqli_fetch_assoc($qDet)) {
                    $cabId = (string) ($r['cabId'] ?? '');
                    if (!isset($uuidSexoMap[$cabId])) {
                        $uuidSexoMap[$cabId] = ['machos' => 0, 'hembras' => 0];
                    }
                    $sexo = strtoupper(trim((string) ($r['sexo'] ?? ''))) === 'P0001002' ? 'H' : 'M';
                    $total = (int) ($r['total'] ?? 0);
                    if ($sexo === 'M') {
                        $uuidSexoMap[$cabId]['machos'] += $total;
                    } elseif ($sexo === 'H') {
                        $uuidSexoMap[$cabId]['hembras'] += $total;
                    }
                }
            }
        }

        $uuidGranjaMap = [];
        if (!empty($allUuidList)) {
            $uuidInCab = self::inSql($conn, $allUuidList);
            $qCab = mysqli_query($conn, "
                SELECT cz.external_id AS id, LEFT(MAX(mz.tcencos), 3) AS granja
                FROM cabe_zonas cz
                INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                    AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
                WHERE cz.external_id IN ({$uuidInCab})
                GROUP BY cz.external_id
            ");
            if ($qCab) {
                while ($r = mysqli_fetch_assoc($qCab)) {
                    $hid = (string) ($r['id'] ?? '');
                    $granjaCode = trim((string) ($r['granja'] ?? ''));
                    if ($hid !== '' && $granjaCode !== '') {
                        $uuidGranjaMap[$hid] = $granjaCode;
                    }
                }
            }
        }

        $granjaNombreMap = [];
        $granjaCodesUnicos = [];
        foreach ($uuidGranjaMap as $code) {
            $prefix = strtoupper(substr($code, 0, 3));
            if ($prefix !== '') {
                $granjaCodesUnicos[$prefix] = true;
            }
        }
        if (!empty($granjaCodesUnicos)) {
            $inG = self::inSql($conn, array_keys($granjaCodesUnicos));
            $qG = mysqli_query($conn, "
                SELECT DISTINCT LEFT(codigo, 3) AS prefijo, descri
                FROM ccos
                WHERE LEFT(codigo, 3) IN ({$inG})
                  AND swac = 'A'
            ");
            if ($qG) {
                while ($r = mysqli_fetch_assoc($qG)) {
                    $prefijo = strtoupper(trim((string) ($r['prefijo'] ?? '')));
                    $descri = trim((string) ($r['descri'] ?? ''));
                    if ($prefijo !== '') {
                        $granjaNombreMap[$prefijo] = $descri !== '' ? $descri : $prefijo;
                    }
                }
            }
            foreach ($granjaCodesUnicos as $gc => $_) {
                if (!isset($granjaNombreMap[$gc])) {
                    $granjaNombreMap[$gc] = $gc;
                }
            }
        }

        $uuidsGranja = null;
        if (($filtros['granja_codigo'] ?? '') !== '' || ($filtros['campania_codigo'] ?? '') !== '' || ($filtros['galpon_codigo'] ?? '') !== '') {
            $uuidList = array_keys($allUuids);
            if (!empty($uuidList)) {
                $uuidIn = self::inSql($conn, $uuidList);
                $whereCab = '';
                if (($filtros['granja_codigo'] ?? '') !== '') {
                    $gEsc = mysqli_real_escape_string($conn, (string) $filtros['granja_codigo']);
                    $whereCab .= " AND LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}' ";
                }
                if (($filtros['campania_codigo'] ?? '') !== '') {
                    $cEsc = mysqli_real_escape_string($conn, (string) $filtros['campania_codigo']);
                    $whereCab .= " AND RIGHT(TRIM(mz.tcencos), 3) = '{$cEsc}' ";
                }
                if (($filtros['galpon_codigo'] ?? '') !== '') {
                    $galEsc = mysqli_real_escape_string($conn, (string) $filtros['galpon_codigo']);
                    $whereCab .= " AND mz.tcodint = '{$galEsc}' ";
                }
                $sqlCab = "
                    SELECT DISTINCT cz.external_id AS id
                    FROM cabe_zonas cz
                    INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                        AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
                    WHERE cz.external_id IN ({$uuidIn}) {$whereCab}
                ";
                $resCab = mysqli_query($conn, $sqlCab);
                $uuidsGranja = [];
                if ($resCab) {
                    while ($r = mysqli_fetch_assoc($resCab)) {
                        $uuidsGranja[(string) ($r['id'] ?? '')] = true;
                    }
                }
            } else {
                $uuidsGranja = [];
            }
        }

        $bloques = [];
        foreach ($bloquesRaw as $row) {
            $auditId = (string) ($row['id'] ?? '');
            $regs = self::decodificarRegistros($row['registros'] ?? '[]');

            if ($uuidsGranja !== null) {
                $bloqueUuids = $bloqueUuidsMap[$auditId] ?? [];
                $coincide = false;
                foreach ($bloqueUuids as $uuid) {
                    if (isset($uuidsGranja[$uuid])) {
                        $coincide = true;
                        break;
                    }
                }
                if (!$coincide) {
                    continue;
                }
            }

            foreach ($regs as &$grupo) {
                if (!is_array($grupo)) {
                    continue;
                }
                $uuidsGrupo = $grupo['registro_uuids'] ?? [];
                $machos = 0;
                $hembras = 0;
                if (is_array($uuidsGrupo)) {
                    foreach ($uuidsGrupo as $uuidG) {
                        $uuidG = trim((string) $uuidG);
                        if ($uuidG !== '' && isset($uuidSexoMap[$uuidG])) {
                            $machos += (int) ($uuidSexoMap[$uuidG]['machos'] ?? 0);
                            $hembras += (int) ($uuidSexoMap[$uuidG]['hembras'] ?? 0);
                        }
                    }
                }
                $grupo['cantMachos'] = $machos;
                $grupo['cantHembras'] = $hembras;
            }
            unset($grupo);

            $granjasBloque = [];
            $debugGroupKeys = [];
            foreach ($regs as $i => $grupo) {
                if (!is_array($grupo)) {
                    continue;
                }
                if ($i === 0) {
                    $debugGroupKeys = array_keys($grupo);
                }
                $grupoGranjaNombre = isset($grupo['granja_nombre']) ? trim((string) $grupo['granja_nombre']) : '';
                if ($grupoGranjaNombre !== '') {
                    if (!in_array($grupoGranjaNombre, $granjasBloque, true)) {
                        $granjasBloque[] = $grupoGranjaNombre;
                    }
                }
                $uuidsGrupo = $grupo['registro_uuids'] ?? [];
                if (is_array($uuidsGrupo)) {
                    foreach ($uuidsGrupo as $uuidG) {
                        $uuidG = trim((string) $uuidG);
                        if ($uuidG !== '' && isset($uuidGranjaMap[$uuidG])) {
                            $code = $uuidGranjaMap[$uuidG];
                            $prefix = strtoupper(substr($code, 0, 3));
                            if ($prefix !== '') {
                                $nombre = $granjaNombreMap[$prefix] ?? $prefix;
                                if (!in_array($nombre, $granjasBloque, true)) {
                                    $granjasBloque[] = $nombre;
                                }
                            }
                        }
                    }
                }
            }
            $granjaNombre = !empty($granjasBloque) ? implode(' / ', $granjasBloque) : null;

            $rawGranja = null;
            foreach ($regs as $grupo) {
                if (!is_array($grupo)) {
                    continue;
                }
                $grupoGranja = isset($grupo['granja']) ? trim((string) $grupo['granja']) : '';
                if ($grupoGranja !== '') {
                    $rawGranja = $grupoGranja;
                    break;
                }
                $uuidsGrupo = $grupo['registro_uuids'] ?? [];
                if (is_array($uuidsGrupo)) {
                    foreach ($uuidsGrupo as $uuidG) {
                        $uuidG = trim((string) $uuidG);
                        if ($uuidG !== '' && isset($uuidGranjaMap[$uuidG])) {
                            $rawGranja = $uuidGranjaMap[$uuidG];
                            break 2;
                        }
                    }
                }
            }

            $bloques[] = [
                'audit_id' => $auditId,
                'usuario' => (string) ($row['usuarioRegistro'] ?? ''),
                'usuario_nombre' => $usuarioNombreMap[strtoupper(trim((string) ($row['usuarioRegistro'] ?? '')))]
                    ?? (string) ($row['usuarioRegistro'] ?? ''),
                'granja_nombre' => $granjaNombre,
                'granja' => $rawGranja,
                '_debug_grupo_keys' => $debugGroupKeys,
                '_debug_uuidGranjaMap_keys' => array_keys($uuidGranjaMap),
                '_debug_allUuidList_count' => count($allUuidList),
                'total_registros' => (int) ($row['totalRegistros'] ?? 0),
                'total_aves' => (int) ($row['totalAves'] ?? 0),
                'observaciones' => (string) ($row['observaciones'] ?? ''),
                'fecha' => (string) ($row['fecha'] ?? ''),
                'fecha_hora_registro' => (string) ($row['fechaHoraRegistro'] ?? ''),
                'grupos' => $regs,
            ];
        }
        return ['bloques' => $bloques, 'total' => count($bloques)];
    }

    /** @return list<array<string, mixed>> */
    private static function decodificarRegistros($raw): array
    {
        $regs = [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $regs = $decoded;
            }
        } elseif (is_array($raw)) {
            $regs = $raw;
        }
        return $regs;
    }

    /** Lista de ids escapados para cláusula IN. */
    private static function inSql(\mysqli $conn, array $valores): string
    {
        $esc = [];
        foreach ($valores as $v) {
            $esc[] = "'" . mysqli_real_escape_string($conn, (string) $v) . "'";
        }
        return implode(',', $esc);
    }
}
