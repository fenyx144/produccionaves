<?php
/**
 * ACL Sanidad: roles (adm_*) + módulos menú (amd_dashboard_modulos) + permisos (adm_rol_progr_modulo).
 * El id del programa se resuelve en amd_programas por nombre «Sanidad» (local y producción).
 */

declare(strict_types=1);

/** Nombre del programa en amd_programas. */
const SIP_ACL_NOMBRE_PROGRAMA_SANIDAD = 'Sanidad';

/** Código sugerido al crear el programa si la tabla tiene columna de código. */
const SIP_ACL_CODIGO_PROGRAMA_SANIDAD = 'SANIDAD';

/** Claves data-ix2-sip / hub: también pueden ir en `cod_interno` sin columna extra. */
const SIP_ACL_KNOWN_IX2 = [
    'plan_prog_reg', 'plan_prog_lst', 'plan_asig_reg', 'plan_asig_evt', 'plan_asig_lst',
    'seg_calendario', 'mue_reg', 'mue_lst', 'rep_comp', 'rep_est', 'rep_cons', 'rep_hc', 'rep_hc_liq', 'rep_hc_seg', 'agc',
];

/**
 * Rutas HC inyectadas en el shell (sidebar/hub): no dependen del catálogo adm ni de ix2_allow desde BD.
 *
 * @param array<string, mixed> $acl referencia resultado de sip_acl_init
 */
function sip_acl_inject_ix2_allow_historia_clinica(array &$acl): void
{
    if (!isset($acl['ix2_allow']) || !is_array($acl['ix2_allow'])) {
        $acl['ix2_allow'] = [];
    }
    if (!isset($acl['allowed']) || !is_array($acl['allowed'])) {
        return;
    }
    $hcMods = ['item-sp-4-5', 'item-sp-4-8'];
    $hcIx2Map = [
        'item-sp-4-5' => 'rep_hc',
        'item-sp-4-8' => 'rep_hc_liq',
    ];
    foreach ($hcMods as $cod) {
        if (!empty($acl['allowed'][$cod])) {
            $acl['ix2_allow'][$hcIx2Map[$cod]] = true;
        }
    }
}

/**
 * Compatibilidad si la BD usa nombres antiguos (p. ej. icon_class en lugar de icono).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sip_acl_normalize_mod_row(array $row): array
{
    // Esquema real (amd_dashboard_modulos): parent_cod, url, nom_mod, label_short, tipo_param
    if (isset($row['parent_cod']) && !array_key_exists('cod_padre', $row)) {
        $row['cod_padre'] = $row['parent_cod'];
    }
    if (isset($row['url']) && (empty($row['ruta']) || trim((string) $row['ruta']) === '')) {
        $row['ruta'] = $row['url'];
    }
    if (!empty($row['nom_mod'])) {
        if (empty($row['titulo']) || trim((string) $row['titulo']) === '') {
            $row['titulo'] = $row['nom_mod'];
        }
    }
    if (!empty($row['label_short'])) {
        if (empty($row['nom_menu']) || trim((string) $row['nom_menu']) === '') {
            $row['nom_menu'] = $row['label_short'];
        }
    } elseif (!empty($row['nom_mod']) && (empty($row['nom_menu']) || trim((string) $row['nom_menu']) === '')) {
        $row['nom_menu'] = $row['nom_mod'];
    }
    if (!empty($row['tipo_param']) && (empty($row['cod_interno']) || trim((string) $row['cod_interno']) === '')) {
        $row['cod_interno'] = $row['tipo_param'];
    }
    if (!empty($row['icon_class']) && (empty($row['icono']) || trim((string) $row['icono']) === '')) {
        $row['icono'] = $row['icon_class'];
    }
    if (!empty($row['descripcion_corta']) && (empty($row['nom_menu']) || trim((string) $row['nom_menu']) === '')) {
        $row['nom_menu'] = $row['descripcion_corta'];
    }
    if (isset($row['tipo']) && is_string($row['tipo']) && $row['tipo'] === 'cgroup') {
        $row['tipo'] = 'config_group';
    }
    return $row;
}

/**
 * Texto de botón: nom_menu, si no, titulo.
 *
 * @param array<string, mixed> $row
 */
function sip_acl_mod_label(array $row): string
{
    $ls = trim((string) ($row['label_short'] ?? ''));
    if ($ls !== '') {
        return $ls;
    }
    $nm = trim((string) ($row['nom_menu'] ?? ''));
    if ($nm !== '') {
        return $nm;
    }
    $nm2 = trim((string) ($row['nom_mod'] ?? ''));
    if ($nm2 !== '') {
        return $nm2;
    }
    return trim((string) ($row['titulo'] ?? ''));
}

/**
 * @param array<string, mixed> $row
 */
function sip_acl_mod_icono(array $row): string
{
    $i = trim((string) ($row['icono'] ?? ''));
    if ($i !== '') {
        return $i;
    }
    return trim((string) ($row['icon_class'] ?? 'fas fa-chevron-down'));
}

/**
 * Clave hub ix2: columna legada hub_ix2_key o cod_interno si coincide con SIP_ACL_KNOWN_IX2.
 *
 * @param array<string, mixed> $row
 */
function sip_acl_row_ix2_key(array $row): string
{
    $h = trim((string) ($row['hub_ix2_key'] ?? ''));
    if ($h !== '') {
        return $h;
    }
    $ci = trim((string) ($row['cod_interno'] ?? $row['tipo_param'] ?? ''));
    if ($ci !== '' && in_array($ci, SIP_ACL_KNOWN_IX2, true)) {
        return $ci;
    }
    return '';
}

/**
 * Carga en iframe con o sin contexto de proceso, según ruta (sin columna load_mode en BD).
 */
function sip_acl_infer_load_mode(?string $ruta): string
{
    if ($ruta === null || trim($ruta) === '') {
        return 'context';
    }
    $n = str_replace('\\', '/', strtolower($ruta));
    $sub = [
        'dashboard-general.php', 'muestras/dashboard/dashboard-dashboard', 'dashboard-indicadores',
        '/laboratorio/tracking/dashboard/', 'planificacion/dashboard/dashboard-planificacion',
        'muestras/listado_muestras/dashboard-reportes', 'laboratorio/trazabilidad/dashboard-trazabilidad',
        'planificacion/calendario/',
        'dashboard-comparativo.php', 'dashboard-estandares-necropsia', 'dashboard-informe-productos',
        'historia-clinica/',
        'historia-clinica-graficas/',
        'historia_clinica/',
        'estandares-granja/',
        'mortalidad/despacho/',
        'mortalidad/ventas/',
    ];
    foreach ($sub as $s) {
        if (strpos($n, $s) !== false) {
            return 'sin_contexto';
        }
    }
    return 'context';
}

/**
 * @return array{allowed: array<string,bool>, role_ids: int[], role_codes: string[], tables_ok: bool, degraded_full_access: bool, idx_path_allow: array<string,bool>, idx_path_has_rules: bool}
 */
function sip_acl_init(mysqli $conn, ?string $codigoUsuario): array
{
    $out = [
        'allowed' => [],
        'role_ids' => [],
        'role_codes' => [],
        'tables_ok' => sip_acl_sanidad_tables_ok($conn),
        'degraded_full_access' => false,
        'idx_path_allow' => [],
        'idx_path_has_rules' => false,
    ];

    if (!$out['tables_ok'] || $codigoUsuario === null || $codigoUsuario === '') {
        $out['degraded_full_access'] = true;
        $out['allowed'] = [];
        return $out;
    }

    $roleIds = sip_acl_load_role_ids($conn, $codigoUsuario);
    $out['role_ids'] = $roleIds['ids'];
    $out['role_codes'] = $roleIds['codes'];

    // ── Sin roles en adm_usuario_rol → sin acceso ──
    // Todo usuario necesita al menos un rol explícito en adm_usuario_rol
    // para visualizar contenido; el menú se controla únicamente por roles.

    $allowed = [];
    foreach ($out['role_ids'] as $rid) {
        $set = sip_acl_load_allowed_for_role($conn, $rid, sip_acl_id_programa_sanidad($conn));
        foreach ($set as $c) {
            $allowed[$c] = true;
        }
    }

    // Sin modulos permitidos: sidebar vacio.
    // No hay bypass: la visibilidad depende solo del alcance del rol.

    if (count($out['role_ids']) === 1) {
        $onlyRid = (int) $out['role_ids'][0];
        foreach (['TRACKING', 'DATALOGGER'] as $codSinVista) {
            $ridLim = sip_acl_id_rol_by_cod($conn, $codSinVista);
            if ($ridLim !== null && $onlyRid === $ridLim) {
                unset($allowed['item-sp-1-1']);
                break;
            }
        }
    }

    $rowsM = sip_acl_load_modulos($conn, sip_acl_id_programa_sanidad($conn));
    if ($rowsM !== []) {
        $allowed = sip_acl_expand_with_parents($allowed, $rowsM);
    }

    $out['allowed'] = $allowed;

    $ipAllow = [];
    $hasRule = false;
    // La sección «Procesos» concede el árbol completo por código: se ignoran los idx_paths
    // guardados (que solo servían para recortar ramas). Así el árbol nunca desaparece por
    // configuración aunque existan filas viejas en adm_rol_proceso_idxpath.
    if (!isset($allowed['grp-sp-2']) && !isset($allowed['item-sp-2-0'])) {
        foreach ($out['role_ids'] as $rid) {
            $r = sip_acl_load_proceso_idx_paths($conn, $rid, sip_acl_id_programa_sanidad($conn));
            if ($r['has']) {
                $hasRule = true;
            }
            foreach ($r['paths'] as $p) {
                $ipAllow[$p] = true;
            }
        }
    }
    $out['idx_path_has_rules'] = $hasRule;
    $out['idx_path_allow'] = $ipAllow;

    $out['ix2_allow'] = [];
    $out['ruta_to_cod'] = [];
    if ($rowsM !== []) {
        sip_acl_apply_ix2_ruta_maps($out, $rowsM);
    }

    return $out;
}

function sip_acl_sanidad_tables_ok(mysqli $conn): bool
{
    $t = ['amd_dashboard_modulos', 'adm_rol', 'adm_rol_progr_modulo', 'amd_programas'];
    foreach ($t as $name) {
        $nameEsc = $conn->real_escape_string($name);
        $r = @$conn->query("SHOW TABLES LIKE '{$nameEsc}'");
        if (!$r || $r->num_rows === 0) {
            return false;
        }
    }
    return true;
}

/**
 * Columnas útiles de amd_programas (introspección; distinto esquema local/producción).
 *
 * @return array{id: string, nombre: string, codigo: ?string, activo: ?string, columns: array<string, array<string, mixed>>}|null
 */
function sip_acl_amd_programas_schema(mysqli $conn): ?array
{
    static $schema = false;
    if ($schema !== false) {
        return is_array($schema) ? $schema : null;
    }
    $schema = null;
    if (!sip_acl_table_exists($conn, 'amd_programas')) {
        return null;
    }
    $r = @$conn->query('SHOW COLUMNS FROM `amd_programas`');
    if (!$r) {
        return null;
    }
    $cols = [];
    while ($row = $r->fetch_assoc()) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $cols[strtolower($field)] = $row;
        }
    }
    $pick = static function (array $cands, array $cols): ?string {
        foreach ($cands as $c) {
            $k = strtolower($c);
            if (isset($cols[$k])) {
                return (string) $cols[$k]['Field'];
            }
        }

        return null;
    };
    $idCol = $pick(['id', 'id_programa'], $cols);
    $nameCol = $pick(['nombre', 'nom_programa', 'programa', 'nom_prog', 'descripcion', 'name'], $cols);
    if ($idCol === null || $nameCol === null) {
        return null;
    }
    $schema = [
        'id' => $idCol,
        'nombre' => $nameCol,
        'codigo' => $pick(['codigo', 'cod_programa', 'cod_prog', 'code'], $cols),
        'activo' => $pick(['activo', 'estado', 'habilitado'], $cols),
        'columns' => $cols,
    ];

    return $schema;
}

/**
 * Valor por defecto al insertar fila en amd_programas según tipo de columna.
 *
 * @param array<string, mixed> $colMeta
 * @return string|int
 */
function sip_acl_amd_programas_default_value(array $colMeta, string $logical)
{
    $type = strtolower((string) ($colMeta['Type'] ?? ''));
    if ($logical === 'activo') {
        if (strpos($type, 'char') !== false || strpos($type, 'enum') === 0) {
            return 'A';
        }

        return 1;
    }

    return $logical === 'codigo' ? SIP_ACL_CODIGO_PROGRAMA_SANIDAD : SIP_ACL_NOMBRE_PROGRAMA_SANIDAD;
}

/**
 * Busca id del programa por nombre exacto (o único resultado parcial «sanidad»).
 *
 * @param array{id: string, nombre: string} $schema
 */
function sip_acl_find_programa_sanidad_id(mysqli $conn, array $schema): ?int
{
    $idCol = $schema['id'];
    $nameCol = $schema['nombre'];
    $nombreUp = strtoupper(trim(SIP_ACL_NOMBRE_PROGRAMA_SANIDAD));
    $idEsc = str_replace('`', '``', $idCol);
    $nameEsc = str_replace('`', '``', $nameCol);

    $sqlExact = 'SELECT `' . $idEsc . '` AS pid FROM `amd_programas`'
        . ' WHERE UPPER(TRIM(`' . $nameEsc . '`)) = ? ORDER BY `' . $idEsc . '` ASC LIMIT 1';
    $st = $conn->prepare($sqlExact);
    if ($st) {
        $st->bind_param('s', $nombreUp);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $st->close();
        if ($row && (int) ($row['pid'] ?? 0) > 0) {
            return (int) $row['pid'];
        }
    }

    $like = '%' . $nombreUp . '%';
    $sqlLike = 'SELECT `' . $idEsc . '` AS pid, `' . $nameEsc . '` AS nom FROM `amd_programas`'
        . ' WHERE UPPER(TRIM(`' . $nameEsc . '`)) LIKE ? ORDER BY `' . $idEsc . '` ASC LIMIT 2';
    $st2 = $conn->prepare($sqlLike);
    if (!$st2) {
        return null;
    }
    $st2->bind_param('s', $like);
    $st2->execute();
    $res2 = $st2->get_result();
    $matches = [];
    while ($res2 && ($r = $res2->fetch_assoc())) {
        $matches[] = $r;
    }
    $st2->close();
    if (count($matches) === 1 && (int) ($matches[0]['pid'] ?? 0) > 0) {
        return (int) $matches[0]['pid'];
    }

    return null;
}

/**
 * Busca el programa Sanidad por nombre; si no existe, lo inserta.
 */
function sip_acl_resolve_or_create_programa_sanidad(mysqli $conn): int
{
    $schema = sip_acl_amd_programas_schema($conn);
    if ($schema === null) {
        throw new RuntimeException('La tabla amd_programas no existe o no tiene columnas id/nombre.');
    }

    $found = sip_acl_find_programa_sanidad_id($conn, $schema);
    if ($found !== null && $found > 0) {
        return $found;
    }

    $nameCol = $schema['nombre'];
    $nombre = SIP_ACL_NOMBRE_PROGRAMA_SANIDAD;

    $fields = [$nameCol];
    $placeholders = ['?'];
    $types = 's';
    $params = [$nombre];

    if ($schema['codigo'] !== null) {
        $fields[] = $schema['codigo'];
        $placeholders[] = '?';
        $types .= 's';
        $params[] = SIP_ACL_CODIGO_PROGRAMA_SANIDAD;
    }
    if ($schema['activo'] !== null) {
        $fields[] = $schema['activo'];
        $placeholders[] = '?';
        $actMeta = $schema['columns'][strtolower($schema['activo'])] ?? [];
        $actVal = sip_acl_amd_programas_default_value(is_array($actMeta) ? $actMeta : [], 'activo');
        if (is_int($actVal)) {
            $types .= 'i';
        } else {
            $types .= 's';
        }
        $params[] = $actVal;
    }

    $sqlIns = 'INSERT INTO `amd_programas` (`' . implode('`, `', $fields) . '`) VALUES (' . implode(', ', $placeholders) . ')';
    $stIns = $conn->prepare($sqlIns);
    if ($stIns) {
        $stIns->bind_param($types, ...$params);
        if ($stIns->execute()) {
            $newId = (int) $conn->insert_id;
            $stIns->close();
            if ($newId > 0) {
                return $newId;
            }
        } else {
            $stIns->close();
        }
    }

    $foundAfter = sip_acl_find_programa_sanidad_id($conn, $schema);
    if ($foundAfter !== null && $foundAfter > 0) {
        return $foundAfter;
    }

    throw new RuntimeException('No se pudo crear el programa Sanidad en amd_programas.');
}

/**
 * Id numérico del programa Sanidad (cache por petición; mismo valor en local y producción).
 */
function sip_acl_id_programa_sanidad(mysqli $conn): int
{
    static $cached = null;
    if (is_int($cached) && $cached > 0) {
        return $cached;
    }
    $id = sip_acl_resolve_or_create_programa_sanidad($conn);
    if ($id <= 0) {
        throw new RuntimeException('Id de programa Sanidad inválido.');
    }
    $cached = $id;

    return $cached;
}

function sip_acl_adm_rol_tiene_id_programa(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `adm_rol` LIKE 'id_programa'");
    $cache = $r && $r->num_rows > 0;

    return $cache;
}

function sip_acl_id_rol_by_cod(mysqli $conn, string $cod, ?int $idPrograma = null): ?int
{
    if ($idPrograma === null) {
        $idPrograma = sip_acl_id_programa_sanidad($conn);
    }
    $c = strtoupper(trim($cod));
    $sql = 'SELECT `id` FROM `adm_rol` WHERE UPPER(TRIM(`cod_rol`)) = ?';
    if (sip_acl_adm_rol_tiene_id_programa($conn)) {
        $sql .= ' AND CAST(`id_programa` AS UNSIGNED) = ?';
    }
    $sql .= ' LIMIT 1';
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    if (sip_acl_adm_rol_tiene_id_programa($conn)) {
        $st->bind_param('si', $c, $idPrograma);
    } else {
        $st->bind_param('s', $c);
    }
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    if (!$row) {
        return null;
    }

    return (int) $row['id'];
}

/**
 * @return array{ids: int[], codes: string[]}
 */
function sip_acl_load_role_ids(mysqli $conn, string $codigo): array
{
    $ids = [];
    $codes = [];
    if (!sip_acl_table_exists($conn, 'adm_usuario_rol')) {
        return ['ids' => [], 'codes' => []];
    }
    $st = $conn->prepare('SELECT DISTINCT `cod_rol` FROM `adm_usuario_rol` WHERE `codigo` = ?');
    if (!$st) {
        return ['ids' => [], 'codes' => []];
    }
    $st->bind_param('s', $codigo);
    $st->execute();
    $res = $st->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $cr = trim((string) ($row['cod_rol'] ?? ''));
        if ($cr === '') {
            continue;
        }
        $codes[] = $cr;
        $id = sip_acl_id_rol_by_cod($conn, $cr);
        if ($id !== null) {
            $ids[] = $id;
        }
    }
    $st->close();
    $ids = array_values(array_unique($ids));
    $codes = array_values(array_unique($codes));
    return ['ids' => $ids, 'codes' => $codes];
}

require_once __DIR__ . '/sip_acl_rol_sistemas.php';

function sip_acl_table_exists(mysqli $conn, string $table): bool
{
    $t = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$t}'");
    return $r && $r->num_rows > 0;
}

/**
 * @return string[]
 */
function sip_acl_load_allowed_for_role(mysqli $conn, int $idRol, int $idPrograma): array
{
    $q = 'SELECT DISTINCT `cod_mod` FROM `adm_rol_progr_modulo`'
        . ' WHERE `id_rol` = ? AND `id_programa` = ?'
        . ' AND `id_rol` <> 0';
    $st = $conn->prepare($q);
    if (!$st) {
        return [];
    }
    $st->bind_param('ii', $idRol, $idPrograma);
    $st->execute();
    $r = $st->get_result();
    $out = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $c = trim((string) ($row['cod_mod'] ?? ''));
        if ($c !== '') {
            $out[] = $c;
        }
    }
    $st->close();
    return $out;
}

/**
 * @return array{paths: string[], has: bool}
 */
function sip_acl_load_proceso_idx_paths(mysqli $conn, int $idRol, int $idPrograma): array
{
    if (!sip_acl_table_exists($conn, 'adm_rol_proceso_idxpath')) {
        return ['paths' => [], 'has' => false];
    }
    $q = 'SELECT `idx_path` FROM `adm_rol_proceso_idxpath` WHERE `id_rol` = ? AND `id_programa` = ?';
    $st = $conn->prepare($q);
    if (!$st) {
        return ['paths' => [], 'has' => false];
    }
    $st->bind_param('ii', $idRol, $idPrograma);
    $st->execute();
    $r = $st->get_result();
    $paths = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $p = trim((string) ($row['idx_path'] ?? ''));
        if ($p !== '') {
            $paths[] = $p;
        }
    }
    $st->close();
    return ['paths' => $paths, 'has' => $paths !== []];
}

/**
 * @return string[]
 */
function sip_acl_select_all_cod_mods(mysqli $conn, int $idPrograma): array
{
    $st = $conn->prepare('SELECT `cod_mod` FROM `amd_dashboard_modulos` WHERE `id_programa` = ?');
    if (!$st) {
        return [];
    }
    $idProgStr = (string) $idPrograma;
    $st->bind_param('s', $idProgStr);
    $st->execute();
    $r = $st->get_result();
    $o = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $o[] = (string) $row['cod_mod'];
    }
    $st->close();
    return $o;
}

/**
 * Carga módulos desde BD. Si falla, devuelve [].
 *
 * @return list<array<string, mixed>>
 */
function sip_acl_load_modulos(mysqli $conn, int $idPrograma): array
{
    $st = $conn->prepare('SELECT * FROM `amd_dashboard_modulos` WHERE `id_programa` = ? ORDER BY `orden` ASC, `id` ASC');
    if (!$st) {
        return [];
    }
    $idProgStr = (string) $idPrograma;
    $st->bind_param('s', $idProgStr);
    $st->execute();
    $r = $st->get_result();
    $rows = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $rows[] = sip_acl_normalize_mod_row($row);
    }
    $st->close();
    return $rows;
}

function sip_acl_is_allowed(array $acl, string $codMod): bool
{
    if (!empty($acl['degraded_full_access'])) {
        return true;
    }
    if (!empty($acl['allowed'][$codMod])) {
        return true;
    }
    return false;
}

/**
 * Añade cod_padre en cadena para cada módulo permitido (muestra grupos vacíos no: solo padres con hijo visible).
 *
 * @param array<string, bool> $allowed
 * @param list<array<string, mixed>> $rows
 * @return array<string, bool>
 */
function sip_acl_expand_with_parents(array $allowed, array $rows): array
{
    $byCod = [];
    foreach ($rows as $row) {
        $byCod[(string) $row['cod_mod']] = $row;
    }
    $out = $allowed;
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach (array_keys($out) as $cod) {
            if (!$out[$cod] || !isset($byCod[$cod])) {
                continue;
            }
            $p = $byCod[$cod]['cod_padre'] ?? $byCod[$cod]['parent_cod'] ?? null;
            if ($p === null || $p === '') {
                continue;
            }
            $p = (string) $p;
            if (empty($out[$p])) {
                $out[$p] = true;
                $changed = true;
            }
        }
    }
    return $out;
}

/**
 * Hijos visibles: item o config_group cuyo cod_padre = padre, recortado por permisos; grupos con anidación.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_acl_children_visible(array $rows, array $acl, ?string $parentCod): array
{
    $parentNorm = $parentCod === null || $parentCod === '' ? null : (string) $parentCod;
    $sidebarCompleto = !empty($acl['degraded_full_access']) || !empty($acl['system_full_access']);
    $out = [];
    foreach ($rows as $row) {
        $pad = $row['cod_padre'] ?? $row['parent_cod'] ?? null;
        $padNorm = $pad === null || $pad === '' ? null : (string) $pad;
        if ($padNorm !== $parentNorm) {
            continue;
        }
        $tipo = (string) $row['tipo'];
        if ($tipo === 'item') {
            if ($sidebarCompleto || sip_acl_item_visible($acl, $row)) {
                $out[] = $row;
            }
            continue;
        }
        if ($tipo === 'config_group' || $tipo === 'cgroup' || $tipo === 'group') {
            $cod = (string) $row['cod_mod'];
            $ch = sip_acl_children_visible($rows, $acl, $cod);
            // El grupo «Procesos» se incluye cuando el rol tiene permiso sobre la sección
            // o sobre el árbol (idx_paths), aunque item-sp-2-0 no esté marcado: el acordeón
            // renderiza el árbol de procesos aparte del catálogo.
            $procesoVisible = $cod === 'grp-sp-2' && sip_acl_can_see_proceso_menu($acl);
            if ($sidebarCompleto || $procesoVisible || $ch !== []) {
                $out[] = array_merge($row, ['_children' => $ch]);
            }
        }
    }
    return $out;
}

function sip_acl_item_visible(array $acl, array $row): bool
{
    $cod = (string) $row['cod_mod'];
    if (sip_acl_is_allowed($acl, $cod)) {
        return true;
    }
    $hk = sip_acl_row_ix2_key($row);
    if ($hk !== '' && !empty($acl['ix2_allow'][$hk])) {
        return true;
    }
    return false;
}

/**
 * Primer módulo a abrir en index.php: «Vista general» si hay permiso; si no, el primer ítem del catálogo
 * con URL y permiso (p. ej. rol TRACKING → Tracking envíos) para evitar 403 al cargar el iframe.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{url: string, title: string}|null
 */
function sip_acl_default_index_start(array $acl, array $rows): ?array
{
    if (!empty($acl['degraded_full_access'])) {
        return ['url' => 'modules/sip/dashboard-general.php', 'title' => 'Vista general'];
    }
    if (sip_acl_is_allowed($acl, 'item-sp-1-1')) {
        return ['url' => 'modules/sip/dashboard-general.php', 'title' => 'Vista general'];
    }
    $toSort = $rows;
    usort(
        $toSort,
        static function (array $a, array $b): int {
            return ((int) ($a['orden'] ?? 0)) <=> ((int) ($b['orden'] ?? 0));
        }
    );
    foreach ($toSort as $row) {
        if ((string) ($row['tipo'] ?? '') !== 'item') {
            continue;
        }
        if (!sip_acl_item_visible($acl, $row)) {
            continue;
        }
        $ruta = trim((string) ($row['ruta'] ?? $row['url'] ?? ''));
        if ($ruta === '') {
            continue;
        }
        if ((string) ($row['cod_mod'] ?? '') === 'item-sp-2-0') {
            continue;
        }
        $title = sip_acl_mod_label($row);
        if ($title === '') {
            $title = 'Módulo';
        }
        return ['url' => $ruta, 'title' => $title];
    }
    return null;
}

/**
 * Rutas normalizadas de ítems del catálogo (con o sin permiso).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, true>
 */
function sip_acl_catalog_module_rutas(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $ruta = trim((string) ($row['ruta'] ?? $row['url'] ?? ''));
        if ($ruta === '') {
            continue;
        }
        $out[sip_acl_norm_path($ruta)] = true;
    }

    return $out;
}

/**
 * Rutas normalizadas que el usuario puede abrir en iframe (según menú + ACL).
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, true>
 */
function sip_acl_allowed_module_rutas(array $acl, array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if ((string) ($row['tipo'] ?? '') !== 'item') {
            continue;
        }
        if (!sip_acl_item_visible($acl, $row)) {
            continue;
        }
        $ruta = trim((string) ($row['ruta'] ?? $row['url'] ?? ''));
        if ($ruta === '') {
            continue;
        }
        $out[sip_acl_norm_path($ruta)] = true;
    }

    return $out;
}

/**
 * true si la ruta puede abrirse: permitida en catálogo, o no catalogada (enlaces programáticos).
 *
 * @param list<array<string, mixed>> $rows filas del catálogo (p. ej. sip_menu_catalog_load_rows)
 */
function sip_acl_user_can_open_ruta(array $acl, array $rows, string $rutaRel): bool
{
    if (!empty($acl['degraded_full_access'])) {
        return true;
    }
    $n = sip_acl_norm_path($rutaRel);
    if ($n === '') {
        return false;
    }
    $allowed = sip_acl_allowed_module_rutas($acl, $rows);
    if (!empty($allowed[$n])) {
        return true;
    }
    $catalog = sip_acl_catalog_module_rutas($rows);
    if (empty($catalog[$n])) {
        return true;
    }

    return false;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{ix2_allow: array<string,bool>, ruta_to_cod: array<string,string>}
 */
function sip_acl_build_maps(array $rows, array $acl): array
{
    $ix2 = [];
    $ruta = [];
    foreach ($rows as $row) {
        $cod = (string) $row['cod_mod'];
        if (!sip_acl_is_allowed($acl, $cod)) {
            continue;
        }
        $k = sip_acl_row_ix2_key($row);
        if ($k !== '') {
            $ix2[$k] = true;
        }
        $p = trim((string) ($row['ruta'] ?? $row['url'] ?? ''));
        if ($p !== '') {
            $ruta[sip_acl_norm_path($p)] = $cod;
        }
    }
    return ['ix2_allow' => $ix2, 'ruta_to_cod' => $ruta];
}

function sip_acl_norm_path(string $p): string
{
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('#^\./#', '', $p) ?? $p;
    return strtolower($p);
}

/**
 * Aplica mapas a acl (ix2 allow para hub).
 *
 * @param list<array<string, mixed>> $rows
 */
function sip_acl_apply_ix2_ruta_maps(array &$acl, array $rows): void
{
    $m = sip_acl_build_maps($rows, $acl);
    $acl['ix2_allow'] = $m['ix2_allow'];
    $acl['ruta_to_cod'] = $m['ruta_to_cod'];
}

/**
 * @return array<string, bool>
 */
function sip_acl_fallback_all_cod_mods(): array
{
    $a = [
        'grp-sp-1', 'item-sp-1-1', 'item-sp-1-2', 'item-sp-1-3', 'item-sp-1-4', 'item-sp-1-5',
        'item-sp-necro-q', 'grp-sp-necro-sub', 'item-sp-necro-reg', 'item-sp-necro-lst', 'item-sp-necro-cmp',
        'grp-sp-plan-q', 'grp-sp-dl', 'item-sp-dl-1', 'item-sp-dl-2', 'item-sp-evf-quick',
        'grp-sp-2', 'item-sp-2-0', 'grp-sp-3', 'grp-sp-3-trk', 'item-sp-3-1', 'item-sp-3-2', 'item-sp-3-3', 'item-sp-3-4', 'item-sp-3-5', 'item-sp-3-manual',
        'item-sp-3-trk-1', 'item-sp-3-trk-2', 'item-sp-3-trk-3',
        'grp-sp-4', 'grp-sp-4-hc', 'grp-sp-4-mort', 'item-sp-4-1', 'item-sp-4-2', 'item-sp-4-3', 'item-sp-4-4', 'item-sp-4-5', 'item-sp-4-8', 'item-sp-4-9',
        'item-sp-4-mort', 'item-sp-4-mort-hist', 'item-sp-4-mort-aud', 'item-sp-4-mort-inf-ctb',
        'item-sp-4-manual',
        'grp-sp-5', 'cfg-grp-ml', 'cfg-grp-pl', 'cfg-grp-adm',
        'item-cfg-ml-1', 'item-cfg-ml-2', 'item-cfg-ml-3', 'item-cfg-ml-4', 'item-cfg-ml-5', 'item-cfg-ml-6', 'item-cfg-ml-7', 'item-cfg-ml-8',
        'item-cfg-pl-1', 'item-cfg-pl-2', 'item-cfg-pl-3', 'item-cfg-pl-4',
        'item-cfg-adm-1', 'item-cfg-adm-2', 'item-cfg-adm-3', 'item-cfg-adm-4', 'item-cfg-adm-5', 'item-cfg-adm-6', 'item-cfg-adm-7', 'item-cfg-adm-8',
        'hub-plan_prog_reg', 'hub-plan_prog_lst', 'hub-plan_asig_reg', 'hub-plan_asig_evt', 'hub-plan_asig_lst', 'hub-plan_manual',
    ];
    $o = [];
    foreach ($a as $c) {
        $o[$c] = true;
    }
    return $o;
}

/**
 * Fallback para usuarios sin rol asignado explícitamente (adm_usuario_rol vacío).
 * Otorga acceso completo excepto el bloque Administración en Configuración.
 *
 * @return array<string, bool>
 */
function sip_acl_fallback_no_roles_cod_mods(): array
{
    $a = sip_acl_fallback_all_cod_mods();
    $excluir = [
        'cfg-grp-adm',
        'item-cfg-adm-1', 'item-cfg-adm-2', 'item-cfg-adm-3',
        'item-cfg-adm-4', 'item-cfg-adm-5', 'item-cfg-adm-6', 'item-cfg-adm-7', 'item-cfg-adm-8',
    ];
    foreach ($excluir as $c) {
        unset($a[$c]);
    }
    return $a;
}

/**
 * @return array<string, bool>
 */
function sip_acl_fallback_consulta_cod_mods(): array
{
    $a = [
        'grp-sp-1', 'item-sp-1-1', 'item-sp-1-2', 'item-sp-1-3', 'item-sp-1-4', 'item-sp-1-5',
        'grp-sp-2', 'item-sp-2-0', 'grp-sp-3', 'item-sp-3-2', 'item-sp-3-4', 'item-sp-3-5', 'item-sp-3-manual', 'grp-sp-3-trk',
        'item-sp-3-trk-1', 'item-sp-3-trk-2', 'item-sp-3-trk-3',
        'grp-sp-4', 'grp-sp-4-hc', 'grp-sp-4-mort', 'item-sp-4-1', 'item-sp-4-2', 'item-sp-4-3', 'item-sp-4-4', 'item-sp-4-5', 'item-sp-4-8', 'item-sp-4-9',
        'item-sp-4-mort', 'item-sp-4-mort-hist', 'item-sp-4-mort-aud', 'item-sp-4-mort-inf-ctb',
        'item-sp-4-manual',
        'hub-plan_prog_reg', 'hub-plan_prog_lst', 'hub-plan_asig_reg', 'hub-plan_asig_evt', 'hub-plan_asig_lst', 'hub-plan_manual',
        'item-sp-necro-q',
    ];
    $o = [];
    foreach ($a as $c) {
        $o[$c] = true;
    }
    return $o;
}

/**
 * Filtra árbol de proceso recursivo (hojas por idxPath "0.1.2"). Si no hay reglas, no modifica
 * (se asume desbloqueado si módulo procesos). Si has_rules y allow vacío, devuelve sin hojas.
 *
 * @return array|array{name: string, children: array}
 */
function sip_acl_filter_proceso_arbol(array $acl, $arbol)
{
    if (empty($acl['idx_path_has_rules'])) {
        return $arbol;
    }
    $allow = $acl['idx_path_allow'] ?? [];
    if ($allow === []) {
        if (isset($arbol['children']) && is_array($arbol['children'])) {
            $arbol['children'] = [];
        }
        return $arbol;
    }
    if (!isset($arbol['name'])) {
        return $arbol;
    }
    return sip_acl_filter_proceso_node($arbol, $allow, '');
}

/**
 * @param array<string, bool> $allow
 */
function sip_acl_filter_proceso_node(array $node, array $allow, string $pathPrefix): array
{
    $kids = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
    if ($kids === []) {
        $leafPath = $pathPrefix === '' ? '0' : $pathPrefix;
        if (sip_acl_idx_path_permitida($allow, $leafPath)) {
            return $node;
        }
        return array_merge($node, ['_sip_acl_pruned' => true]);
    }
    $nKids = [];
    $i = 0;
    foreach ($kids as $ch) {
        if (!is_array($ch)) {
            continue;
        }
        $sub = (string) $pathPrefix === '' ? (string) $i : $pathPrefix . '.' . $i;
        $fil = sip_acl_filter_proceso_node($ch, $allow, $sub);
        if (empty($fil['_sip_acl_pruned'])) {
            $nKids[] = $fil;
        }
        $i++;
    }
    $node['children'] = $nKids;
    return $node;
}

/**
 * @param array<string, bool> $allow
 */
function sip_acl_idx_path_permitida(array $allow, string $path): bool
{
    if (isset($allow['*']) || isset($allow['**'])) {
        return true;
    }
    if (isset($allow[$path])) {
        return true;
    }
    $parts = explode('.', $path);
    $acc = '';
    foreach ($parts as $i => $p) {
        $acc = $i === 0 ? $p : $acc . '.' . $p;
        if (isset($allow[$acc . '/*']) || isset($allow[$acc . '/*'])) {
            return true;
        }
    }
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        $pref = '';
        for ($j = 0; $j <= $i; $j++) {
            $pref = $j === 0 ? $parts[$j] : $pref . '.' . $parts[$j];
        }
        if (isset($allow[$pref])) {
            return true;
        }
    }
    return false;
}

/**
 * true si se puede mostrar al menos una ruta bajo módulo procesos.
 */
function sip_acl_can_see_proceso_menu(array $acl): bool
{
    if (sip_acl_is_allowed($acl, 'grp-sp-2') || sip_acl_is_allowed($acl, 'item-sp-2-0')) {
        return true;
    }
    if (!empty($acl['idx_path_has_rules'])) {
        return true;
    }
    return false;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function sip_acl_find_cod_by_ruta(array $rows, string $rutaRel): ?string
{
    $n = sip_acl_norm_path($rutaRel);
    foreach ($rows as $row) {
        $p = trim((string) ($row['ruta'] ?? $row['url'] ?? ''));
        if ($p === '') {
            continue;
        }
        if (sip_acl_norm_path($p) === $n) {
            return (string) $row['cod_mod'];
        }
    }
    return null;
}

/**
 * Comprueba ruta p.ej. modules/sip/dashboard-general.php. Si no hay módulo en catálogo, se permite.
 */
function sip_acl_user_can_ruta(mysqli $conn, array $acl, string $rutaRel): bool
{
    if (!empty($acl['degraded_full_access'])) {
        return true;
    }
    $idProg = sip_acl_id_programa_sanidad($conn);
    if (function_exists('sip_menu_catalog_load_rows')) {
        $rows = sip_menu_catalog_load_rows($conn, $idProg);
    } else {
        $rows = sip_acl_load_modulos($conn, $idProg);
    }
    if ($rows === []) {
        return true;
    }

    return sip_acl_user_can_open_ruta($acl, $rows, $rutaRel);
}

/**
 * Uso: tras session_start y conexión, bloquea con 403 si el usuario no puede ver el módulo.
 */
function sip_acl_guard_module(mysqli $conn, string $rutaRelativaDesdeRaizProyecto): void
{
    if (empty($_SESSION['active']) || empty($_SESSION['usuario'])) {
        return;
    }
    $u = (string) $_SESSION['usuario'];
    $acl = sip_acl_init($conn, $u);
    sip_acl_inject_ix2_allow_historia_clinica($acl);
    if (!sip_acl_user_can_ruta($conn, $acl, $rutaRelativaDesdeRaizProyecto)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acceso denegado a este módulo.';
        exit;
    }
}

/**
 * Filtra grupos de configuración (estructura index: [título => [[label, ruta, icon],...]]).
 *
 * @param array<string, list<array{0: string, 1: string, 2: string}>> $groups
 * @return array<string, list<array{0: string, 1: string, 2: string}>>
 */
function sip_acl_filter_config_groups(
    mysqli $conn,
    array $acl,
    array $groups
): array {
    $rows = sip_acl_load_modulos($conn, sip_acl_id_programa_sanidad($conn));
    if ($rows === [] || !empty($acl['degraded_full_access'])) {
        return $groups;
    }
    $out = [];
    foreach ($groups as $title => $links) {
        $nl = [];
        foreach ($links as $l) {
            if (!is_array($l) || !isset($l[1])) {
                continue;
            }
            $cod = sip_acl_find_cod_by_ruta($rows, (string) $l[1]);
            if ($cod === null) {
                // La URL no esta en el catalogo de BD -> el enlace fue agregado
                // por logica programatica (booleanos en index.php), no por permiso directo.
                // Se conserva para no eliminar enlaces de grupos como Administracion
                // que no fueron sincronizados en la BD.
                $nl[] = $l;
                continue;
            }
            if (sip_acl_is_allowed($acl, $cod)) {
                $nl[] = $l;
            }
        }
        if ($nl !== []) {
            $out[$title] = $nl;
        }
    }
    return $out;
}

/**
 * Uso al inicio de un dashboard: deniega con HTTP 403 si no hay permiso.
 */
function sip_acl_require_ruta(mysqli $conn, string $rutaDesdeModulo): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $u = $_SESSION['usuario'] ?? null;
    if ($u === null) {
        http_response_code(401);
        echo 'No autenticado';
        exit;
    }
    $includeRoot = defined('SIP_ACL_DIR') ? SIP_ACL_DIR : dirname(dirname(__DIR__));
    if (!function_exists('sip_acl_init')) {
        require_once $includeRoot . '/core/lib/sip_acl_sanidad.php';
    }
    if (!function_exists('conectar_joya_mysqli')) {
        $cf = $includeRoot . '/../conexion_grs/conexion.php';
        if (is_file($cf)) {
            include_once $cf;
        }
    }
    $c = conectar_joya_mysqli();
    if (!$c) {
        http_response_code(500);
        echo 'Conexión no disponible';
        exit;
    }
    $acl = sip_acl_init($c, (string) $u);
    sip_acl_inject_ix2_allow_historia_clinica($acl);
    if (!sip_acl_user_can_ruta($c, $acl, $rutaDesdeModulo)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acceso denegado a este módulo.';
        exit;
    }
}
