<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../core/lib/gri/mortalidad_auditoria_lib.php';
require_once __DIR__ . '/../admin/mortalidad_admin_lib.php';
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

function mort_aud_listado_tabla_existe(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = false;
    $rs = @$conn->query("SHOW TABLES LIKE 'san_mortalidad_auditoria'");
    if ($rs && $rs->num_rows > 0) {
        $cache = true;
    }

    return $cache;
}

/**
 * @return array<string, mixed>
 */
function mort_aud_listado_filtros_defecto(): array
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
function mort_aud_listado_parse_filtros(array $input): array
{
    $f = mort_aud_listado_filtros_defecto();
    foreach (array_keys($f) as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $val = $input[$k];
        if ($k === 'clavesGrupo') {
            if (is_array($val)) {
                $f[$k] = mort_aud_listado_normalizar_claves($val);
            } else {
                $str = trim((string) $val);
                $f[$k] = $str === ''
                    ? []
                    : mort_aud_listado_normalizar_claves(explode(',', $str));
            }
            continue;
        }
        if (is_array($val)) {
            if ($k === 'search' && isset($val['value'])) {
                $f[$k] = trim((string) $val['value']);
            }
            continue;
        }
        $f[$k] = trim((string) $val);
    }

    $gDigits = preg_replace('/\D/', '', $f['granja']) ?? '';
    $f['granja'] = $gDigits !== '' ? substr(str_pad($gDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    // Normalizar campania
    $cDigits = preg_replace('/\D/', '', $f['campania']) ?? '';
    $f['campania'] = $cDigits !== '' ? substr(str_pad($cDigits, 3, '0', STR_PAD_LEFT), 0, 3) : '';

    return $f;
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function mort_aud_listado_normalizar_claves($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $valid = [];
    foreach ($raw as $c) {
        $clave = trim((string) $c);
        if ($clave !== '' && in_array($clave, mort_aud_claves_validas(), true)) {
            $valid[$clave] = true;
        }
    }

    return array_keys($valid);
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_aud_listado_where_filtros(mysqli $conn, array $filtros): string
{
    $where = ' WHERE 1=1 ';

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
        $where .= " AND a.fecha BETWEEN '{$desde}' AND '{$hasta}' ";
    }

    $granja = trim((string) ($filtros['granja'] ?? ''));
    if ($granja !== '') {
        $gEsc = mysqli_real_escape_string($conn, $granja);
        $where .= " AND a.granja = '{$gEsc}' ";
    }

    $campania = trim((string) ($filtros['campania'] ?? ''));
    if ($campania !== '') {
        $campEsc = mysqli_real_escape_string($conn, $campania);
        $where .= " AND a.campania = '{$campEsc}' ";
    }

    $galpon = trim((string) ($filtros['galpon'] ?? ''));
    if ($galpon !== '') {
        $galEsc = mysqli_real_escape_string($conn, $galpon);
        $where .= " AND a.galpon = '{$galEsc}' ";
    }

    $claves = mort_aud_listado_normalizar_claves($filtros['clavesGrupo'] ?? []);
    $todasValidas = mort_aud_claves_validas();
    // Si se seleccionaron todas las claves validas, omitir filtro (equivale a "todas")
    if ($claves !== [] && count($claves) < count($todasValidas)) {
        $parts = [];
        foreach ($claves as $clave) {
            $claveEsc = mysqli_real_escape_string($conn, $clave);
            $claveLike = str_replace(['%', '_'], ['\\%', '\\_'], $claveEsc);
            $parts[] = "a.registros LIKE '%\"clave\":\"{$claveLike}\"%'";
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ') ';
    }

    $search = trim((string) ($filtros['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (' . mort_aud_listado_sql_busqueda($conn, $search) . ') ';
    }

    return $where;
}

/**
 * Búsqueda libre auditoría: fecha, granja, campaña, galpón, auditor, observaciones, grupos.
 */
function mort_aud_listado_sql_busqueda(mysqli $conn, string $search): string
{
    require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

    $sEsc = mysqli_real_escape_string($conn, $search);
    $sLower = function_exists('mb_strtolower')
        ? mb_strtolower($search, 'UTF-8')
        : strtolower($search);

    $ors = [
        "a.usuarioRegistro LIKE '%{$sEsc}%'",
        "a.observaciones LIKE '%{$sEsc}%'",
        "a.id LIKE '%{$sEsc}%'",
        "a.granja LIKE '%{$sEsc}%'",
        "a.campania LIKE '%{$sEsc}%'",
        "a.galpon LIKE '%{$sEsc}%'",
        "CONCAT(TRIM(IFNULL(a.granja,'')), TRIM(IFNULL(a.campania,''))) LIKE '%{$sEsc}%'",
        "a.fecha LIKE '%{$sEsc}%'",
        "a.fechaHoraRegistro LIKE '%{$sEsc}%'",
        "a.registros LIKE '%{$sEsc}%'",
        "EXISTS (
            SELECT 1 FROM usuario u
            WHERE u.codigo = a.usuarioRegistro
              AND u.nombre LIKE '%{$sEsc}%'
         )",
    ];

    $fechaYmd = mort_listado_search_fecha_ymd($search);
    if ($fechaYmd !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fechaYmd);
        $ors[] = "a.fecha = '{$fEsc}'";
        $ors[] = "DATE(a.fechaHoraRegistro) = '{$fEsc}'";
    }

    foreach (mort_admin_tipos_mortalidad() as $t) {
        $key = strtolower(trim((string) ($t['key'] ?? '')));
        $label = function_exists('mb_strtolower')
            ? mb_strtolower((string) ($t['label'] ?? ''), 'UTF-8')
            : strtolower((string) ($t['label'] ?? ''));
        if ($key === '') {
            continue;
        }
        if ($sLower === $key || $sLower === $label || strpos($label, $sLower) !== false) {
            $kEsc = mysqli_real_escape_string($conn, $key);
            $ors[] = "a.registros LIKE '%\"tipo_mortalidad\":\"{$kEsc}\"%'";
            $ors[] = "a.registros LIKE '%\"clave\":\"{$kEsc}%'";
        }
    }
    foreach (array_merge(mort_listado_subtipos_transporte(), mort_listado_subtipos_produccion()) as $key => $label) {
        $lab = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if ($sLower === $key || $sLower === $lab || strpos($lab, $sLower) !== false) {
            $kEsc = mysqli_real_escape_string($conn, $key);
            $lEsc = mysqli_real_escape_string($conn, $label);
            $ors[] = "a.registros LIKE '%{$kEsc}%'";
            $ors[] = "a.registros LIKE '%{$lEsc}%'";
        }
    }

    return implode(' OR ', $ors);
}

/**
 * @return list<array<string, mixed>>
 */
function mort_aud_listado_parse_registros_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $clave = trim((string) ($item['clave'] ?? ''));
        $out[] = [
            'clave' => $clave,
            'label' => trim((string) ($item['label'] ?? '')) !== ''
                ? (string) $item['label']
                : mort_aud_grupo_label($clave),
            'tipo_mortalidad' => (string) ($item['tipo_mortalidad'] ?? ''),
            'subtipo' => $item['subtipo'] ?? null,
            'totalRegistros' => (int) ($item['totalRegistros'] ?? 0),
            'totalAves' => (int) ($item['totalAves'] ?? 0),
            'observaciones' => $item['observaciones'] ?? null,
            'evidencia' => $item['evidencia'] ?? null,
            'registro_uuids' => is_array($item['registro_uuids'] ?? null) ? $item['registro_uuids'] : [],
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mort_aud_listado_formatear_fila(array $row, mysqli $conn = null): array
{
    $grupos = mort_aud_listado_parse_registros_json((string) ($row['registros'] ?? ''));
    $labels = [];
    foreach ($grupos as $g) {
        $labels[] = (string) ($g['label'] ?? '');
    }
    $row['grupos'] = $grupos;
    $row['gruposLabel'] = implode(', ', array_filter($labels));
    $row['numGrupos'] = count($grupos);

    // Nombre del auditor desde tabla usuario
    if (empty($row['nombreAuditor']) && !empty($row['usuarioRegistro'])) {
        $row['nombreAuditor'] = mort_aud_nombre_auditor($conn ?? null, (string) ($row['usuarioRegistro'] ?? ''));
    }

    // Nombre de granja
    if (!empty($row['codGranja']) && empty($row['nombreGranja'])) {
        $row['nombreGranja'] = mort_aud_nombre_granja($conn ?? null, (string) $row['codGranja']);
    }

    return $row;
}

/**
 * Verifica si la tabla san_mortalidad_auditoria tiene la columna granja.
 * Auto-migración: agrega las columnas si no existen.
 */
function mort_aud_tiene_columna_granja(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = false;
    $rs = @$conn->query("SHOW COLUMNS FROM san_mortalidad_auditoria LIKE 'granja'");
    if ($rs && $rs->num_rows > 0) {
        $cache = true;
        return $cache;
    }

    // Auto-migración: agregar columnas granja, campania, galpon
    $ok = @$conn->query("ALTER TABLE san_mortalidad_auditoria
        ADD COLUMN granja   VARCHAR(3)  NULL AFTER fechaHoraRegistro,
        ADD COLUMN campania VARCHAR(3)  NULL AFTER granja,
        ADD COLUMN galpon   VARCHAR(20) NULL AFTER campania,
        ADD INDEX idx_mort_aud_granja (granja)");
    if ($ok) {
        // Agregar indice en campania si no existe
        @$conn->query("ALTER TABLE san_mortalidad_auditoria ADD INDEX idx_mort_aud_campania (campania)");
        $cache = true;
    }

    return $cache;
}

/**
 * Obtiene el nombre completo del usuario auditor desde la tabla usuario.
 */
function mort_aud_nombre_auditor($conn, string $codigoUsuario): string
{
    static $cache = [];
    if (!$conn || trim($codigoUsuario) === '') {
        return $codigoUsuario;
    }
    $key = trim($codigoUsuario);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $codEsc = mysqli_real_escape_string($conn, $key);
    $q = @$conn->query("SELECT nombre FROM usuario WHERE codigo = '{$codEsc}' LIMIT 1");
    if ($q && ($r = $q->fetch_assoc())) {
        $cache[$key] = trim((string) ($r['nombre'] ?? $key));
    } else {
        $cache[$key] = $key;
    }

    return $cache[$key];
}

/**
 * Obtiene el nombre de la granja desde la tabla ccos.
 */
function mort_aud_nombre_granja($conn, string $codGranja): string
{
    static $cache = [];
    if (!$conn || trim($codGranja) === '') {
        return $codGranja;
    }
    $key = trim($codGranja);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $g3 = substr(str_pad($key, 3, '0', STR_PAD_LEFT), 0, 3);
    $cod6 = $g3 . '000';
    $codEsc = mysqli_real_escape_string($conn, $cod6);
    $q = @$conn->query("SELECT nombre FROM ccos WHERE codigo = '{$codEsc}' LIMIT 1");
    if ($q && ($r = $q->fetch_assoc())) {
        $cache[$key] = trim((string) ($r['nombre'] ?? $key));
    } else {
        $cache[$key] = $key;
    }

    return $cache[$key];
}

/**
 * @param array<string, mixed> $filtros
 * @return array{sesiones: int, totalRegistros: int, totalAves: int, grupos: int}
 */
function mort_aud_listado_fetch_resumen(mysqli $conn, array $filtros): array
{
    if (!mort_aud_listado_tabla_existe($conn)) {
        return ['sesiones' => 0, 'totalRegistros' => 0, 'totalAves' => 0, 'grupos' => 0];
    }

    $where = mort_aud_listado_where_filtros($conn, $filtros);
    $res = [
        'sesiones' => 0,
        'totalRegistros' => 0,
        'totalAves' => 0,
        'grupos' => 0,
    ];

    $q = @mysqli_query($conn, "
        SELECT
            COUNT(*) AS sesiones,
            COALESCE(SUM(a.totalRegistros), 0) AS totalRegistros,
            COALESCE(SUM(a.totalAves), 0) AS totalAves
        FROM san_mortalidad_auditoria a
        {$where}
    ");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $res['sesiones'] = (int) ($r['sesiones'] ?? 0);
        $res['totalRegistros'] = (int) ($r['totalRegistros'] ?? 0);
        $res['totalAves'] = (int) ($r['totalAves'] ?? 0);
    }

    return $res;
}

/**
 * @return list<string>
 */
function mort_aud_listado_evidencia_urls(string $evidenciaCsv, ?string $verUploadPrefix = null): array
{
    require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

    return mort_listado_evidencia_urls($evidenciaCsv, $verUploadPrefix);
}

/**
 * Árbol tipo → subtipos para el filtro de grupos.
 *
 * @return list<array{tipo: string, label: string, items: list<array{key: string, label: string}>}>
 */
function mort_aud_listado_arbol_grupos(): array
{
    $porTipo = [];
    foreach (mort_aud_claves_validas() as $clave) {
        $partes = mort_aud_clave_partes($clave);
        $tipo = (string) ($partes['tipo_mortalidad'] ?? '');
        if ($tipo === '') {
            continue;
        }
        if (!isset($porTipo[$tipo])) {
            $porTipo[$tipo] = [
                'tipo' => $tipo,
                'label' => mort_listado_label_tipo($tipo),
                'items' => [],
            ];
        }
        $subLabel = mort_aud_grupo_label($clave);
        $tipoLabel = mort_listado_label_tipo($tipo);
        if (strpos($subLabel, $tipoLabel) === 0) {
            $subLabel = trim(preg_replace('/^' . preg_quote($tipoLabel, '/') . '\s*[—-]\s*/u', '', $subLabel) ?? $subLabel);
        }
        $porTipo[$tipo]['items'][] = [
            'key' => $clave,
            'label' => $subLabel !== '' ? $subLabel : mort_aud_grupo_label($clave),
        ];
    }

    $orden = ['incubacion', 'transporte', 'produccion', 'despacho'];
    $out = [];
    foreach ($orden as $tipo) {
        if (isset($porTipo[$tipo])) {
            $out[] = $porTipo[$tipo];
        }
    }

    return $out;
}

/**
 * @return list<array{key: string, label: string}>
 */
function mort_aud_listado_claves_grupo(): array
{
    $out = [];
    foreach (mort_aud_claves_validas() as $clave) {
        $out[] = ['key' => $clave, 'label' => mort_aud_grupo_label($clave)];
    }

    return $out;
}

/**
 * Pobla las columnas granja, campania, galpon en san_mortalidad_auditoria
 * para registros existentes que aun tengan NULL. Ejecuta una unica consulta
 * masiva con JOIN en lugar de subconsultas por fila.
 */
function mort_aud_migrar_granja_campania_galpon(mysqli $conn): void
{
    static $migrado = false;
    if ($migrado) {
        return;
    }
    // Verificar si la columna granja existe
    $rs = @$conn->query("SHOW COLUMNS FROM san_mortalidad_auditoria LIKE 'granja'");
    if (!$rs || $rs->num_rows === 0) {
        return;
    }

    // Primero obtener los IDs de los registros pendientes (granja IS NULL)
    $pendientes = [];
    $qPend = $conn->query("SELECT id FROM san_mortalidad_auditoria WHERE granja IS NULL LIMIT 100");
    if ($qPend) {
        while ($r = $qPend->fetch_assoc()) {
            $pendientes[] = $conn->real_escape_string($r['id']);
        }
    }
    if (empty($pendientes)) {
        $migrado = true;
        return;
    }
    $idsSql = "'" . implode("','", $pendientes) . "'";

    // Actualizar en un solo UPDATE con los IDs explicitos
    // (evita la subconsulta anidada con LIMIT que MySQL no resuelve bien)
    $conn->query("
        UPDATE san_mortalidad_auditoria a
        INNER JOIN (
            SELECT
                mz.internal_auditoria,
                MAX(LEFT(TRIM(mz.tcencos), 3)) AS grj,
                MAX(RIGHT(TRIM(mz.tcencos), 3)) AS cmp,
                MAX(NULLIF(TRIM(mz.tcodint), '')) AS glp
            FROM movi_zonas mz
            WHERE mz.internal_auditoria IN ({$idsSql})
            GROUP BY mz.internal_auditoria
        ) det ON det.internal_auditoria = a.id
        SET a.granja   = COALESCE(a.granja, det.grj),
            a.campania = COALESCE(a.campania, det.cmp),
            a.galpon   = COALESCE(a.galpon, det.glp)
        WHERE a.granja IS NULL AND a.id IN ({$idsSql})
    ");
    $migrado = true;
}
