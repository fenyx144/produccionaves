<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/mortalidad_admin_lib.php';
require_once __DIR__ . '/../../../core/lib/sip_acl_sanidad.php';
require_once __DIR__ . '/../../../core/lib/sip_acl_rol_sistemas.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_doc_lib.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_fact_aux_lib.php';

/** Tabla fact legacy disponible (fallback de campos durante migración). */
function mort_listado_tabla_fact_disponible(mysqli $conn): bool
{
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $res = @$conn->query("SHOW TABLES LIKE 'san_fact_mortalidad_cab'");
    $cache[$key] = ($res && $res->num_rows > 0);

    return $cache[$key];
}

function mort_listado_texto_zonas_vacio(?string $valor): bool
{
    return trim((string) $valor) === '';
}

/** observaciones de cabecera en fact (vía external_id). */
function mort_listado_fact_observaciones_cab(mysqli $conn, string $externalId): string
{
    if (!mort_listado_tabla_fact_disponible($conn) || mort_listado_texto_zonas_vacio($externalId)) {
        return '';
    }
    $idEsc = mysqli_real_escape_string($conn, $externalId);
    $q = mysqli_query($conn, "SELECT observaciones FROM san_fact_mortalidad_cab WHERE id = '{$idEsc}' LIMIT 1");
    $row = $q ? mysqli_fetch_assoc($q) : null;

    return trim((string) ($row['observaciones'] ?? ''));
}

/**
 * Detalle fact indexado por "{posicion}-{sexo}".
 *
 * @return array<string, array{observacion: string, evidencia: string}>
 */
function mort_listado_fact_detalle_map(mysqli $conn, string $externalId): array
{
    if (!mort_listado_tabla_fact_disponible($conn) || mort_listado_texto_zonas_vacio($externalId)) {
        return [];
    }
    $idEsc = mysqli_real_escape_string($conn, $externalId);
    $q = mysqli_query($conn, "
        SELECT posicion, sexo, observacion, evidencia
        FROM san_fact_mortalidad_det
        WHERE cabId = '{$idEsc}'
    ");
    $map = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $pos = (int) ($row['posicion'] ?? 0);
            $sexo = strtoupper(substr(trim((string) ($row['sexo'] ?? '')), 0, 1));
            if ($pos <= 0 || ($sexo !== 'M' && $sexo !== 'H')) {
                continue;
            }
            $map[$pos . '-' . $sexo] = [
                'observacion' => trim((string) ($row['observacion'] ?? '')),
                'evidencia' => trim((string) ($row['evidencia'] ?? '')),
            ];
        }
    }

    return $map;
}

/**
 * Completa evidencia/observacion del detalle desde fact si están vacíos en movi_zonas.
 *
 * @param list<array<string, mixed>> $det
 * @return list<array<string, mixed>>
 */
function mort_listado_aplicar_fallback_detalle_fact(mysqli $conn, string $externalId, array $det, ?string $verUploadPrefix = null): array
{
    if ($det === [] || !mort_listado_tabla_fact_disponible($conn)) {
        return $det;
    }
    $factMap = mort_listado_fact_detalle_map($conn, $externalId);
    if ($factMap === []) {
        return $det;
    }
    foreach ($det as &$row) {
        $key = (int) ($row['posicion'] ?? 0) . '-' . strtoupper(substr(trim((string) ($row['sexo'] ?? '')), 0, 1));
        $fb = $factMap[$key] ?? null;
        if ($fb === null) {
            continue;
        }
        if (mort_listado_texto_zonas_vacio($row['observacion'] ?? '')) {
            $row['observacion'] = $fb['observacion'];
        }
        if (mort_listado_texto_zonas_vacio($row['evidencia'] ?? '')) {
            $row['evidencia'] = $fb['evidencia'];
            $row['evidenciaUrls'] = mort_listado_evidencia_urls($row['evidencia'], $verUploadPrefix);
        }
    }
    unset($row);

    return $det;
}

/**
 * @return list<string>
 */
function mort_listado_nombres_rol_sistemas(): array
{
    return sip_acl_nombres_rol_sistemas();
}

function mort_listado_usuario_tiene_rol_sistemas(mysqli $conn, ?string $codigoUsuario = null): bool
{
    return sip_acl_usuario_tiene_rol_sistemas($conn, $codigoUsuario);
}

function mort_listado_usuario_puede_editar_eliminar(mysqli $conn, ?string $codigoUsuario = null): bool
{
    if ($codigoUsuario === null) {
        $codigoUsuario = trim((string) ($_SESSION['usuario'] ?? ''));
    } else {
        $codigoUsuario = trim($codigoUsuario);
    }
    if ($codigoUsuario === '') {
        return false;
    }

    // Usuario KAREN tiene acceso directo (regla local produccionaves)
    if (strtoupper($codigoUsuario) === 'KAREN') {
        return true;
    }

    // Rol Sistemas vía ACL (adm_usuario_rol + adm_rol), igual que sanidad hc_obs
    return sip_acl_usuario_tiene_rol_sistemas($conn, $codigoUsuario);
}

/**
 * Responde 403 si el usuario de sesión no puede editar/eliminar.
 * Cierra la conexión si se deniega.
 */
function mort_listado_require_editar_eliminar(mysqli $conn): void
{
    if (mort_listado_usuario_puede_editar_eliminar($conn)) {
        return;
    }
    mysqli_close($conn);
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'No tiene permiso para editar o eliminar registros. Se requiere rol Sistemas (asignado en Roles y permisos).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function mort_listado_format_usuario_display(?string $nombre, ?string $codigo = ''): string
{
    $text = trim((string) $nombre);
    if ($text === '') {
        $text = trim((string) $codigo);
    }
    if ($text === '') {
        return '';
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($text, 'UTF-8')
        : strtoupper($text);
}

/**
 * Tipos visibles en el reporte web de liquidación: todos los de BD.
 * La tabla san_mortalidad_acceso_app solo aplica a la app móvil (registro).
 *
 * @return list<string>
 */
function mort_listado_tipos_reporte_keys(): array
{
    return array_column(mort_admin_tipos_mortalidad(), 'key');
}

function mort_listado_label_tipo(string $tipo): string
{
    foreach (mort_admin_tipos_mortalidad() as $t) {
        if (($t['key'] ?? '') === $tipo) {
            return (string) ($t['label'] ?? $tipo);
        }
    }

    return $tipo;
}

/**
 * @return array<string, string>
 */
function mort_listado_subtipos_transporte(): array
{
    return [
        'transporte' => 'Transporte',
        'evento' => 'Evento',
    ];
}

/**
 * @return array<string, string>
 */
function mort_listado_subtipos_produccion(): array
{
    return [
        'crianza' => 'Crianza',
        'necropsia' => 'Necropsia',
        'laboratorio' => 'Laboratorio',
        'cuarentena' => 'Cuarentena',
    ];
}

function mort_listado_label_subtipo(string $tipoMortalidad, ?string $subtipoTransporte, ?string $subtipoProduccion): string
{
    $tipo = strtolower(trim($tipoMortalidad));
    if ($tipo === 'transporte') {
        $k = strtolower(trim((string) $subtipoTransporte));
        return mort_listado_subtipos_transporte()[$k] ?? ($subtipoTransporte ?? '');
    }
    if ($tipo === 'produccion') {
        $k = strtolower(trim((string) $subtipoProduccion));
        return mort_listado_subtipos_produccion()[$k] ?? ($subtipoProduccion ?? '');
    }

    return '';
}

/**
 * @return array<string, mixed>
 */
function mort_listado_filtros_defecto(): array
{
    return [
        'periodoTipo' => 'ENTRE_MESES',
        'fechaUnica' => '',
        'fechaInicio' => '',
        'fechaFin' => '',
        'mesUnico' => '',
        'mesInicio' => date('Y-01'),
        'mesFin' => date('Y-12'),
        'granja' => '',
        'campania' => '',
        'galpon' => '',
        'clavesGrupo' => [],
        'search' => '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mort_listado_parse_filtros(array $input): array
{
    $f = mort_listado_filtros_defecto();
    foreach (array_keys($f) as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $val = $input[$k];
        if (is_array($val)) {
            if ($k === 'search' && isset($val['value'])) {
                $f[$k] = trim((string) $val['value']);
            } elseif ($k === 'clavesGrupo') {
                $f[$k] = [];
                foreach ($val as $v) {
                    $f[$k][] = trim((string) $v);
                }
            }
            continue;
        }
        $f[$k] = trim((string) $val);
    }
    $gDigits = preg_replace('/\D/', '', $f['granja']) ?? '';
    $f['granja'] = $gDigits !== '' ? substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    return $f;
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_listado_where_filtros(mysqli $conn, array $tiposPerm, array $filtros): string
{
    $where = ' WHERE 1=1 ';

    if ($tiposPerm === []) {
        return $where . ' AND 1=0 ';
    }

    $escaped = array_map(static function (string $k) use ($conn): string {
        return "'" . mysqli_real_escape_string($conn, $k) . "'";
    }, $tiposPerm);
    $where .= ' AND c.tipoMortalidad IN (' . implode(',', $escaped) . ') ';

    // Filtro por claves de grupo (tipo de registro)
    $claves = (array) ($filtros['clavesGrupo'] ?? []);
    if ($claves !== []) {
        $condiciones = [];
        foreach ($claves as $ck) {
            $parts = explode('::', (string) $ck);
            $tipo = trim($parts[0] ?? '');
            $subtipo = trim($parts[1] ?? '');
            if ($tipo === '') continue;
            $tEsc = mysqli_real_escape_string($conn, $tipo);
            if ($subtipo !== '') {
                $sEsc = mysqli_real_escape_string($conn, $subtipo);
                if ($tipo === $subtipo) {
                    // tipo == subtipo (ej. incubacion::incubacion, produccion::crianza, etc.)
                    $condiciones[] = "(c.tipoMortalidad = '{$tEsc}' AND (LOWER(TRIM(c.subtipoTransporte)) = '{$sEsc}' OR LOWER(TRIM(c.subtipoProduccion)) = '{$sEsc}'))";
                } else {
                    // tipo != subtipo (ej. transporte::evento)
                    $condiciones[] = "(c.tipoMortalidad = '{$tEsc}' AND (LOWER(TRIM(c.subtipoTransporte)) = '{$sEsc}' OR LOWER(TRIM(c.subtipoProduccion)) = '{$sEsc}'))";
                }
            } else {
                $condiciones[] = "c.tipoMortalidad = '{$tEsc}'";
            }
        }
        if ($condiciones !== []) {
            $where .= ' AND (' . implode(' OR ', $condiciones) . ') ';
        }
    }

    require_once __DIR__ . '/../../../core/lib/filtro_periodo_util.php';
    $rango = periodo_a_rango([
        'periodoTipo' => (string) ($filtros['periodoTipo'] ?? 'TODOS'),
        'fechaUnica' => (string) ($filtros['fechaUnica'] ?? ''),
        'fechaInicio' => (string) ($filtros['fechaInicio'] ?? ''),
        'fechaFin' => (string) ($filtros['fechaFin'] ?? ''),
        'mesUnico' => (string) ($filtros['mesUnico'] ?? ''),
        'mesInicio' => (string) ($filtros['mesInicio'] ?? ''),
        'mesFin' => (string) ($filtros['mesFin'] ?? ''),
    ]);
    if ($rango) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $where .= " AND c.fechaRegistro BETWEEN '{$desde}' AND '{$hasta}' ";
    }

    $granja = trim((string) ($filtros['granja'] ?? ''));
    if ($granja !== '') {
        $gEsc = mysqli_real_escape_string($conn, $granja);
        $where .= " AND c.granja = '{$gEsc}' ";
    }

    $campania = trim((string) ($filtros['campania'] ?? ''));
    if ($campania !== '') {
        $campEsc = mysqli_real_escape_string($conn, $campania);
        $where .= " AND c.campania = '{$campEsc}' ";
    }

    $galpon = trim((string) ($filtros['galpon'] ?? ''));
    if ($galpon !== '') {
        $galEsc = mysqli_real_escape_string($conn, $galpon);
        $where .= " AND c.galpon = '{$galEsc}' ";
    }

    $search = trim((string) ($filtros['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (' . mort_listado_sql_busqueda($conn, $search) . ') ';
    }

    return $where;
}

/**
 * Normaliza texto de búsqueda DataTables / filtros.
 */
function mort_listado_extract_search_input(array $input): string
{
    if (isset($input['search']) && is_array($input['search']) && array_key_exists('value', $input['search'])) {
        return trim((string) $input['search']['value']);
    }
    if (isset($input['search']) && is_string($input['search'])) {
        return trim($input['search']);
    }
    return '';
}

/**
 * Convierte fecha d/m/Y o d-m-Y a Y-m-d si aplica.
 */
function mort_listado_search_fecha_ymd(string $search): string
{
    $search = trim($search);
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $search, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $search)) {
        return substr($search, 0, 10);
    }
    return '';
}

/**
 * Condición SQL OR para búsqueda libre del listado (columnas de texto visibles).
 */
function mort_listado_sql_busqueda(mysqli $conn, string $search): string
{
    $s = mysqli_real_escape_string($conn, $search);
    $sLower = function_exists('mb_strtolower')
        ? mb_strtolower($search, 'UTF-8')
        : strtolower($search);

    $ors = [
        "c.serie LIKE '%{$s}%'",
        "c.numero LIKE '%{$s}%'",
        "c.granja LIKE '%{$s}%'",
        "c.campania LIKE '%{$s}%'",
        "CONCAT(TRIM(c.granja), TRIM(c.campania)) LIKE '%{$s}%'",
        "c.granjaNombre LIKE '%{$s}%'",
        "c.galpon LIKE '%{$s}%'",
        "c.usuarioRegistro LIKE '%{$s}%'",
        "c.observaciones LIKE '%{$s}%'",
        "c.tipoMortalidad LIKE '%{$s}%'",
        "c.subtipoTransporte LIKE '%{$s}%'",
        "c.subtipoProduccion LIKE '%{$s}%'",
        "c.fechaRegistro LIKE '%{$s}%'",
        "c.fechaHoraRegistro LIKE '%{$s}%'",
        "c.fechaLlegada LIKE '%{$s}%'",
        "EXISTS (
            SELECT 1 FROM usuario u
            WHERE u.codigo = c.usuarioRegistro
              AND u.nombre LIKE '%{$s}%'
         )",
        "EXISTS (
            SELECT 1 FROM cabe_zonas cz
            INNER JOIN movi_zonas mz
                ON mz.mark = cz.mark AND mz.treg = cz.treg
               AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
            WHERE cz.external_id = c.id
              AND mz.tnumfac LIKE '%{$s}%'
         )",
    ];

    $fechaYmd = mort_listado_search_fecha_ymd($search);
    if ($fechaYmd !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fechaYmd);
        $ors[] = "c.fechaRegistro = '{$fEsc}'";
        $ors[] = "DATE(c.fechaHoraRegistro) = '{$fEsc}'";
        $ors[] = "c.fechaLlegada = '{$fEsc}'";
    }

    // Match por etiquetas de tipo/subtipo (Incubación, Crianza, etc.)
    foreach (mort_admin_tipos_mortalidad() as $t) {
        $key = strtolower(trim((string) ($t['key'] ?? '')));
        $label = function_exists('mb_strtolower')
            ? mb_strtolower((string) ($t['label'] ?? ''), 'UTF-8')
            : strtolower((string) ($t['label'] ?? ''));
        if ($key === '') {
            continue;
        }
        if ($sLower === $key || $sLower === $label || strpos($label, $sLower) !== false || strpos($sLower, $key) !== false) {
            $kEsc = mysqli_real_escape_string($conn, $key);
            $ors[] = "c.tipoMortalidad = '{$kEsc}'";
        }
    }
    foreach (mort_listado_subtipos_transporte() as $key => $label) {
        $lab = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if ($sLower === $key || $sLower === $lab || strpos($lab, $sLower) !== false) {
            $kEsc = mysqli_real_escape_string($conn, $key);
            $ors[] = "LOWER(TRIM(c.subtipoTransporte)) = '{$kEsc}'";
        }
    }
    foreach (mort_listado_subtipos_produccion() as $key => $label) {
        $lab = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if ($sLower === $key || $sLower === $lab || strpos($lab, $sLower) !== false) {
            $kEsc = mysqli_real_escape_string($conn, $key);
            $ors[] = "LOWER(TRIM(c.subtipoProduccion)) = '{$kEsc}'";
        }
    }

    return implode(' OR ', $ors);
}

/**
 * @return list<array{granja: string, nombre: string}>
 */
function mort_listado_opciones_granjas_hc(mysqli $conn): array
{
    $granjas = [];
    $sqlMort = "SELECT DISTINCT LEFT(TRIM(mz.tcencos), 3) AS granja
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.mark IN ('JI1','JT2','JP3','JD4') AND TRIM(mz.tcencos) <> ''
        ORDER BY granja ASC";
    $resMort = @$conn->query($sqlMort);
    if ($resMort) {
        while ($row = $resMort->fetch_assoc()) {
            $g = substr(str_pad(trim((string) ($row['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
            if ($g !== '') {
                $granjas[$g] = true;
            }
        }
    }

    require_once __DIR__ . '/../../../core/lib/sip_historia_clinica_cab_lib.php';
    if (sip_hc_cab_tabla_disponible($conn)) {
        $t = sip_hc_cab_tabla_lista();
        $sql = "SELECT DISTINCT TRIM(`granja`) AS granja FROM `{$t}` WHERE TRIM(`granja`) <> '' ORDER BY granja ASC";
        $res = @$conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $g = substr(str_pad(trim((string) ($row['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
                if ($g !== '') {
                    $granjas[$g] = true;
                }
            }
        }
    }

    $g3List = array_keys($granjas);
    $nombres = mort_listado_nombres_granjas_batch($conn, $g3List);
    $rows = [];
    foreach ($g3List as $g) {
        $rows[] = ['granja' => $g, 'nombre' => $nombres[$g] ?? $g];
    }
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) $a['granja'], (string) $b['granja']);
    });

    return $rows;
}

/**
 * @return list<array{granja: string, nombre: string}>
 */
function mort_listado_opciones_granjas_fallback(mysqli $conn): array
{
    $sql = "SELECT DISTINCT LEFT(TRIM(mz.tcencos), 3) AS granja
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.mark IN ('JI1','JT2','JP3','JD4') AND TRIM(mz.tcencos) <> ''
        ORDER BY granja ASC";
    $res = @$conn->query($sql);
    if (!$res) {
        return [];
    }
    $g3List = [];
    while ($row = $res->fetch_assoc()) {
        $g = substr(str_pad(trim((string) ($row['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
        if ($g !== '') {
            $g3List[] = $g;
        }
    }
    $nombres = mort_listado_nombres_granjas_batch($conn, $g3List);
    $rows = [];
    foreach ($g3List as $g) {
        $rows[] = ['granja' => $g, 'nombre' => $nombres[$g] ?? $g];
    }

    return $rows;
}

/**
 * Totales de detalle por cabId (external_id) desde movi_zonas (una sola consulta).
 *
 * @param list<int|string> $cabIds
 * @return array<string, array{totalAves: int, machos: int, hembras: int}>
 */
function mort_listado_totales_por_cab_ids(mysqli $conn, array $cabIds): array
{
    $ids = mort_listado_normalize_cab_ids($cabIds);
    if ($ids === []) {
        return [];
    }

    $in = mort_listado_sql_in_strings($conn, $ids);
    $map = [];
    $res = mysqli_query($conn, "
        SELECT
            cz.external_id AS cabId,
            COALESCE(SUM(mz.tcantid), 0) AS totalAves,
            COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001001' THEN mz.tcantid ELSE 0 END), 0) AS machos,
            COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS hembras
        FROM cabe_zonas cz
        INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
            AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
        WHERE cz.external_id IN ({$in})
        GROUP BY cz.external_id
    ");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cid = mort_listado_norm_cab_id_key($row['cabId'] ?? '');
            if ($cid === '') {
                continue;
            }
            $map[$cid] = [
                'totalAves' => (int) ($row['totalAves'] ?? 0),
                'machos' => (int) ($row['machos'] ?? 0),
                'hembras' => (int) ($row['hembras'] ?? 0),
            ];
        }
    }

    return $map;
}

/**
 * numFac / edad desde cabe_zonas + movi_zonas (una sola consulta para la página).
 *
 * @param list<int|string> $cabIds
 * @return array<string, array{numFac: string, edad: string}>
 */
function mort_listado_zona_por_cab_ids(mysqli $conn, array $cabIds): array
{
    $ids = mort_listado_normalize_cab_ids($cabIds);
    if ($ids === []) {
        return [];
    }

    $in = mort_listado_sql_in_strings($conn, $ids);
    $map = [];
    // LEFT JOIN: a veces existe tnumfac en cabe_zonas sin fila emparejada en movi_zonas.
    $res = mysqli_query($conn, "
        SELECT
            cz.external_id,
            COALESCE(NULLIF(TRIM(CAST(mz.tnumfac AS CHAR)), ''), NULLIF(TRIM(CAST(cz.tnumfac AS CHAR)), '')) AS numFac,
            NULLIF(TRIM(CAST(mz.tedad AS CHAR)), '') AS edad
        FROM cabe_zonas cz
        LEFT JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
            AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
        WHERE cz.external_id IN ({$in})
    ");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cid = mort_listado_norm_cab_id_key($row['external_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $numFac = trim((string) ($row['numFac'] ?? ''));
            $edad = trim((string) ($row['edad'] ?? ''));
            if (!isset($map[$cid])) {
                $map[$cid] = ['numFac' => $numFac, 'edad' => $edad];
                continue;
            }
            // Preferir la primera fila con valores no vacíos.
            if ($map[$cid]['numFac'] === '' && $numFac !== '') {
                $map[$cid]['numFac'] = $numFac;
            }
            if ($map[$cid]['edad'] === '' && $edad !== '') {
                $map[$cid]['edad'] = $edad;
            }
        }
    }

    return $map;
}

/**
 * @param list<int|string> $cabIds
 * @return list<string>
 */
function mort_listado_normalize_cab_ids(array $cabIds): array
{
    $ids = [];
    foreach ($cabIds as $id) {
        $k = mort_listado_norm_cab_id_key($id);
        if ($k !== '') {
            $ids[$k] = true;
        }
    }

    return array_keys($ids);
}

/**
 * @param mixed $id
 */
function mort_listado_norm_cab_id_key($id): string
{
    return trim((string) $id);
}

/**
 * @param list<string> $ids
 */
function mort_listado_sql_in_strings(mysqli $conn, array $ids): string
{
    $parts = [];
    foreach ($ids as $id) {
        $parts[] = "'" . mysqli_real_escape_string($conn, (string) $id) . "'";
    }

    return implode(',', $parts);
}

/**
 * Completa totales / numFac / edad en filas ya paginadas (evita subconsultas correlacionadas).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function mort_listado_enrich_page_rows(mysqli $conn, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = $row['id'] ?? '';
    }
    $totales = mort_listado_totales_por_cab_ids($conn, $ids);
    $zonas = mort_listado_zona_por_cab_ids($conn, $ids);

    foreach ($rows as &$row) {
        $cid = mort_listado_norm_cab_id_key($row['id'] ?? '');
        $tot = $totales[$cid] ?? ['totalAves' => 0, 'machos' => 0, 'hembras' => 0];
        $zona = $zonas[$cid] ?? ['numFac' => '', 'edad' => ''];
        $row['totalAves'] = $tot['totalAves'];
        $row['machos'] = $tot['machos'];
        $row['hembras'] = $tot['hembras'];
        $row['numFac'] = $zona['numFac'];
        $row['edad'] = $zona['edad'];
    }
    unset($row);

    return mort_listado_enrich_bloqueos($conn, $rows);
}

function mort_listado_nombre_granja(mysqli $conn, string $granja3): string
{
    $map = mort_listado_nombres_granjas_batch($conn, [$granja3]);

    return $map[substr(str_pad(trim($granja3), 3, '0', STR_PAD_LEFT), 0, 3)] ?? substr(str_pad(trim($granja3), 3, '0', STR_PAD_LEFT), 0, 3);
}

/**
 * @param list<string> $granja3List
 * @return array<string, string> granja3 => nombre
 */
function mort_listado_nombres_granjas_batch(mysqli $conn, array $granja3List): array
{
    $map = [];
    $cod6ByG3 = [];
    foreach ($granja3List as $g) {
        $g3 = substr(str_pad(trim((string) $g), 3, '0', STR_PAD_LEFT), 0, 3);
        if ($g3 === '') {
            continue;
        }
        $cod6ByG3[$g3] = $g3 . '000';
        $map[$g3] = $g3;
    }
    if ($cod6ByG3 === []) {
        return $map;
    }

    $in = implode(',', array_map(static function (string $c) use ($conn): string {
        return "'" . mysqli_real_escape_string($conn, $c) . "'";
    }, array_values($cod6ByG3)));

    $res = @$conn->query("SELECT codigo, nombre FROM ccos WHERE codigo IN ({$in})");
    if ($res) {
        $nombreByCod6 = [];
        while ($row = $res->fetch_assoc()) {
            $nombreByCod6[(string) ($row['codigo'] ?? '')] = trim((string) ($row['nombre'] ?? ''));
        }
        foreach ($cod6ByG3 as $g3 => $cod6) {
            $nom = $nombreByCod6[$cod6] ?? '';
            if ($nom !== '') {
                $map[$g3] = $nom;
            }
        }
    }

    return $map;
}

/**
 * Incubación, necropsia y laboratorio no muestran línea de causa en reportes.
 */
function mort_listado_muestra_causa(string $tipoMortalidad, ?string $subtipoTransporte, ?string $subtipoProduccion): bool
{
    $tipo = strtolower(trim($tipoMortalidad));
    if ($tipo === 'incubacion') {
        return false;
    }
    if ($tipo === 'produccion') {
        $sub = strtolower(trim((string) $subtipoProduccion));
        if (in_array($sub, ['necropsia', 'laboratorio'], true)) {
            return false;
        }
    }

    return true;
}

function mort_listado_texto_causa(?string $codMortalidad, ?string $nomMortalidad, bool $muestraCausa): string
{
    if (!$muestraCausa) {
        return '';
    }
    $text = trim((string) ($nomMortalidad ?? ''));
    if ($text === '') {
        $text = trim((string) ($codMortalidad ?? ''));
    }

    return $text;
}

function mort_listado_normalize_evidencia_rel(string $part): string
{
    $part = trim(str_replace('\\', '/', $part));
    if ($part === '' || strpos($part, '..') !== false) {
        return '';
    }
    if (preg_match('#^https?://#i', $part)) {
        return $part;
    }
    $rel = ltrim($part, '/');
    if (preg_match('#^uploads/mortalidad/#', $rel)) {
        return $rel;
    }
    if (preg_match('#^uploads/#', $rel)) {
        return $rel;
    }

    return 'uploads/mortalidad/' . basename($rel);
}

/**
 * Marca(s) de cabe_zonas/movi_zonas para un tipo de mortalidad.
 *
 * @return list<string>
 */
function mort_listado_marks_tipo(string $tipoMortalidad): array
{
    switch (strtolower(trim($tipoMortalidad))) {
        case 'incubacion':
            return ['JI1'];
        case 'transporte':
            return ['JT2'];
        case 'produccion':
            return ['JP3'];
        case 'despacho':
            return ['JD4'];
        default:
            return ['JI1', 'JT2', 'JP3', 'JD4'];
    }
}

/** Motivos tipo despacho (misma lista que get_motivos_listado.php → despacho). */
function mort_listado_codigos_motivo_despacho(): array
{
    return ['14', '17', '18', '19'];
}

/**
 * Valida que una fecha pertenezca al periodo contable abierto usando
 * las tablas conempre (anio), indi (mes) y dola (dia).
 *
 * Lanza RuntimeException si el anio esta fuera, el mes o el dia estan cerrados.
 */
function mort_listado_validar_fecha_cierre(mysqli $conn, string $fechaRegistro): void
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
                throw new RuntimeException('La fecha esta fuera del anio contable abierto.');
            }
        }
    }

    $mes = date('Y/m', strtotime($fechaRegistro));
    $stmtIndi = $conn->prepare('SELECT cierre FROM indi WHERE fecha = ? LIMIT 1');
    if ($stmtIndi) {
        $stmtIndi->bind_param('s', $mes);
        $stmtIndi->execute();
        $resIndi = $stmtIndi->get_result();
        $rowIndi = $resIndi ? $resIndi->fetch_assoc() : null;
        $stmtIndi->close();
        if ($rowIndi && isset($rowIndi['cierre']) && strtoupper(trim((string) $rowIndi['cierre'])) === 'C') {
            throw new RuntimeException('El mes de la fecha esta cerrado.');
        }
    }

    $dia = date('Y-m-d', strtotime($fechaRegistro));
    $stmtDola = $conn->prepare('SELECT cerra, cerrajoya FROM dola WHERE fecha = ? LIMIT 1');
    if ($stmtDola) {
        $stmtDola->bind_param('s', $dia);
        $stmtDola->execute();
        $resDola = $stmtDola->get_result();
        $rowDola = $resDola ? $resDola->fetch_assoc() : null;
        $stmtDola->close();
        if ($rowDola) {
            $cerra = strtoupper(trim((string) ($rowDola['cerra'] ?? '')));
            $cerrajoya = strtoupper(trim((string) ($rowDola['cerrajoya'] ?? '')));
            if ($cerra === 'C' || $cerrajoya === 'C') {
                throw new RuntimeException('El dia de la fecha esta cerrado.');
            }
        }
    }
}

/**
 * Valida cierre contable antes de eliminar, salvo para usuarios con rol Sistemas.
 */
function mort_listado_validar_fecha_cierre_para_eliminacion(
    mysqli $conn,
    string $fechaRegistro,
    ?string $codigoUsuario = null
): void {
    if (mort_listado_usuario_tiene_rol_sistemas($conn, $codigoUsuario)) {
        return;
    }
    mort_listado_validar_fecha_cierre($conn, $fechaRegistro);
}

/**
 * Valida campaña activa (ccos) antes de eliminar, salvo para usuarios con rol Sistemas.
 */
function mort_listado_validar_cenco_activo_para_eliminacion(
    mysqli $conn,
    string $granja3,
    string $campania3,
    ?string $codigoUsuario = null
): void {
    if (mort_listado_usuario_tiene_rol_sistemas($conn, $codigoUsuario)) {
        return;
    }
    $g = trim($granja3);
    $c = trim($campania3);
    if ($g !== '' && $c !== '' && !mort_listado_cenco_activo($conn, $g, $c)) {
        throw new RuntimeException('No se puede eliminar: la campaña ' . $g . '-' . $c . ' ya no está activa.');
    }
}

/**
 * Devuelve true si el cenco (granja+campaña, 6 dígitos) está activo en ccos.
 *
 * Si el cenco no existe o su estado ya no es 'A', se interpreta como campaña
 * cerrada/desactivada: no tiene sentido permitir editar ese registro.
 */
function mort_listado_cenco_activo(mysqli $conn, string $granja3, string $campania3): bool
{
    $g3 = substr(str_pad(trim($granja3), 3, '0', STR_PAD_LEFT), 0, 3);
    $c3 = substr(str_pad(trim($campania3), 3, '0', STR_PAD_LEFT), 0, 3);
    if ($g3 === '' || $c3 === '' || $c3 === '000') {
        return false;
    }
    $cenco = $g3 . $c3;
    $stmt = $conn->prepare('SELECT swac FROM ccos WHERE TRIM(codigo) = ? LIMIT 1');
    if (!$stmt) {
        // Si no se puede consultar, no bloquear la edición por seguridad.
        return true;
    }
    $stmt->bind_param('s', $cenco);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return false;
    }

    return strtoupper(trim((string) ($row['swac'] ?? ''))) === 'A';
}

/**
 * Elimina del nombre de granja el sufijo de campaña que ccos trae embebido
 * (ej. "GRANJA X C=195" -> "GRANJA X"). Case-insensitive.
 */
function mort_listado_limpiar_nombre_granja(string $nombre): string
{
    $nombre = trim($nombre);
    if ($nombre === '') {
        return $nombre;
    }

    return trim((string) preg_replace('/\s*[Cc]\s*=\s*\d+\s*$/', '', $nombre));
}

/**
 * Enriquece filas del listado con `campaniaActiva` (bool) y, si la campaña
 * ya no está activa, limpia `granjaNombre` para no mostrar la campaña actual
 * embebida en el nombre (desfase).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function mort_listado_enrich_campania_activa(mysqli $conn, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    $pares = [];
    foreach ($rows as $row) {
        $g = substr(str_pad(trim((string) ($row['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
        $c = substr(str_pad(trim((string) ($row['campania'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
        if ($g !== '' && $c !== '' && $c !== '000') {
            $pares[$g . $c] = true;
        }
    }

    $activos = [];
    if ($pares !== []) {
        $in = mort_listado_sql_in_strings($conn, array_keys($pares));
        $res = @mysqli_query($conn, "SELECT TRIM(codigo) AS codigo FROM ccos WHERE codigo IN ({$in}) AND UPPER(TRIM(swac)) = 'A'");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $activos[trim((string) ($r['codigo'] ?? ''))] = true;
            }
        }
    }

    foreach ($rows as &$row) {
        $g = substr(str_pad(trim((string) ($row['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
        $c = substr(str_pad(trim((string) ($row['campania'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
        $cenco = $g . $c;
        $activo = ($c !== '000') && isset($activos[$cenco]);
        $row['campaniaActiva'] = $activo;
        if (!$activo) {
            $row['granjaNombre'] = mort_listado_limpiar_nombre_granja((string) ($row['granjaNombre'] ?? ''));
        }
    }
    unset($row);

    return $rows;
}

/**
 * Enriquece filas del listado con `fechaCerrada` (bool) y `motivoCierre` (string)
 * comparando la fecha del registro contra el año contable (conempre), el cierre
 * de mes (indi) y el cierre de día (dola). Consultas en lote para toda la página.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function mort_listado_enrich_fecha_cerrada(mysqli $conn, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }

    $fechas = [];
    $meses = [];
    foreach ($rows as $row) {
        $f = trim((string) ($row['fechaRegistro'] ?? ''));
        $ts = strtotime($f);
        if ($ts === false) {
            continue;
        }
        $fecha = date('Y-m-d', $ts);
        $fechas[$fecha] = true;
        $meses[substr($fecha, 0, 7)] = true;
    }

    // Año contable abierto.
    $eano = '';
    $qAnio = @mysqli_query($conn, "SELECT eano FROM conempre WHERE epre = 'RS' LIMIT 1");
    if ($qAnio && ($rAnio = mysqli_fetch_assoc($qAnio))) {
        $eano = trim((string) ($rAnio['eano'] ?? ''));
    }

    // Meses cerrados.
    $mesCerrado = [];
    if ($meses !== []) {
        $inMes = mort_listado_sql_in_strings($conn, array_keys($meses));
        $qMes = @mysqli_query($conn, "SELECT fecha, cierre FROM indi WHERE fecha IN ({$inMes})");
        if ($qMes) {
            while ($r = mysqli_fetch_assoc($qMes)) {
                if (strtoupper(trim((string) ($r['cierre'] ?? ''))) === 'C') {
                    $mesCerrado[trim((string) ($r['fecha'] ?? ''))] = true;
                }
            }
        }
    }

    // Días cerrados.
    $diaCerrado = [];
    if ($fechas !== []) {
        $inDia = mort_listado_sql_in_strings($conn, array_keys($fechas));
        $qDia = @mysqli_query($conn, "SELECT fecha, cerra, cerrajoya FROM dola WHERE fecha IN ({$inDia})");
        if ($qDia) {
            while ($r = mysqli_fetch_assoc($qDia)) {
                $cerra = strtoupper(trim((string) ($r['cerra'] ?? '')));
                $cerrajoya = strtoupper(trim((string) ($r['cerrajoya'] ?? '')));
                if ($cerra === 'C' || $cerrajoya === 'C') {
                    $diaCerrado[trim((string) ($r['fecha'] ?? ''))] = true;
                }
            }
        }
    }

    foreach ($rows as &$row) {
        $row['fechaCerrada'] = false;
        $row['motivoCierre'] = '';
        $f = trim((string) ($row['fechaRegistro'] ?? ''));
        $ts = strtotime($f);
        if ($ts === false) {
            continue;
        }
        $fecha = date('Y-m-d', $ts);
        if ($eano !== '' && substr($fecha, 0, 4) !== $eano) {
            $row['fechaCerrada'] = true;
            $row['motivoCierre'] = 'El año de la fecha no corresponde al año contable abierto.';
        } elseif (isset($mesCerrado[substr($fecha, 0, 7)])) {
            $row['fechaCerrada'] = true;
            $row['motivoCierre'] = 'El mes de la fecha está cerrado.';
        } elseif (isset($diaCerrado[$fecha])) {
            $row['fechaCerrada'] = true;
            $row['motivoCierre'] = 'El día de la fecha está cerrado.';
        }
    }
    unset($row);

    return $rows;
}

/**
 * Enriquece filas con bloqueos de edición/eliminación: campaña inactiva y
 * periodo contable cerrado. También limpia el nombre de granja si corresponde.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function mort_listado_enrich_bloqueos(mysqli $conn, array $rows): array
{
    $rows = mort_listado_enrich_campania_activa($conn, $rows);

    return mort_listado_enrich_fecha_cerrada($conn, $rows);
}

/**
 * Elimina por completo un registro de mortalidad dentro de una transacción activa:
 * movi_zonas y cabe_zonas, limpiando además las referencias en san_mortalidad_auditoria.
 *
 * Devuelve el snapshot previo (para historial de acciones).
 *
 * @return array<string, mixed>
 */
function mort_listado_eliminar_registro_completo(mysqli $conn, string $id): array
{
    $idEsc = mysqli_real_escape_string($conn, $id);

    // Marks (tipos) presentes en cabe_zonas para este registro.
    $marks = [];
    $qMarks = mysqli_query($conn, "SELECT DISTINCT mark FROM cabe_zonas WHERE external_id = '{$idEsc}' AND TRIM(mark) <> ''");
    if ($qMarks) {
        while ($row = mysqli_fetch_assoc($qMarks)) {
            $m = trim((string) ($row['mark'] ?? ''));
            if ($m !== '') {
                $marks[] = $m;
            }
        }
    }
    if ($marks === []) {
        // Registro que solo vive en el espejo auxiliar (0 aves sin documento).
        $qFactCab = mysqli_query($conn, "SELECT * FROM san_fact_mortalidad_cab WHERE id = '{$idEsc}' LIMIT 1");
        $factCab = $qFactCab ? mysqli_fetch_assoc($qFactCab) : null;
        if (!$factCab) {
            throw new RuntimeException('Registro no encontrado');
        }
        $datosPrevios['san_fact_mortalidad_cab'] = $factCab;
        $detFactRows = [];
        $qDetFact = mysqli_query($conn, "SELECT * FROM san_fact_mortalidad_det WHERE cabId = '{$idEsc}'");
        if ($qDetFact) {
            while ($rowDetF = mysqli_fetch_assoc($qDetFact)) {
                $detFactRows[] = $rowDetF;
            }
        }
        $datosPrevios['san_fact_mortalidad_det'] = $detFactRows;
        $okDelDet0 = mysqli_query($conn, "DELETE FROM san_fact_mortalidad_det WHERE cabId = '{$idEsc}'");
        if (!$okDelDet0) {
            throw new RuntimeException('Error al eliminar san_fact_mortalidad_det: ' . mysqli_error($conn));
        }
        $okDelCab0 = mysqli_query($conn, "DELETE FROM san_fact_mortalidad_cab WHERE id = '{$idEsc}'");
        if (!$okDelCab0) {
            throw new RuntimeException('Error al eliminar san_fact_mortalidad_cab: ' . mysqli_error($conn));
        }
        return $datosPrevios;
    }

    // Total de aves antes de borrar (para auditoria)
    $totalAvesCab = 0;
    $qTot = mysqli_query($conn, "
        SELECT COALESCE(SUM(mz.tcantid), 0) AS total
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id = '{$idEsc}'
    ");
    if ($qTot) {
        $totalAvesCab = (int) (mysqli_fetch_assoc($qTot)['total'] ?? 0);
    }

    // Snapshot completo antes de borrar
    $datosPrevios = [
        'cabe_zonas' => [],
        'movi_zonas' => [],
        'san_mortalidad_auditoria' => [],
        'totalAves' => $totalAvesCab,
    ];

    $audIdsUnicos = [];
    foreach ($marks as $mark) {
        $markEsc = mysqli_real_escape_string($conn, $mark);
        $qCz = mysqli_query($conn, "SELECT * FROM cabe_zonas WHERE external_id = '{$idEsc}' AND mark = '{$markEsc}'");
        if ($qCz) {
            while ($row = mysqli_fetch_assoc($qCz)) {
                $datosPrevios['cabe_zonas'][] = $row;
            }
        }
        $qMz = mysqli_query($conn, "
            SELECT mz.* FROM movi_zonas mz
            INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
            WHERE cz.external_id = '{$idEsc}' AND cz.mark = '{$markEsc}'
        ");
        if ($qMz) {
            while ($row = mysqli_fetch_assoc($qMz)) {
                $datosPrevios['movi_zonas'][] = $row;
                $audRef = trim((string) ($row['internal_auditoria'] ?? ''));
                if ($audRef !== '') {
                    $audIdsUnicos[$audRef] = true;
                }
            }
        }
    }

    foreach (array_keys($audIdsUnicos) as $audIdSnap) {
        $audIdEscSnap = mysqli_real_escape_string($conn, $audIdSnap);
        $qAudSnap = mysqli_query($conn, "SELECT * FROM san_mortalidad_auditoria WHERE id = '{$audIdEscSnap}' LIMIT 1");
        if ($qAudSnap && ($audSnap = mysqli_fetch_assoc($qAudSnap))) {
            $datosPrevios['san_mortalidad_auditoria'][] = $audSnap;
        }
    }

    // Limpiar referencias de auditoria
    foreach (array_keys($audIdsUnicos) as $audIdAud) {
        $audIdEscA = mysqli_real_escape_string($conn, $audIdAud);
        $qAud = mysqli_query($conn, "SELECT registros FROM san_mortalidad_auditoria WHERE id = '{$audIdEscA}' LIMIT 1");
        if (!$qAud) {
            continue;
        }
        $audData = mysqli_fetch_assoc($qAud);
        if (!$audData) {
            continue;
        }
        $registros = json_decode($audData['registros'], true);
        if (!is_array($registros)) {
            continue;
        }

        $changed = false;
        $nuevosRegistros = [];
        $nuevoTotalReg = 0;
        $nuevoTotalAves = 0;

        foreach ($registros as $grupo) {
            if (!is_array($grupo)) {
                continue;
            }
            $uuids = $grupo['registro_uuids'] ?? [];
            $grupoChanged = false;

            if (is_array($uuids)) {
                if (array_key_exists($id, $uuids)) {
                    unset($uuids[$id]);
                    $grupo['registro_uuids'] = $uuids;
                    $grupoChanged = true;
                } elseif (($idx = array_search($id, $uuids)) !== false) {
                    array_splice($uuids, $idx, 1);
                    $grupo['registro_uuids'] = $uuids;
                    $grupoChanged = true;
                }
            }

            if ($grupoChanged) {
                $grupo['totalRegistros'] = is_array($grupo['registro_uuids'] ?? null) ? count($grupo['registro_uuids']) : 0;
                $grupo['totalAves'] = max(0, (int) ($grupo['totalAves'] ?? 0) - $totalAvesCab);
                $changed = true;
            }

            $nuevoTotalReg += (int) ($grupo['totalRegistros'] ?? 0);
            $nuevoTotalAves += (int) ($grupo['totalAves'] ?? 0);
            $nuevosRegistros[] = $grupo;
        }

        if ($changed) {
            $jsonReg = json_encode($nuevosRegistros, JSON_UNESCAPED_UNICODE);
            if ($jsonReg === false) {
                continue;
            }
            $jsonEsc = mysqli_real_escape_string($conn, $jsonReg);
            $okAud = mysqli_query($conn, "UPDATE san_mortalidad_auditoria SET registros = '{$jsonEsc}', totalRegistros = {$nuevoTotalReg}, totalAves = {$nuevoTotalAves} WHERE id = '{$audIdEscA}'");
            if (!$okAud) {
                throw new RuntimeException('Error al actualizar auditoria: ' . mysqli_error($conn));
            }
        }
    }

    // 1. Eliminar movi_zonas (JOIN con cabe_zonas para cada mark)
    foreach ($marks as $mark) {
        $markEsc = mysqli_real_escape_string($conn, $mark);
        $okMz = mysqli_query($conn, "
            DELETE mz FROM movi_zonas mz
            INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
            WHERE cz.external_id = '{$idEsc}' AND cz.mark = '{$markEsc}'
        ");
        if (!$okMz) {
            throw new RuntimeException('Error al eliminar movi_zonas: ' . mysqli_error($conn));
        }
    }

    // 2. Eliminar cabe_zonas
    foreach ($marks as $mark) {
        $markEsc = mysqli_real_escape_string($conn, $mark);
        $okCz = mysqli_query($conn, "DELETE FROM cabe_zonas WHERE external_id = '{$idEsc}' AND mark = '{$markEsc}'");
        if (!$okCz) {
            throw new RuntimeException('Error al eliminar cabe_zonas: ' . mysqli_error($conn));
        }
    }

    // 3. Limpiar el espejo auxiliar san_fact_mortalidad_cab/det.
    $okDelFactDet = mysqli_query($conn, "DELETE FROM san_fact_mortalidad_det WHERE cabId = '{$idEsc}'");
    if (!$okDelFactDet) {
        throw new RuntimeException('Error al eliminar san_fact_mortalidad_det: ' . mysqli_error($conn));
    }
    $okDelFactCab = mysqli_query($conn, "DELETE FROM san_fact_mortalidad_cab WHERE id = '{$idEsc}'");
    if (!$okDelFactCab) {
        throw new RuntimeException('Error al eliminar san_fact_mortalidad_cab: ' . mysqli_error($conn));
    }

    return $datosPrevios;
}

/**
 * @param array<string, mixed> $filtros
 * @return list<array{campania: string, codigo: string}>
 */
function mort_listado_opciones_campanias_hc(mysqli $conn, string $granja3, array $filtros): array
{
    $g3 = substr(str_pad(trim($granja3), 3, '0', STR_PAD_LEFT), 0, 3);
    if ($g3 === '') {
        return [];
    }

    require_once __DIR__ . '/../../../core/lib/filtro_periodo_util.php';
    require_once __DIR__ . '/../../../core/lib/hc/hc_campanias_modal_helpers.php';

    $byGranja = [$g3 => []];
    $periodoTipo = trim((string) ($filtros['periodoTipo'] ?? 'TODOS'));
    $rango = periodo_a_rango([
        'periodoTipo' => $periodoTipo,
        'fechaUnica' => (string) ($filtros['fechaUnica'] ?? ''),
        'fechaInicio' => (string) ($filtros['fechaInicio'] ?? ''),
        'fechaFin' => (string) ($filtros['fechaFin'] ?? ''),
        'mesUnico' => (string) ($filtros['mesUnico'] ?? ''),
        'mesInicio' => (string) ($filtros['mesInicio'] ?? ''),
        'mesFin' => (string) ($filtros['mesFin'] ?? ''),
    ]);
    if ($periodoTipo !== 'TODOS' && $rango === null) {
        return [];
    }

    hc_merge_campanias_desde_hc_cab($conn, $byGranja, [$g3], $rango);

    $map = [];
    foreach ($byGranja[$g3] ?? [] as $row) {
        $camp = trim((string) ($row['campania'] ?? ''));
        if ($camp === '' || $camp === '000') {
            continue;
        }
        $map[$camp] = ['campania' => $camp, 'codigo' => $g3 . $camp];
    }

    // Fuente ccos (misma lógica que la app en get_cencos_galpones.php):
    // todas las campañas activas del catálogo de centros, sin filtrar por fecha.
    $gEscCcos = mysqli_real_escape_string($conn, $g3);
    $qCcos = @$conn->query("
        SELECT DISTINCT RIGHT(c.codigo, 3) AS campania
        FROM ccos c
        WHERE LEFT(c.codigo, 3) = '{$gEscCcos}'
          AND LEFT(c.codigo, 1) = '6'
          AND RIGHT(c.codigo, 3) <> '000'
          AND c.swac = 'A'
          AND CHAR_LENGTH(c.codigo) = 6
        ORDER BY campania ASC
    ");
    if ($qCcos) {
        while ($rCcos = $qCcos->fetch_assoc()) {
            $camp = substr(str_pad(trim((string) ($rCcos['campania'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
            if ($camp !== '' && $camp !== '000' && !isset($map[$camp])) {
                $map[$camp] = ['campania' => $camp, 'codigo' => $g3 . $camp];
            }
        }
    }

    $gEsc = mysqli_real_escape_string($conn, $g3);
    $sqlMort = "SELECT DISTINCT RIGHT(TRIM(mz.tcencos), 3) AS campania
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.mark IN ('JI1','JT2','JP3','JD4')
            AND LEFT(TRIM(mz.tcencos), 3) = '{$gEsc}'
            AND TRIM(mz.tcencos) <> ''";
    if ($rango) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $sqlMort .= " AND cz.tfecrem BETWEEN '{$desde}' AND '{$hasta}'";
    }
    $sqlMort .= ' ORDER BY campania ASC';
    $resMort = @$conn->query($sqlMort);
    if ($resMort) {
        while ($row = $resMort->fetch_assoc()) {
            $camp = substr(str_pad(trim((string) ($row['campania'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
            if ($camp !== '' && $camp !== '000' && !isset($map[$camp])) {
                $map[$camp] = ['campania' => $camp, 'codigo' => $g3 . $camp];
            }
        }
    }

    $out = array_values($map);
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) $a['campania'], (string) $b['campania']);
    });

    return $out;
}

/**
 * @return list<string>
 */
function mort_listado_opciones_galpones(mysqli $conn, string $granja3, string $campania): array
{
    $g3 = substr(str_pad(trim($granja3), 3, '0', STR_PAD_LEFT), 0, 3);
    $c3 = substr(str_pad(trim($campania), 3, '0', STR_PAD_LEFT), 0, 3);
    if ($g3 === '' || $c3 === '' || $c3 === '000') {
        return [];
    }

    require_once __DIR__ . '/../../../core/lib/sip_historia_clinica_cab_lib.php';
    $gps = sip_hc_cab_galpones_granja_campania($conn, $g3, $c3);
    $out = [];
    foreach ($gps as $g) {
        $gal = trim((string) ($g['galpon'] ?? $g['tcodint'] ?? ''));
        if ($gal !== '') {
            $out[] = $gal;
        }
    }

    if ($out !== []) {
        return $out;
    }

    $cenco = $g3 . $c3;
    $cencoEsc = mysqli_real_escape_string($conn, $cenco);
    $prefEsc = mysqli_real_escape_string($conn, $g3);
    $sql = "SELECT DISTINCT g.tcodint AS galpon
            FROM regcencosgalpones g
            WHERE g.tcencos = '{$prefEsc}'
            ORDER BY g.tcodint ASC";
    $res = @$conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $gal = trim((string) ($row['galpon'] ?? ''));
            if ($gal !== '') {
                $out[] = $gal;
            }
        }
    }

    if ($out === []) {
        $where = " WHERE cz.mark IN ('JI1','JT2','JP3','JD4')
            AND LEFT(TRIM(mz.tcencos), 3) = '{$prefEsc}'
            AND RIGHT(TRIM(mz.tcencos), 3) = '{$c3}' ";
        $sql2 = "SELECT DISTINCT mz.tcodint AS galpon
            FROM movi_zonas mz
            INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
            {$where}
            ORDER BY galpon ASC";
        $res2 = @$conn->query($sql2);
        if ($res2) {
            while ($row = $res2->fetch_assoc()) {
                $gal = trim((string) ($row['galpon'] ?? ''));
                if ($gal !== '') {
                    $out[] = $gal;
                }
            }
        }
    }

    return $out;
}

/** @deprecated use mort_listado_opciones_granjas_hc */
function mort_listado_opciones_cencos(mysqli $conn): array
{
    $rows = [];
    foreach (mort_listado_opciones_granjas_hc($conn) as $g) {
        $rows[] = [
            'codigo' => (string) $g['granja'],
            'nombre' => (string) $g['nombre'],
            'granja' => (string) $g['granja'],
            'campania' => '',
        ];
    }

    return $rows;
}

/**
 * Subconsulta única de cabecera desde cabe_zonas/movi_zonas (con totales precalculados).
 * El alias externo es `c` para preservar las cláusulas WHERE/ORDER existentes.
 */
function mort_listado_sql_cabecera_zonas(mysqli $conn): string
{
    $obsSel = mort_listado_tabla_fact_disponible($conn)
        ? "COALESCE(NULLIF(TRIM(MAX(cz.observaciones)), ''), (
            SELECT NULLIF(TRIM(f.observaciones), '')
            FROM san_fact_mortalidad_cab f
            WHERE f.id = cz.external_id
            LIMIT 1
          )) AS observaciones"
        : 'MAX(cz.observaciones) AS observaciones';

    $ramas = [];
    $ramas[] = "
        SELECT
            cz.external_id AS id,
            cz.tdoc AS doc,
            CONCAT(LEFT(cz.tserie, 3), '-', RIGHT(cz.tserie, 1)) AS serie,
            LPAD(TRIM(LEADING '0' FROM RIGHT(CAST(cz.tnumfac AS CHAR), 7)), 6, '0') AS numero,
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
            cz.tfectra AS fechaLlegada,
            {$obsSel},
            cz.tuser AS usuarioRegistro,
            MAX(CONCAT(cz.tdate, ' ', cz.ttime)) AS fechaHoraRegistro,
            cz.tnumfac AS tnumfac,
            SUM(CASE WHEN mz.tcodigo = 'P0001001' THEN mz.tcantid ELSE 0 END) AS machos,
            SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END) AS hembras,
            SUM(mz.tcantid) AS totalAves
        FROM cabe_zonas cz
        INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
            AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
        LEFT JOIN ccos cc ON cc.codigo = CONCAT(LEFT(mz.tcencos, 3), '000')
        WHERE cz.mark IN ('JI1','JT2','JP3','JD4')
        GROUP BY cz.external_id, cz.tdoc, cz.tserie, cz.tnumfac, cz.mark,
                 cz.tfecrem, cz.tfectra, cz.tuser, cz.tdate, cz.ttime
    ";

    // Registros sin mortalidad (0 aves): solo existen en el espejo
    // san_fact_mortalidad_cab, sin filas en cabe_zonas/movi_zonas.
    if (mort_listado_tabla_fact_disponible($conn)) {
        $ramas[] = "
        SELECT
            f.id AS id,
            f.doc AS doc,
            NULLIF(TRIM(f.serie), '') AS serie,
            NULLIF(TRIM(f.numero), '') AS numero,
            f.tipoMortalidad AS tipoMortalidad,
            NULLIF(LOWER(TRIM(f.subtipoTransporte)), '') AS subtipoTransporte,
            NULLIF(LOWER(TRIM(f.subtipoProduccion)), '') AS subtipoProduccion,
            f.granja AS granja,
            f.campania AS campania,
            COALESCE(NULLIF(TRIM(f.granjaNombre), ''), cc.nombre) AS granjaNombre,
            NULLIF(TRIM(f.galpon), '') AS galpon,
            f.fechaRegistro AS fechaRegistro,
            f.fechaLlegada AS fechaLlegada,
            NULLIF(TRIM(f.observaciones), '') AS observaciones,
            f.usuarioRegistro AS usuarioRegistro,
            DATE_FORMAT(f.fechaHoraRegistro, '%Y-%m-%d %H:%i:%s') AS fechaHoraRegistro,
            '' AS tnumfac,
            0 AS machos,
            0 AS hembras,
            0 AS totalAves
        FROM san_fact_mortalidad_cab f
        LEFT JOIN ccos cc ON cc.codigo = CONCAT(f.granja, '000')
        WHERE NULLIF(TRIM(f.serie), '') IS NULL
          AND NULLIF(TRIM(f.numero), '') IS NULL
          AND NOT EXISTS (
            SELECT 1
            FROM cabe_zonas cz0
            INNER JOIN movi_zonas mz0 ON mz0.mark = cz0.mark AND mz0.treg = cz0.treg
                AND mz0.tdoc = cz0.tdoc AND mz0.tserie = cz0.tserie AND mz0.tnumfac = cz0.tnumfac
            WHERE cz0.external_id = f.id
          )
        ";
    }

    return '(' . implode(' UNION ALL ', $ramas) . ') c';
}

function mort_listado_sql_from(mysqli $conn): string
{
    return ' FROM ' . mort_listado_sql_cabecera_zonas($conn) . ' ';
}

function mort_listado_sql_from_cab(mysqli $conn): string
{
    return ' FROM ' . mort_listado_sql_cabecera_zonas($conn) . ' ';
}

/**
 * @param list<string> $tiposPerm
 * @return list<array<string, mixed>>
 */
function mort_listado_fetch_registros(
    mysqli $conn,
    array $tiposPerm,
    array $filtros,
    ?int $limit = null,
    int $offset = 0
): array {
    $where = mort_listado_where_filtros($conn, $tiposPerm, $filtros);
    $limitSql = '';
    if ($limit !== null && $limit > 0) {
        $offset = max(0, $offset);
        $limitSql = ' LIMIT ' . $offset . ', ' . $limit;
    }

    $sql = "
        SELECT
            c.id,
            c.serie,
            c.numero,
            c.tipoMortalidad,
            c.subtipoTransporte,
            c.subtipoProduccion,
            c.granja,
            c.campania,
            c.granjaNombre,
            c.galpon,
            c.fechaRegistro,
            c.usuarioRegistro,
            c.totalAves,
            c.machos,
            c.hembras
        " . mort_listado_sql_from($conn) . "
        {$where}
        ORDER BY c.fechaHoraRegistro DESC, c.serie DESC, c.numero DESC
        {$limitSql}
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $row['tipoLabel'] = mort_listado_label_tipo((string) ($row['tipoMortalidad'] ?? ''));
        $row['subtipoLabel'] = mort_listado_label_subtipo(
            (string) ($row['tipoMortalidad'] ?? ''),
            $row['subtipoTransporte'] ?? null,
            $row['subtipoProduccion'] ?? null
        );
        $serieNum = trim((string) ($row['serie'] ?? ''));
        $numeroNum = trim((string) ($row['numero'] ?? ''));
        $row['documento'] = $serieNum !== '' && $numeroNum !== ''
            ? $serieNum . '-' . $numeroNum
            : '';
        $row['cenco'] = trim((string) ($row['granja'] ?? '') . (string) ($row['campania'] ?? ''));
        $rows[] = $row;
    }

    return mort_listado_enrich_bloqueos($conn, $rows);
}

/**
 * @param list<string> $tiposPerm
 * @return array{registros: int, totalAves: int, machos: int, hembras: int}
 */
function mort_listado_fetch_resumen(
    mysqli $conn,
    array $tiposPerm,
    array $filtros
): array {
    $where = mort_listado_where_filtros($conn, $tiposPerm, $filtros);
    $def = ['registros' => 0, 'totalAves' => 0, 'machos' => 0, 'hembras' => 0];

    $fromCab = mort_listado_sql_from_cab($conn);
    $qCount = mysqli_query($conn, 'SELECT COUNT(*) AS total ' . $fromCab . $where);
    if ($qCount && ($r = mysqli_fetch_assoc($qCount))) {
        $def['registros'] = (int) ($r['total'] ?? 0);
    }

    // Totales desde las columnas precalculadas de la subconsulta de zonas.
    $qRes = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(c.totalAves), 0) AS totalAves,
            COALESCE(SUM(c.machos), 0) AS machos,
            COALESCE(SUM(c.hembras), 0) AS hembras
        " . $fromCab . "
        {$where}");
    if ($qRes && ($r = mysqli_fetch_assoc($qRes))) {
        $def['totalAves'] = (int) ($r['totalAves'] ?? 0);
        $def['machos'] = (int) ($r['machos'] ?? 0);
        $def['hembras'] = (int) ($r['hembras'] ?? 0);
    }

    return $def;
}

function mort_listado_formato_fecha_pdf(string $fecha): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fecha, $m)) {
        return $fecha;
    }

    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_listado_texto_filtros_aplicados(array $filtros): string
{
    $partes = [];
    require_once __DIR__ . '/../../../core/lib/filtro_periodo_util.php';
    $pt = trim((string) ($filtros['periodoTipo'] ?? 'TODOS'));
    if ($pt !== '' && $pt !== 'TODOS') {
        $rango = periodo_a_rango([
            'periodoTipo' => $pt,
            'fechaUnica' => (string) ($filtros['fechaUnica'] ?? ''),
            'fechaInicio' => (string) ($filtros['fechaInicio'] ?? ''),
            'fechaFin' => (string) ($filtros['fechaFin'] ?? ''),
            'mesUnico' => (string) ($filtros['mesUnico'] ?? ''),
            'mesInicio' => (string) ($filtros['mesInicio'] ?? ''),
            'mesFin' => (string) ($filtros['mesFin'] ?? ''),
        ]);
        if ($rango) {
            $partes[] = 'Fecha: ' . mort_listado_formato_fecha_pdf($rango['desde'])
                . ($rango['desde'] !== $rango['hasta'] ? ' — ' . mort_listado_formato_fecha_pdf($rango['hasta']) : '');
        } else {
            $partes[] = 'Fecha: ' . $pt;
        }
    }
    $granja = trim((string) ($filtros['granja'] ?? ''));
    $camp = trim((string) ($filtros['campania'] ?? ''));
    if ($granja !== '') {
        $txt = 'Granja ' . $granja;
        if ($camp !== '' && $camp !== '000') {
            $txt .= ' / campaña ' . $camp;
        }
        $partes[] = $txt;
    }
    $galpon = trim((string) ($filtros['galpon'] ?? ''));
    if ($galpon !== '') {
        $partes[] = 'Galpon: ' . $galpon;
    }
    $claves = (array) ($filtros['clavesGrupo'] ?? []);
    if ($claves !== []) {
        require_once __DIR__ . '/../auditoria/mortalidad_auditoria_listado_lib.php';
        $map = [];
        foreach (mort_aud_listado_arbol_grupos() as $bloque) {
            foreach ($bloque['items'] ?? [] as $item) {
                $map[$item['key']] = $item['label'];
            }
        }
        $labels = array_map(static function (string $ck) use ($map): string {
            return $map[$ck] ?? $ck;
        }, $claves);
        $partes[] = 'Tipo reg.: ' . implode(', ', $labels);
    }

    return $partes !== [] ? implode(' · ', $partes) : 'Sin filtros adicionales (todos los registros permitidos)';
}

/**
 * Agrupa detalle por posicion (grupo app = macho + hembra).
 * Datos legacy: si todas las posiciones son mono-sexo, re-empareja M con H en orden.
 *
 * @param list<array<string, mixed>> $detalle
 * @return list<array{posicion: int, macho: ?array, hembra: ?array}>
 */
function mort_listado_agrupar_detalle(array $detalle): array
{
    $map = [];
    foreach ($detalle as $row) {
        $pos = (int) ($row['posicion'] ?? 0);
        if ($pos <= 0) {
            continue;
        }
        if (!isset($map[$pos])) {
            $map[$pos] = ['posicion' => $pos, 'macho' => null, 'hembra' => null];
        }
        $sexo = strtoupper(substr(trim((string) ($row['sexo'] ?? '')), 0, 1));
        if ($sexo === 'M') {
            $map[$pos]['macho'] = $row;
        } elseif ($sexo === 'H') {
            $map[$pos]['hembra'] = $row;
        }
    }
    ksort($map);

    $grupos = array_values($map);
    if ($grupos === []) {
        return [];
    }

    $tieneAmbos = false;
    foreach ($grupos as $g) {
        if ($g['macho'] !== null && $g['hembra'] !== null) {
            $tieneAmbos = true;
            break;
        }
    }
    if ($tieneAmbos) {
        return $grupos;
    }

    // Legacy: cada posicion tiene solo un sexo → re-emparejar M con H en orden
    $soloMachos = [];
    $soloHembras = [];
    foreach ($grupos as $g) {
        if ($g['macho'] !== null && $g['hembra'] === null) {
            $soloMachos[] = $g['macho'];
        } elseif ($g['hembra'] !== null && $g['macho'] === null) {
            $soloHembras[] = $g['hembra'];
        } elseif ($g['macho'] !== null && $g['hembra'] !== null) {
            // no debería llegar aquí
            $soloMachos[] = $g['macho'];
            $soloHembras[] = $g['hembra'];
        }
    }

    $reparado = [];
    $n = max(count($soloMachos), count($soloHembras));
    for ($i = 0; $i < $n; $i++) {
        $macho = $soloMachos[$i] ?? null;
        $hembra = $soloHembras[$i] ?? null;
        $posRef = 0;
        if ($macho !== null) {
            $posRef = (int) ($macho['posicion'] ?? 0);
        } elseif ($hembra !== null) {
            $posRef = (int) ($hembra['posicion'] ?? 0);
        }
        $reparado[] = [
            'posicion' => $posRef > 0 ? $posRef : ($i + 1),
            'macho' => $macho,
            'hembra' => $hembra,
        ];
    }

    return $reparado;
}

/**
 * @return list<string>
 */
function mort_listado_evidencia_urls(string $evidenciaCsv, ?string $verUploadPrefix = null): array
{
    if ($verUploadPrefix === null) {
        if (!function_exists('pa_ver_upload_prefix')) {
            require_once __DIR__ . '/../../../core/config.php';
        }
        $verUploadPrefix = pa_ver_upload_prefix(3);
    }
    $urls = [];
    foreach (preg_split('/\s*,\s*/', trim($evidenciaCsv)) ?: [] as $part) {
        $rel = mort_listado_normalize_evidencia_rel((string) $part);
        if ($rel === '') {
            continue;
        }
        if (preg_match('#^https?://#i', $rel)) {
            $urls[] = $rel;
            continue;
        }
        if (strpos($rel, 'uploads/mortalidad/') !== 0) {
            continue;
        }
        $urls[] = $verUploadPrefix . rawurlencode($rel);
    }

    return $urls;
}

/**
 * Convierte mark de zonas (JI1/JT2/JP3/JD4) a tipoMortalidad del contrato fact.
 */
function mort_listado_tipo_desde_mark(string $mark): string
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
            return 'produccion';
    }
}

/**
 * Cabecera desde el espejo san_fact_mortalidad_cab (registros sin cabe_zonas/movi_zonas).
 * El mismo formato de salida que mort_listado_cabecera_desde_zonas().
 *
 * @return array<string, mixed>|null
 */
function mort_listado_cabecera_desde_fact(mysqli $conn, string $id): ?array
{
    $fila = mort_fact_cab_por_id($conn, $id);
    if ($fila === null) {
        return null;
    }

    $granja = substr(str_pad(trim((string) ($fila['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
    $campania = substr(str_pad(trim((string) ($fila['campania'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);
    $tipoMortalidad = strtolower(trim((string) ($fila['tipoMortalidad'] ?? 'produccion')));
    $subtipoTransporteRaw = trim((string) ($fila['subtipoTransporte'] ?? ''));
    $subtipoProduccionRaw = trim((string) ($fila['subtipoProduccion'] ?? ''));
    $usuarioRegistro = trim((string) ($fila['usuarioRegistro'] ?? ''));

    $nombreGranja = trim((string) ($fila['granjaNombre'] ?? ''));
    if ($nombreGranja === '' && $granja !== '') {
        $nombreGranja = mort_listado_nombre_granja($conn, $granja);
    }

    $nombreUsuario = '';
    if ($usuarioRegistro !== '') {
        $stmt = $conn->prepare('SELECT nombre FROM usuario WHERE codigo = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $usuarioRegistro);
            $stmt->execute();
            $res = $stmt->get_result();
            $uRow = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            $nombreUsuario = trim((string) ($uRow['nombre'] ?? ''));
        }
    }

    return [
        'id' => $id,
        'doc' => trim((string) ($fila['doc'] ?? '')),
        'serie' => trim((string) ($fila['serie'] ?? '')),
        'numero' => trim((string) ($fila['numero'] ?? '')),
        'tipoMortalidad' => $tipoMortalidad,
        'subtipoTransporte' => $subtipoTransporteRaw !== '' ? strtolower($subtipoTransporteRaw) : null,
        'subtipoProduccion' => $subtipoProduccionRaw !== '' ? strtolower($subtipoProduccionRaw) : null,
        'granja' => $granja,
        'campania' => $campania,
        'granjaNombre' => $nombreGranja !== '' ? $nombreGranja : $granja,
        'galpon' => trim((string) ($fila['galpon'] ?? '')),
        'fechaRegistro' => trim((string) ($fila['fechaRegistro'] ?? '')),
        'fechaLlegada' => $tipoMortalidad === 'incubacion' ? trim((string) ($fila['fechaLlegada'] ?? '')) : null,
        'observaciones' => trim((string) ($fila['observaciones'] ?? '')),
        'usuarioRegistro' => $usuarioRegistro,
        'fechaHoraRegistro' => trim((string) ($fila['fechaHoraRegistro'] ?? '')),
        'numFac' => '',
        'nombreUsuario' => $nombreUsuario,
    ];
}

/**
 * Construye la cabecera (mismo contrato que san_fact_mortalidad_cab) desde
 * cabe_zonas/movi_zonas. Devuelve null si no existe el registro.
 *
 * @return array<string, mixed>|null
 */
function mort_listado_cabecera_desde_zonas(mysqli $conn, string $id): ?array
{
    $idEsc = mysqli_real_escape_string($conn, $id);
    $q = mysqli_query($conn, "
        SELECT
            cz.external_id,
            cz.tdoc,
            cz.tserie,
            cz.tnumfac,
            cz.mark,
            cz.tfecrem,
            cz.tfectra,
            cz.observaciones,
            cz.tuser,
            cz.tdate,
            cz.ttime,
            mz.tcencos,
            mz.tcodint,
            mz.flujo,
            u.nombre AS nombreUsuario
        FROM cabe_zonas cz
        LEFT JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
            AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
        LEFT JOIN usuario u ON u.codigo = cz.tuser
        WHERE cz.external_id = '{$idEsc}'
        ORDER BY mz.idmovi ASC
        LIMIT 1
    ");
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        return mort_listado_cabecera_desde_fact($conn, $id);
    }

    $mark = strtoupper(trim((string) ($row['mark'] ?? '')));
    // Cabe_zonas sin filas en movi_zonas (0 aves): cabecera desde el espejo.
    if (mort_listado_texto_zonas_vacio((string) ($row['tcencos'] ?? ''))) {
        $desdeFact = mort_listado_cabecera_desde_fact($conn, $id);
        if ($desdeFact !== null) {
            return $desdeFact;
        }
    }
    $tipoMortalidad = mort_listado_tipo_desde_mark($mark);
    $tcencos = trim((string) ($row['tcencos'] ?? ''));
    $granja = substr(str_pad($tcencos, 6, '0', STR_PAD_LEFT), 0, 3);
    $campania = strlen($tcencos) >= 3 ? substr($tcencos, -3) : '';

    $flujo = trim((string) ($row['flujo'] ?? ''));
    $subtipoTransporte = null;
    $subtipoProduccion = null;
    if ($mark === 'JT2') {
        $subtipoTransporte = strtolower($flujo) === 'evento' ? 'evento' : 'transporte';
    } elseif ($mark === 'JP3') {
        $subtipoProduccion = strtolower($flujo);
    }

    $nombreGranja = mort_listado_nombre_granja($conn, $granja);

    $observaciones = trim((string) ($row['observaciones'] ?? ''));
    if (mort_listado_texto_zonas_vacio($observaciones)) {
        $observaciones = mort_listado_fact_observaciones_cab($conn, $id);
    }

    return [
        'id' => (string) ($row['external_id'] ?? ''),
        'doc' => trim((string) ($row['tdoc'] ?? 'GI')),
        'serie' => mort_serie_desde_tserie((string) ($row['tserie'] ?? '')),
        'numero' => mort_numero_desde_tnumfac($row['tnumfac'] ?? ''),
        'tipoMortalidad' => $tipoMortalidad,
        'subtipoTransporte' => $subtipoTransporte,
        'subtipoProduccion' => $subtipoProduccion,
        'granja' => $granja,
        'campania' => $campania,
        'granjaNombre' => $nombreGranja !== '' ? $nombreGranja : $granja,
        'galpon' => trim((string) ($row['tcodint'] ?? '')),
        'fechaRegistro' => trim((string) ($row['tfecrem'] ?? '')),
        'fechaLlegada' => $mark === 'JI1' ? trim((string) ($row['tfectra'] ?? '')) : null,
        'observaciones' => $observaciones,
        'usuarioRegistro' => trim((string) ($row['tuser'] ?? '')),
        'fechaHoraRegistro' => trim((string) ($row['tdate'] ?? '') . ' ' . (string) ($row['ttime'] ?? '')),
        'numFac' => trim((string) ($row['tnumfac'] ?? '')),
        'nombreUsuario' => trim((string) ($row['nombreUsuario'] ?? '')),
    ];
}

/**
 * Construye el detalle (mismo contrato que san_fact_mortalidad_det) desde movi_zonas.
 *
 * @return list<array<string, mixed>>
 */
function mort_listado_detalle_desde_zonas(mysqli $conn, string $id, ?string $verUploadPrefix = null): array
{
    if ($verUploadPrefix === null) {
        if (!function_exists('pa_ver_upload_prefix')) {
            require_once __DIR__ . '/../../../core/config.php';
        }
        $verUploadPrefix = pa_ver_upload_prefix(3);
    }
    $idEsc = mysqli_real_escape_string($conn, $id);
    $q = mysqli_query($conn, "
        SELECT
            mz.idmovi,
            mz.tcodigo,
            mz.tcantid,
            mz.tcod_mortgrs,
            mz.observaciones,
            mz.evidencia,
            r.tnom_mort AS nomMortalidad
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        LEFT JOIN regmotivo_mortalidadgrs r ON r.tcod_mort = mz.tcod_mortgrs
        WHERE cz.external_id = '{$idEsc}'
        ORDER BY mz.idmovi ASC, mz.tcodigo ASC
    ");

    $det = [];
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $pos = (int) ($row['idmovi'] ?? 0);
            $sexo = strtoupper(trim((string) ($row['tcodigo'] ?? ''))) === 'P0001002' ? 'H' : 'M';
            $ev = (string) ($row['evidencia'] ?? '');
            $det[] = [
                'id' => sprintf('%s-%02d-%s', $id, $pos, $sexo),
                'posicion' => $pos,
                'sexo' => $sexo,
                'cantidad' => (int) ($row['tcantid'] ?? 0),
                'codMortalidad' => trim((string) ($row['tcod_mortgrs'] ?? '')),
                'nomMortalidad' => trim((string) ($row['nomMortalidad'] ?? '')),
                'observacion' => trim((string) ($row['observaciones'] ?? '')),
                'evidencia' => $ev,
                'evidenciaUrls' => mort_listado_evidencia_urls($ev, $verUploadPrefix),
            ];
        }
    }

    $det = mort_listado_aplicar_fallback_detalle_fact($conn, $id, $det, $verUploadPrefix);

    // Etapas de despacho desde el espejo san_fact_mortalidad_det.
    $etapasMap = mort_fact_etapas_por_cab($conn, $id);
    if ($etapasMap !== []) {
        foreach ($det as &$rowDet) {
            $key = (int) ($rowDet['posicion'] ?? 0) . '-' . strtoupper(substr(trim((string) ($rowDet['sexo'] ?? 'M')), 0, 1));
            $etapas = $etapasMap[$key] ?? null;
            if ($etapas === null) {
                continue;
            }
            foreach (MORT_FACT_ETAPA_COLS as $col) {
                $rowDet[$col] = (int) ($etapas[$col] ?? 0);
            }
            $rowDet['proceso_etapas'] = $etapas;
        }
        unset($rowDet);
    }

    return $det;
}

/**
 * Snapshot de historial leyendo únicamente zonas, conservando las claves
 * `san_fact_mortalidad_cab`/`san_fact_mortalidad_det` (mapeadas desde zonas)
 * para no romper el contrato de mortalidad_historial_lib.php.
 *
 * @return array<string, mixed>
 */
function mort_listado_snapshot_desde_zonas(mysqli $conn, string $id): array
{
    $idEsc = mysqli_real_escape_string($conn, $id);
    $snap = [
        'san_fact_mortalidad_cab' => mort_listado_cabecera_desde_zonas($conn, $id),
        'san_fact_mortalidad_det' => mort_listado_detalle_desde_zonas($conn, $id),
        'cabe_zonas' => [],
        'movi_zonas' => [],
        'san_mortalidad_auditoria' => [],
        'totalAves' => 0,
    ];

    $audIds = [];
    $qCz = mysqli_query($conn, "SELECT * FROM cabe_zonas WHERE external_id = '{$idEsc}'");
    if ($qCz) {
        while ($row = mysqli_fetch_assoc($qCz)) {
            $snap['cabe_zonas'][] = $row;
        }
    }
    $qMz = mysqli_query($conn, "
        SELECT mz.* FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id = '{$idEsc}'
    ");
    if ($qMz) {
        while ($row = mysqli_fetch_assoc($qMz)) {
            $snap['movi_zonas'][] = $row;
            $snap['totalAves'] += (int) ($row['tcantid'] ?? 0);
            $audRef = trim((string) ($row['internal_auditoria'] ?? ''));
            if ($audRef !== '') {
                $audIds[$audRef] = true;
            }
        }
    }

    foreach (array_keys($audIds) as $audId) {
        $audEsc = mysqli_real_escape_string($conn, $audId);
        $qAud = mysqli_query($conn, "SELECT * FROM san_mortalidad_auditoria WHERE id = '{$audEsc}' LIMIT 1");
        if ($qAud && ($audRow = mysqli_fetch_assoc($qAud))) {
            $snap['san_mortalidad_auditoria'][] = $audRow;
        }
    }

    return $snap;
}
