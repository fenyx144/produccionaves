<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Consultas de auditoría móvil (pendientes, mis auditorías, verificación).
 */
final class AuditoriaModel
{
    /**
     * Pendientes de auditoría agrupados por tipo/subtipo.
     *
     * @param array<string,string> $filtros fecha_desde, fecha_hasta, granja_codigo, campania_codigo, galpon_codigo
     * @return array<string, mixed>
     */
    public static function pendientes(\mysqli $conn, array $filtros): array
    {
        $fechaDesde = trim((string) ($filtros['fecha_desde'] ?? ''));
        $fechaHasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
        $granjaCodigo = trim((string) ($filtros['granja_codigo'] ?? ''));
        $campaniaCodigo = trim((string) ($filtros['campania_codigo'] ?? ''));
        $galponCodigo = trim((string) ($filtros['galpon_codigo'] ?? ''));

        $marks = ['JI1', 'JT2', 'JP3', 'JD4'];
        $marksEsc = self::inSql($conn, $marks);

        $whereExtra = '';
        if ($fechaDesde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
            $whereExtra .= " AND cz.tfecrem >= '" . mysqli_real_escape_string($conn, $fechaDesde) . "' ";
        }
        if ($fechaHasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            $whereExtra .= " AND cz.tfecrem <= '" . mysqli_real_escape_string($conn, $fechaHasta) . "' ";
        }
        if ($granjaCodigo !== '' && $granjaCodigo !== '000') {
            $gEsc = mysqli_real_escape_string($conn, $granjaCodigo);
            $whereExtra .= " AND LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}' ";
        }
        if ($campaniaCodigo !== '' && $campaniaCodigo !== '000') {
            $cEsc = mysqli_real_escape_string($conn, $campaniaCodigo);
            $whereExtra .= " AND RIGHT(TRIM(mz.tcencos), 3) = '{$cEsc}' ";
        }
        if ($galponCodigo !== '') {
            $galEsc = mysqli_real_escape_string($conn, $galponCodigo);
            $whereExtra .= " AND mz.tcodint = '{$galEsc}' ";
        }

        $filtroAuditMz = " AND (mz.internal_auditoria IS NULL OR TRIM(mz.internal_auditoria) = '') ";

        $sql = "
            SELECT
                cz.external_id AS cab_uuid,
                CASE cz.mark
                    WHEN 'JI1' THEN 'incubacion'
                    WHEN 'JT2' THEN 'transporte'
                    WHEN 'JP3' THEN 'produccion'
                    WHEN 'JD4' THEN 'despacho'
                    ELSE 'produccion'
                END AS tipoMortalidad,
                CASE
                    WHEN cz.mark = 'JT2' AND MAX(mz.flujo) = 'Evento' THEN 'evento'
                    WHEN cz.mark = 'JT2' THEN 'transporte'
                    ELSE NULL
                END AS subtipoTransporte,
                CASE WHEN cz.mark = 'JP3' THEN LOWER(MAX(mz.flujo)) ELSE NULL END AS subtipoProduccion,
                LEFT(MAX(mz.tcencos), 3) AS granja,
                RIGHT(MAX(mz.tcencos), 3) AS campania,
                MAX(cc.nombre) AS granjaNombre,
                MAX(mz.tcodint) AS galpon,
                cz.tfecrem AS fechaRegistro,
                COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS cant_machos,
                COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS cant_hembras,
                COALESCE(SUM(mz.tcantid), 0) AS total_aves
            FROM cabe_zonas cz
            INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
            LEFT JOIN ccos cc ON cc.codigo = CONCAT(LEFT(mz.tcencos, 3), '000')
            WHERE cz.mark IN ({$marksEsc})
              AND mz.tcategoria <> 'P'
              {$filtroAuditMz}
              {$whereExtra}
            GROUP BY cz.external_id, cz.mark, cz.tfecrem
            HAVING total_aves > 0
            ORDER BY MAX(CONCAT(TRIM(cz.tdate), ' ', TRIM(cz.ttime))) DESC
        ";

        $filas = [];
        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $row['campania'] = (string) ($row['campania'] ?? '');
                $row['granja_nombre'] = trim((string) ($row['granjaNombre'] ?? ''));
                $filas[] = $row;
            }
        }

        $groupLabels = [
            'incubacion' => 'Planta incubacion',
            'despacho' => 'Despacho',
            'transporte:evento' => 'Transporte - Evento',
            'transporte:transporte' => 'Transporte - Traslado',
            'produccion:crianza' => 'Produccion - Crianza',
            'produccion:necropsia' => 'Produccion - Necropsia',
            'produccion:laboratorio' => 'Produccion - Laboratorio',
            'produccion:cuarentena' => 'Produccion - Cuarentena',
        ];

        $buckets = [];
        foreach ($filas as $row) {
            $tipo = strtolower(trim((string) ($row['tipoMortalidad'] ?? '')));
            $subtipoTransporte = $row['subtipoTransporte'] ?? null;
            $subtipoProduccion = $row['subtipoProduccion'] ?? null;

            if ($tipo === 'incubacion' || $tipo === 'despacho') {
                $clave = $tipo;
            } elseif ($tipo === 'transporte') {
                $st = strtolower(trim((string) $subtipoTransporte));
                $clave = 'transporte:' . ($st !== 'evento' ? 'transporte' : 'evento');
            } elseif ($tipo === 'produccion') {
                $valid = ['crianza' => true, 'necropsia' => true, 'laboratorio' => true, 'cuarentena' => true];
                $st = strtolower(trim((string) $subtipoProduccion));
                $clave = 'produccion:' . ($st !== '' && isset($valid[$st]) ? $st : 'crianza');
            } else {
                continue;
            }

            if (!isset($buckets[$clave])) {
                $partes = strpos($clave, ':') === false
                    ? ['tipo_mortalidad' => $clave, 'subtipo' => null]
                    : ['tipo_mortalidad' => explode(':', $clave, 2)[0], 'subtipo' => explode(':', $clave, 2)[1]];

                $buckets[$clave] = [
                    'clave' => $clave,
                    'tipo_mortalidad' => $partes['tipo_mortalidad'],
                    'subtipo' => $partes['subtipo'],
                    'label' => $groupLabels[$clave] ?? $clave,
                    'total_registros' => 0,
                    'total_aves' => 0,
                    'registro_uuids' => [],
                ];
            }

            $uuid = trim((string) ($row['cab_uuid'] ?? ''));
            $aves = (int) ($row['total_aves'] ?? 0);
            if ($uuid === '' || $aves <= 0) {
                continue;
            }

            $buckets[$clave]['total_registros']++;
            $buckets[$clave]['total_aves'] += $aves;
            $buckets[$clave]['registro_uuids'][$uuid] = true;
        }

        $grupos = [];
        foreach ($buckets as $b) {
            $b['registro_uuids'] = array_keys($b['registro_uuids']);
            $grupos[] = $b;
        }

        $resumen = [];
        foreach ($grupos as $g) {
            $resumen[$g['clave']] = $g['total_aves'];
        }

        return [
            'aprobables' => $grupos,
            'resumen' => $resumen,
            'registros' => $filas,
        ];
    }

    /**
     * Auditorías registradas del usuario (listar_mis_auditorias_movil.php).
     *
     * @param array<string,string> $filtros usuario, fecha_desde, fecha_hasta
     * @return array<string, mixed>
     */
    public static function misAuditorias(\mysqli $conn, array $filtros): array
    {
        $usuario = trim((string) ($filtros['usuario'] ?? ''));
        $fechaDesde = trim((string) ($filtros['fecha_desde'] ?? ''));
        $fechaHasta = trim((string) ($filtros['fecha_hasta'] ?? ''));

        $where = ' WHERE 1=1 ';
        $params = [];
        $types = '';

        if ($usuario !== '') {
            $where .= ' AND a.usuarioRegistro = ? ';
            $params[] = $usuario;
            $types .= 's';
        }
        if ($fechaDesde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
            $where .= ' AND a.fecha >= ? ';
            $params[] = $fechaDesde;
            $types .= 's';
        }
        if ($fechaHasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            $where .= ' AND a.fecha <= ? ';
            $params[] = $fechaHasta;
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
            {$where}
            ORDER BY a.fechaHoraRegistro DESC
            LIMIT 200
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Error prepare: ' . $conn->error);
        }

        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $auditorias = [];

        while ($row = $res->fetch_assoc()) {
            $regs = [];
            $regsRaw = $row['registros'] ?? '';
            if ($regsRaw !== '') {
                $decoded = json_decode((string) $regsRaw, true);
                if (is_array($decoded)) {
                    $regs = $decoded;
                }
            }
            $auditorias[] = [
                'id' => (string) ($row['id'] ?? ''),
                'usuario' => (string) ($row['usuarioRegistro'] ?? ''),
                'totalRegistros' => (int) ($row['totalRegistros'] ?? 0),
                'totalAves' => (int) ($row['totalAves'] ?? 0),
                'observaciones' => (string) ($row['observaciones'] ?? ''),
                'fecha' => (string) ($row['fecha'] ?? ''),
                'fechaHoraRegistro' => (string) ($row['fechaHoraRegistro'] ?? ''),
                'grupos' => $regs,
            ];
        }

        $stmt->close();

        return [
            'auditorias' => $auditorias,
            'total' => count($auditorias),
        ];
    }

    /**
     * Verificación de integridad de una auditoría (verificar_auditoria_movil.php).
     *
     * @return array<string, mixed>
     */
    public static function verificarAuditoria(\mysqli $conn, string $uuid): array
    {
        $uuidEsc = mysqli_real_escape_string($conn, $uuid);

        $qAud = mysqli_query($conn, "
            SELECT id, usuarioRegistro, totalRegistros, totalAves, observaciones, evidencia, fecha, registros
            FROM san_mortalidad_auditoria
            WHERE id = '{$uuidEsc}'
            LIMIT 1
        ");

        $auditoria = $qAud ? mysqli_fetch_assoc($qAud) : null;

        if ($auditoria === null) {
            return [
                'uuid' => $uuid,
                'completo' => false,
                'existe' => false,
                'faltantes' => ['auditoria'],
            ];
        }

        $evidenciaRaw = trim((string) ($auditoria['evidencia'] ?? ''));
        $fotos = [];
        $fotosFaltantes = [];

        if ($evidenciaRaw !== '') {
            $partes = preg_split('/\s*,\s*/', $evidenciaRaw) ?: [];
            foreach ($partes as $rutaRel) {
                $rutaRel = trim($rutaRel);
                if ($rutaRel === '') {
                    continue;
                }

                $pathFisico = sanidad_uploads_fs_from_rel($rutaRel);
                $existe = $pathFisico !== '' && is_file($pathFisico);

                $fotos[] = [
                    'ruta' => $rutaRel,
                    'existe' => $existe,
                ];

                if (!$existe) {
                    $fotosFaltantes[] = $rutaRel;
                }
            }
        }

        $registrosData = $auditoria['registros'] ?? '[]';
        $totalUuids = 0;

        if (is_string($registrosData)) {
            $decoded = json_decode($registrosData, true);
            if (is_array($decoded)) {
                foreach ($decoded as $grupo) {
                    if (!is_array($grupo)) {
                        continue;
                    }
                    $uuids = $grupo['registro_uuids'] ?? [];
                    if (is_array($uuids)) {
                        $totalUuids += count($uuids);
                    }
                }
            }
        }

        $qCountMovi = mysqli_query($conn, "
            SELECT COUNT(DISTINCT mz.id) AS cnt
            FROM movi_zonas mz
            WHERE mz.internal_auditoria = '{$uuidEsc}'
        ");
        $moviCount = 0;
        if ($qCountMovi && ($r = mysqli_fetch_assoc($qCountMovi))) {
            $moviCount = (int) ($r['cnt'] ?? 0);
        }

        $faltantes = [];
        if (!empty($fotosFaltantes)) {
            $faltantes[] = 'imagenes:' . implode(',', $fotosFaltantes);
        }
        if ($moviCount === 0 && $totalUuids > 0) {
            $faltantes[] = 'movi_zonas:sin_vincular';
        }

        $completo = empty($fotosFaltantes) && ($moviCount > 0 || $totalUuids === 0);

        return [
            'uuid' => $uuid,
            'completo' => $completo,
            'existe' => true,
            'auditoria' => [
                'id' => $auditoria['id'],
                'usuario' => $auditoria['usuarioRegistro'],
                'totalRegistros' => (int) ($auditoria['totalRegistros'] ?? 0),
                'totalAves' => (int) ($auditoria['totalAves'] ?? 0),
            ],
            'imagenes' => [
                'total' => count($fotos),
                'existentes' => count($fotos) - count($fotosFaltantes),
                'faltantes' => $fotosFaltantes,
                'lista' => $fotos,
            ],
            'movi_zonas_vinculadas' => $moviCount > 0,
            'movi_count' => $moviCount,
            'faltantes' => $faltantes,
        ];
    }

    /** Lista de ids escapados para cláusula IN. */
    public static function inSql(\mysqli $conn, array $valores): string
    {
        $esc = [];
        foreach ($valores as $v) {
            $esc[] = "'" . mysqli_real_escape_string($conn, (string) $v) . "'";
        }
        return implode(',', $esc);
    }
}
