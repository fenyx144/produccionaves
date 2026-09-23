<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Consultas de listado, cabeceras y cierres de mortalidad móvil.
 */
final class MortalidadModel
{
    public static function tiposTodos(): array
    {
        return ['incubacion', 'transporte', 'produccion', 'despacho'];
    }

    public static function markDeTipo(string $tipo): string
    {
        switch (strtolower(trim($tipo))) {
            case 'incubacion':
                return 'JI1';
            case 'transporte':
                return 'JT2';
            case 'produccion':
                return 'JP3';
            case 'despacho':
                return 'JD4';
            default:
                return '';
        }
    }

    public static function tipoDesdeMark(string $mark): string
    {
        switch (strtoupper(trim($mark))) {
            case 'JI1':
                return 'incubacion';
            case 'JT2':
                return 'transporte';
            case 'JP3':
                return 'produccion';
            case 'JD4':
                return 'despacho';
            default:
                return '';
        }
    }

    public static function codigoDisplay(string $doc, string $serie, string $numero): string
    {
        return trim($doc . '-' . $serie . '-' . $numero, '-');
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

    /**
     * Registros de mortalidad con detalles y fotos (contrato de la app).
     *
     * @param array<string,string> $filtros  fecha, granja, campania, galpon, tipo_mortalidad
     */
    public static function listarRegistros(\mysqli $conn, array $filtros, string $webBase): array
    {
        $fecha = trim((string) ($filtros['fecha'] ?? ''));
        $granja = substr(str_pad(preg_replace('/\D/', '', (string) ($filtros['granja'] ?? '')) ?? '', 3, '0', STR_PAD_LEFT), 0, 3);
        $campaniaRaw = trim((string) ($filtros['campania'] ?? ''));
        $campania = $campaniaRaw !== ''
            ? substr(str_pad(preg_replace('/\D/', '', $campaniaRaw) ?? '', 3, '0', STR_PAD_LEFT), 0, 3)
            : '';
        $galpon = trim((string) ($filtros['galpon'] ?? ''));
        $tipo = strtolower(trim((string) ($filtros['tipo_mortalidad'] ?? '')));

        $where = " WHERE cz.mark IN ('JI1','JT2','JP3','JD4') ";
        $tipos = self::tiposTodos();
        if ($tipo !== '' && in_array($tipo, $tipos, true)) {
            $mark = self::markDeTipo($tipo);
            if ($mark !== '') {
                $markEsc = mysqli_real_escape_string($conn, $mark);
                $where .= " AND cz.mark = '{$markEsc}' ";
            }
        }

        if ($fecha !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fechaEsc = mysqli_real_escape_string($conn, $fecha);
            $where .= " AND cz.tfecrem = '{$fechaEsc}' ";
        }

        if ($granja !== '' && $granja !== '000') {
            $gEsc = mysqli_real_escape_string($conn, $granja);
            $where .= " AND LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}' ";
        }

        if ($campania !== '' && $campania !== '000') {
            $cEsc = mysqli_real_escape_string($conn, $campania);
            $where .= " AND RIGHT(TRIM(mz.tcencos), 3) = '{$cEsc}' ";
        }

        if ($galpon !== '') {
            $galEsc = mysqli_real_escape_string($conn, $galpon);
            $where .= " AND mz.tcodint = '{$galEsc}' ";
        }

        $sql = "
            SELECT
                cz.external_id,
                MAX(cz.tdoc) AS doc,
                MAX(cz.tserie) AS tserie,
                MAX(cz.tnumfac) AS tnumfac,
                MAX(cz.mark) AS mark,
                MAX(cz.tfecrem) AS tfecrem,
                MAX(cz.tfectra) AS tfectra,
                MAX(cz.observaciones) AS observaciones,
                MAX(cz.tuser) AS usuario,
                MAX(CONCAT(TRIM(cz.tdate), ' ', TRIM(cz.ttime))) AS fecha_hora,
                MAX(cz.idcia) AS cz_idcia,
                MAX(cz.treg) AS cz_treg,
                MAX(cz.tdoc) AS cz_tdoc,
                MAX(cz.tserie) AS cz_tserie,
                MAX(cz.tnumfac) AS cz_tnumfac,
                MAX(mz.tcencos) AS tcencos,
                MAX(mz.tcodint) AS galpon,
                MAX(mz.flujo) AS flujo,
                MAX(cc.nombre) AS granja_nombre,
                COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS cant_machos,
                COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS cant_hembras
            FROM cabe_zonas cz
            INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
            LEFT JOIN ccos cc ON cc.codigo = CONCAT(LEFT(mz.tcencos, 3), '000')
            {$where}
            GROUP BY cz.external_id
            ORDER BY MAX(CONCAT(TRIM(cz.tdate), ' ', TRIM(cz.ttime))) DESC
        ";

        $res = mysqli_query($conn, $sql);
        if (!$res) {
            throw new \RuntimeException('Error en consulta: ' . mysqli_error($conn));
        }

        $registros = [];
        $cabIds = [];

        while ($row = mysqli_fetch_assoc($res)) {
            $cabId = (string) ($row['external_id'] ?? '');
            if ($cabId === '') {
                continue;
            }
            $cabIds[] = $cabId;

            $mark = strtoupper(trim((string) ($row['mark'] ?? '')));
            $tipoMortalidad = self::tipoDesdeMark($mark);

            $tcencos = trim((string) ($row['tcencos'] ?? ''));
            $granja3 = substr(str_pad($tcencos, 6, '0', STR_PAD_LEFT), 0, 3);
            $campania3 = strlen($tcencos) >= 3 ? substr($tcencos, -3) : '';

            $flujo = trim((string) ($row['flujo'] ?? ''));

            $subtipoTransporte = null;
            $subtipoProduccion = null;
            if ($mark === 'JT2') {
                $subtipoTransporte = strtolower($flujo) === 'evento' ? 'evento' : 'transporte';
            } elseif ($mark === 'JP3') {
                $subtipoProduccion = strtolower($flujo);
            }

            $fechaReg = (string) ($row['tfecrem'] ?? '');
            $fechaHora = (string) ($row['fecha_hora'] ?? '');
            $hora = '';
            if ($fechaHora !== '') {
                $hora = strlen($fechaHora) >= 19 ? substr($fechaHora, 11, 8) : '';
            }

            $serie = \mort_serie_desde_tserie((string) ($row['tserie'] ?? ''));
            $numero = \mort_numero_desde_tnumfac($row['tnumfac'] ?? '');
            $doc = trim((string) ($row['doc'] ?? 'GI'));

            $registros[$cabId] = [
                'tuuid' => $cabId,
                'id' => $cabId,
                'tipo_mortalidad' => $tipoMortalidad,
                'granja' => $granja3,
                'campania' => $campania3,
                'cenco_codigo' => $granja3 . $campania3,
                'granja_nombre' => trim((string) ($row['granja_nombre'] ?? '')),
                'galpon' => trim((string) ($row['galpon'] ?? '')),
                'fecha' => $fechaReg,
                'fecha_llegada' => $mark === 'JI1' ? (string) ($row['tfectra'] ?? '') : null,
                'subtipo_transporte' => $subtipoTransporte,
                'origen_analisis' => $subtipoProduccion,
                'observaciones' => (string) ($row['observaciones'] ?? ''),
                'usuario' => (string) ($row['usuario'] ?? ''),
                'cant_machos' => (int) ($row['cant_machos'] ?? 0),
                'cant_hembras' => (int) ($row['cant_hembras'] ?? 0),
                'doc' => $doc,
                'serie' => $serie,
                'numero' => $numero,
                'codigo' => self::codigoDisplay($doc, $serie, $numero),
                'cabe_zonas' => ($row['cz_idcia'] ?? '') !== '' ? [
                    'idcia' => (string) ($row['cz_idcia'] ?? ''),
                    'treg' => (string) ($row['cz_treg'] ?? ''),
                    'mark' => (string) ($row['mark'] ?? ''),
                    'tdoc' => (string) ($row['cz_tdoc'] ?? ''),
                    'tserie' => (string) ($row['cz_tserie'] ?? ''),
                    'tnumfac' => (string) ($row['cz_tnumfac'] ?? ''),
                ] : null,
                'ttime_trans' => $hora,
                'tdate_trans' => $fechaHora,
                'synced' => true,
                'guardado_backend' => true,
                'from_server' => true,
                'detalles' => [],
            ];
        }

        $etapasPorCab = [];
        if ($cabIds !== []) {
            $etapasPorCab = \mort_fact_etapas_por_cabs($conn, $cabIds);

            $idsEsc = self::inSql($conn, $cabIds);
            $qDet = mysqli_query($conn, "
                SELECT
                    cz.external_id,
                    mz.idmovi,
                    mz.tcodigo,
                    mz.tcantid,
                    mz.tcod_mortgrs,
                    mz.observaciones,
                    mz.evidencia,
                    r.tnom_mort AS nomMortalidad
                FROM movi_zonas mz
                INNER JOIN cabe_zonas cz ON mz.mark = cz.mark AND mz.treg = cz.treg
                    AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
                LEFT JOIN regmotivo_mortalidadgrs r ON r.tcod_mort = mz.tcod_mortgrs
                WHERE cz.external_id IN ({$idsEsc})
                ORDER BY cz.external_id ASC, mz.idmovi ASC, mz.tcodigo ASC
            ");

            if ($qDet) {
                while ($det = mysqli_fetch_assoc($qDet)) {
                    $cabId = (string) ($det['external_id'] ?? '');
                    if ($cabId === '' || !isset($registros[$cabId])) {
                        continue;
                    }
                    $pos = (int) ($det['idmovi'] ?? 0);
                    $sexo = strtoupper(trim((string) ($det['tcodigo'] ?? ''))) === 'P0001002' ? 'H' : 'M';
                    $etapasDet = [];
                    if (($registros[$cabId]['tipo_mortalidad'] ?? '') === 'despacho') {
                        $etapasDet = $etapasPorCab[$cabId][$pos . '-' . $sexo] ?? [];
                    }
                    $registros[$cabId]['detalles'][] = [
                        'id' => sprintf('%s-%02d-%s', $cabId, $pos, $sexo),
                        'sexo' => $sexo,
                        'cantidad' => (int) ($det['tcantid'] ?? 0),
                        'causa_codigo' => trim((string) ($det['tcod_mortgrs'] ?? '')),
                        'causa_nombre' => trim((string) ($det['nomMortalidad'] ?? '')),
                        'observacion' => trim((string) ($det['observaciones'] ?? '')),
                        'grupo_id' => (string) $pos,
                        'proceso_etapas' => $etapasDet === [] ? null : $etapasDet,
                        'fotos' => self::fotosDesdeCsv((string) ($det['evidencia'] ?? ''), $webBase),
                    ];
                }
            }
        }

        return [
            'registros' => array_values($registros),
            'total' => count($registros),
        ];
    }

    /** Cabecera existente en cabe_zonas por external_id (doc/serie/numero reconstruidos). */
    public static function cabExiste(\mysqli $conn, string $id): ?array
    {
        $stmt = $conn->prepare(
            'SELECT external_id, tdoc, tserie, tnumfac FROM cabe_zonas WHERE external_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return null;
        }

        return [
            'id' => (string) ($row['external_id'] ?? ''),
            'doc' => trim((string) ($row['tdoc'] ?? 'GI')),
            'serie' => \mort_serie_desde_tserie((string) ($row['tserie'] ?? '')),
            'numero' => \mort_numero_desde_tnumfac($row['tnumfac'] ?? ''),
        ];
    }

    /** Cabecera derivada con marca, subtipos y datos para reparar un registro. */
    public static function cabParaReparar(\mysqli $conn, string $id): ?array
    {
        $stmt = $conn->prepare(
            'SELECT cz.tdoc, cz.tserie, cz.tnumfac, cz.mark, cz.tfecrem, cz.tfectra,
                    cz.observaciones, cz.tuser, cz.tdate, cz.ttime,
                    mz.tcencos, mz.tcodint, mz.flujo
             FROM cabe_zonas cz
             LEFT JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
             WHERE cz.external_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row) {
            return null;
        }

        $mark = strtoupper(trim((string) ($row['mark'] ?? '')));
        $tipoMortalidad = self::tipoDesdeMark($mark);
        $flujo = trim((string) ($row['flujo'] ?? ''));
        $subtipoTransporte = null;
        $subtipoProduccion = null;
        if ($mark === 'JT2') {
            $subtipoTransporte = strtolower($flujo) === 'evento' ? 'evento' : 'transporte';
        } elseif ($mark === 'JP3') {
            $subtipoProduccion = strtolower($flujo);
        }

        $tcencos = trim((string) ($row['tcencos'] ?? ''));
        $granja = substr(str_pad($tcencos, 6, '0', STR_PAD_LEFT), 0, 3);
        $campania = strlen($tcencos) >= 3 ? substr($tcencos, -3) : '';

        return [
            'id' => $id,
            'doc' => trim((string) ($row['tdoc'] ?? 'GI')),
            'serie' => \mort_serie_desde_tserie((string) ($row['tserie'] ?? '')),
            'numero' => \mort_numero_desde_tnumfac($row['tnumfac'] ?? ''),
            'tipoMortalidad' => $tipoMortalidad,
            'subtipoTransporte' => $subtipoTransporte,
            'subtipoProduccion' => $subtipoProduccion,
            'granja' => $granja,
            'campania' => $campania,
            'galpon' => trim((string) ($row['tcodint'] ?? '')),
            'fechaRegistro' => trim((string) ($row['tfecrem'] ?? '')),
            'fechaLlegada' => $mark === 'JI1' ? trim((string) ($row['tfectra'] ?? '')) : null,
            'observaciones' => (string) ($row['observaciones'] ?? ''),
            'usuarioRegistro' => trim((string) ($row['tuser'] ?? '')),
            'fechaHoraRegistro' => trim((string) ($row['tdate'] ?? '') . ' ' . (string) ($row['ttime'] ?? '')),
            'mark' => $mark,
        ];
    }

    /** Existe una línea en movi_zonas para (external_id, posicion, sexo). */
    public static function moviLineaExiste(\mysqli $conn, string $cabId, int $posicion, string $sexo): bool
    {
        $tcodigo = $sexo === 'H' ? 'P0001002' : 'P0001001';
        $stmt = $conn->prepare(
            'SELECT 1 FROM movi_zonas mz
             INNER JOIN cabe_zonas cz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
             WHERE cz.external_id = ? AND mz.idmovi = ? AND mz.tcodigo = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sis', $cabId, $posicion, $tcodigo);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = $res && $res->num_rows > 0;
        $stmt->close();

        return $ok;
    }

    /** Cierres de año/mes/día que bloquean el registro (lanza CIERRE_ANIO|CIERRE_MES|CIERRE_DIA). */
    public static function validarFechaCierre(\mysqli $conn, string $fechaRegistro): void
    {
        $anio = date('Y', strtotime($fechaRegistro));
        $stmtAnio = $conn->prepare("SELECT eano FROM conempre WHERE epre = 'RS' LIMIT 1");
        if ($stmtAnio) {
            $stmtAnio->execute();
            $resAnio = $stmtAnio->get_result();
            $rowAnio = $resAnio ? $resAnio->fetch_assoc() : null;
            $stmtAnio->close();
            if ($rowAnio && isset($rowAnio['eano'])) {
                $eano = trim((string) $rowAnio['eano']);
                if ($eano !== '' && $anio !== $eano) {
                    throw new \RuntimeException('CIERRE_ANIO');
                }
            }
        }

        $mes = date('Y/m', strtotime($fechaRegistro));
        $stmtIndi = $conn->prepare("SELECT cierre FROM indi WHERE fecha = ? LIMIT 1");
        if ($stmtIndi) {
            $stmtIndi->bind_param('s', $mes);
            $stmtIndi->execute();
            $resIndi = $stmtIndi->get_result();
            $rowIndi = $resIndi ? $resIndi->fetch_assoc() : null;
            $stmtIndi->close();
            if ($rowIndi && isset($rowIndi['cierre']) && strtoupper(trim((string) $rowIndi['cierre'])) === 'C') {
                throw new \RuntimeException('CIERRE_MES');
            }
        }

        $dia = date('Y-m-d', strtotime($fechaRegistro));
        $stmtDola = $conn->prepare("SELECT cerra, cerrajoya FROM dola WHERE fecha = ? LIMIT 1");
        if ($stmtDola) {
            $stmtDola->bind_param('s', $dia);
            $stmtDola->execute();
            $resDola = $stmtDola->get_result();
            $rowDola = $resDola ? $resDola->fetch_assoc() : null;
            $stmtDola->close();
            if ($rowDola) {
                $cerra = isset($rowDola['cerra']) ? strtoupper(trim((string) $rowDola['cerra'])) : '';
                $cerrajoya = isset($rowDola['cerrajoya']) ? strtoupper(trim((string) $rowDola['cerrajoya'])) : '';
                if ($cerra === 'C' || $cerrajoya === 'C') {
                    throw new \RuntimeException('CIERRE_DIA');
                }
            }
        }
    }

    /**
     * Lista de fotos desde el CSV de evidencia de un detalle.
     *
     * @return list<array{path: string}>
     */
    public static function fotosDesdeCsv(string $evidenciaCsv, string $webBase): array
    {
        $fotos = [];
        foreach (preg_split('/\s*,\s*/', trim($evidenciaCsv)) ?: [] as $part) {
            $rel = self::normalizeRel((string) $part);
            if ($rel === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $rel)) {
                $fotos[] = ['path' => $rel];
                continue;
            }
            if (strpos($rel, 'uploads/mortalidad/') !== 0) {
                continue;
            }
            $fotos[] = ['path' => $webBase . rawurlencode($rel)];
        }

        return $fotos;
    }

    public static function normalizeRel(string $part): string
    {
        $part = trim($part);
        if ($part === '') {
            return '';
        }
        if (preg_match('#^\.\.?/#', $part)) {
            return '';
        }
        if (preg_match('#^(?:https?:)?//#i', $part)) {
            return $part;
        }
        if (strpos($part, 'uploads/mortalidad/') === 0) {
            return $part;
        }

        return 'uploads/mortalidad/' . ltrim($part, '/');
    }

    /**
     * Verificación de integridad de un registro móvil (verificar_registro_movil.php).
     *
     * @return array<string, mixed>
     */
    public static function verificarRegistro(\mysqli $conn, string $uuid, string $detallesEnviadosRaw): array
    {
        $uuidEsc = mysqli_real_escape_string($conn, $uuid);

        $qCabe = mysqli_query($conn, "
            SELECT idcia, treg, mark, tdoc, tserie, tnumfac, tfecrem, tfectra, observaciones, tuser
            FROM cabe_zonas
            WHERE external_id = '{$uuidEsc}'
            LIMIT 1
        ");
        $cabeZonas = $qCabe ? mysqli_fetch_assoc($qCabe) : null;

        if ($cabeZonas === null) {
            return [
                'uuid' => $uuid,
                'completo' => false,
                'cabecera' => ['existe' => false],
                'detalles' => [],
                'zonas_contables' => null,
                'faltantes' => ['cabecera'],
            ];
        }

        $resultado = [
            'uuid' => $uuid,
            'cabecera' => [
                'existe' => true,
                'id' => $uuid,
                'doc' => trim((string) ($cabeZonas['tdoc'] ?? '')),
                'serie' => \mort_serie_desde_tserie((string) ($cabeZonas['tserie'] ?? '')),
                'numero' => \mort_numero_desde_tnumfac($cabeZonas['tnumfac'] ?? ''),
                'mark' => trim((string) ($cabeZonas['mark'] ?? '')),
            ],
        ];

        $markEsc = mysqli_real_escape_string($conn, trim((string) ($cabeZonas['mark'] ?? '')));

        $qDet = mysqli_query($conn, "
            SELECT
                mz.idmovi,
                mz.tcodigo,
                mz.tcantid,
                mz.tcod_mortgrs,
                mz.observaciones,
                mz.evidencia,
                r.tnom_mort AS nomMortalidad
            FROM movi_zonas mz
            INNER JOIN cabe_zonas cz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
            LEFT JOIN regmotivo_mortalidadgrs r ON r.tcod_mort = mz.tcod_mortgrs
            WHERE cz.external_id = '{$uuidEsc}'
            ORDER BY mz.idmovi ASC, mz.tcodigo ASC
        ");

        $detalles = [];
        $totalFotosEsperadas = 0;
        $totalFotosExistentes = 0;
        $faltantes = [];

        if ($qDet && mysqli_num_rows($qDet) > 0) {
            while ($det = mysqli_fetch_assoc($qDet)) {
                $pos = (int) ($det['idmovi'] ?? 0);
                $sexo = strtoupper(trim((string) ($det['tcodigo'] ?? ''))) === 'P0001002' ? 'H' : 'M';
                $detId = sprintf('%s-%02d-%s', $uuid, $pos, $sexo);
                $evidenciaRaw = trim((string) ($det['evidencia'] ?? ''));

                $fotos = [];
                $fotosFaltantes = [];

                if ($evidenciaRaw !== '') {
                    $partes = preg_split('/\s*,\s*/', $evidenciaRaw) ?: [];
                    foreach ($partes as $rutaRel) {
                        $rutaRel = trim($rutaRel);
                        if ($rutaRel === '') {
                            continue;
                        }

                        $totalFotosEsperadas++;
                        $pathFisico = sanidad_uploads_fs_from_rel($rutaRel);
                        $existe = $pathFisico !== '' && is_file($pathFisico);

                        $fotos[] = [
                            'ruta' => $rutaRel,
                            'existe' => $existe,
                        ];

                        if (!$existe) {
                            $fotosFaltantes[] = $rutaRel;
                            $faltantes[] = 'imagen:' . $detId . ':' . $rutaRel;
                        } else {
                            $totalFotosExistentes++;
                        }
                    }
                }

                $detalles[] = [
                    'id' => $detId,
                    'sexo' => $sexo,
                    'cantidad' => (int) ($det['tcantid'] ?? 0),
                    'causa_codigo' => trim((string) ($det['tcod_mortgrs'] ?? '')),
                    'causa_nombre' => trim((string) ($det['nomMortalidad'] ?? '')),
                    'existe' => true,
                    'imagenes' => [
                        'total' => count($fotos),
                        'existentes' => count($fotos) - count($fotosFaltantes),
                        'faltantes' => $fotosFaltantes,
                        'lista' => $fotos,
                    ],
                ];
            }
        } else {
            $faltantes[] = 'detalles:todos';
        }

        $resultado['detalles'] = $detalles;

        $moviZonasCount = count($detalles);
        $zonasContables = [
            'existe' => true,
            'cabe_zonas' => true,
            'movi_zonas' => $moviZonasCount > 0,
            'movi_filas' => $moviZonasCount,
        ];

        if ($moviZonasCount === 0) {
            $faltantes[] = 'zonas_contables:movi_zonas';
        }

        $resultado['zonas_contables'] = $zonasContables;

        $lineasFaltantes = 0;
        if ($detallesEnviadosRaw !== '' && ctype_digit($detallesEnviadosRaw)) {
            $esperados = (int) $detallesEnviadosRaw;
            $lineasFaltantes = max(0, $esperados - $moviZonasCount);
            if ($lineasFaltantes > 0) {
                $faltantes[] = 'detalles:faltantes:' . $lineasFaltantes;
            }
        }

        $detallesCompletos = count($detalles) > 0;
        $fotosCompletas = $totalFotosEsperadas === $totalFotosExistentes;
        $zonasCompletas = $zonasContables['cabe_zonas'] && $zonasContables['movi_zonas'];
        $lineasCompletas = $lineasFaltantes === 0;

        $completo = $detallesCompletos && $fotosCompletas && $zonasCompletas && $lineasCompletas;

        $resultado['completo'] = $completo;
        $resultado['resumen'] = [
            'cabecera' => true,
            'detalles' => ['total' => count($detalles), 'ok' => $detallesCompletos, 'lineas_faltantes' => $lineasFaltantes],
            'imagenes' => ['esperadas' => $totalFotosEsperadas, 'existentes' => $totalFotosExistentes, 'ok' => $fotosCompletas],
            'zonas_contables' => $zonasCompletas,
        ];
        $resultado['faltantes'] = $faltantes;

        return $resultado;
    }
}
