<?php

declare(strict_types=1);

/**
 * Catálogo del menú lateral (amd_dashboard_modulos) y grupos Config de index.
 */

require_once __DIR__ . '/sip_acl_sanidad.php';

/**
 * Módulos retirados del menú (no se muestran ni se sincronizan).
 *
 * @return list<string>
 */
function sip_menu_catalog_retired_cod_mods(): array
{
    return [
        'cfg-grp-dp',
        'item-cfg-dp-1',
        'item-sp-agc-quick',
        'grp-mort',
        'item-mort-1',
        'item-sp-4-7',
        'item-sp-4-mort-manual',
        'item-sp-4-hc-manual',
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_filter_retired_rows(array $rows): array
{
    $retired = array_flip(sip_menu_catalog_retired_cod_mods());

    return array_values(array_filter(
        $rows,
        static function (array $row) use ($retired): bool {
            return !isset($retired[(string) ($row['cod_mod'] ?? '')]);
        }
    ));
}

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function sip_menu_catalog_links_administracion_publicos(): array
{
    return [
        ['Tareas y Parámetros', 'modules/configuracion/tareasParametros/dashboard-tareas-parametros.php', 'fa-list-check'],
        ['Estándares de Procesos', 'modules/configuracion/estandares_nuevo/dashboard-estandares2.php', 'fa-sitemap'],
        ['Notificaciones Whatsapp', 'modules/configuracion/notificaciones_whatsapp/dashboard-notificaciones-whatsapp.php', 'fa-mobile-alt'],
    ];
}

/**
 * Grupos de Configuración (misma estructura que index.php).
 *
 * @return array<string, list<array{0: string, 1: string, 2: string}>>
 */
function sip_menu_catalog_ix2_config_groups(
    bool $puedeNotifUsuarios,
    bool $puedeDestinatarios,
    bool $puedeAsignacionZonas,
    bool $puedeRolesPermisos = false,
    bool $puedeMortalidadAdmin = false
): array
{
    $linksAdminPublicos = sip_menu_catalog_links_administracion_publicos();
    $linksAdminSoloAdmin = [];
    if ($puedeNotifUsuarios) {
        $linksAdminSoloAdmin[] = ['Notificación usuarios', 'modules/configuracion/notificaciones_usuarios/dashboard-notificaciones-usuarios.php', 'fa-users'];
    }
    if ($puedeDestinatarios) {
        $linksAdminSoloAdmin[] = ['Destinatarios alertas gatilladores', 'modules/configuracion/gt_alertas_destinatarios/dashboard-gt-alertas-destinatarios.php', 'fa-bell'];
    }
    $linkRoles = ['Roles de Usuario', 'modules/configuracion/roles_permisos/dashboard-roles-permisos.php', 'fa-shield-alt'];
    $linkMortalidad = ['Mortalidad', 'modules/configuracion/mortalidad_admin/dashboard-mortalidad-admin.php', 'fa-dove'];
    $linkZonas = ['Asignación de Zonas', 'modules/configuracion/mis_zonas/dashboard-mis-zonas.php', 'fa-map'];

    $adminExtras = $linksAdminSoloAdmin;
    if ($puedeMortalidadAdmin) {
        $adminExtras[] = $linkMortalidad;
    }
    if ($puedeRolesPermisos) {
        $adminExtras[] = $linkRoles;
    }
    if ($puedeAsignacionZonas) {
        $adminExtras[] = $linkZonas;
    }

    $groups = [
        'Mi cuenta' => [
            ['Cambiar contraseña', 'modules/configuracion/mi_cuenta/dashboard-mi-cuenta.php', 'fa-key'],
        ],
        'Muestras y Laboratorio' => [
            ['Laboratorios', 'modules/configuracion/laboratorio/dashboard-laboratorio.php', 'fa-flask'],
            ['Tipos muestra', 'modules/configuracion/tipo_muestra/dashboard-tipo-muestra.php', 'fa-vial'],
            ['Tipos análisis', 'modules/configuracion/tipo_analisis/dashboard-analisis.php', 'fa-microscope'],
            ['Paquetes', 'modules/configuracion/paquete_analisis/dashboard-paquete-analisis.php', 'fa-box'],
            ['Tipos respuesta', 'modules/configuracion/tipo_respuesta/dashboard-respuesta.php', 'fa-reply'],
            ['Emp. transporte', 'modules/configuracion/empTransporte/dashboard-empresas-transporte.php', 'fa-truck'],
            ['Correo', 'modules/configuracion/correo_contacto/dashboard-correo-contactos.php', 'fa-envelope'],
            ['Procedencia', 'modules/configuracion/origen_aves/dashboard-origen-aves.php', 'fa-map-marker-alt'],
        ],
        'Planificación' => [
            ['Tipos programa', 'modules/configuracion/tipoPrograma/dashboard-tipo-programa.php', 'fa-list'],
            ['Proveedor', 'modules/configuracion/proveedor/dashboard-proveedor.php', 'fa-handshake'],
            ['Productos', 'modules/configuracion/productos/dashboard-productos.php', 'fa-cubes'],
            ['Enfermedades', 'modules/configuracion/enfermedades/dashboard-enfermedades.php', 'fa-notes-medical'],
        ],
        'Administración' => $linksAdminPublicos,
        'Ayuda' => [
            ['Manual de configuración', 'modules/configuracion/manual/manual-configuracion.php', 'fa-book-open'],
        ],
    ];

    if ($adminExtras !== []) {
        $groups['Administración'] = array_merge($linksAdminPublicos, $adminExtras);
    }

    return $groups;
}

/**
 * Añade enlace de zonas si ACL filtró y el usuario puede verlo.
 *
 * @param array<string, list<array{0: string, 1: string, 2: string}>> $groups
 * @return array<string, list<array{0: string, 1: string, 2: string}>>
 */
function sip_menu_catalog_patch_zonas_link(array $groups, bool $puedeAsignacionZonas): array
{
    if (!$puedeAsignacionZonas) {
        return $groups;
    }
    $linkZonas = ['Asignación de Zonas', 'modules/configuracion/mis_zonas/dashboard-mis-zonas.php', 'fa-map'];
    $adminLinks = $groups['Administración'] ?? [];
    foreach ($adminLinks as $ln) {
        if (is_array($ln) && !empty($ln[1]) && strpos((string) $ln[1], 'mis_zonas') !== false) {
            return $groups;
        }
    }
    if (!isset($groups['Administración'])) {
        $groups['Administración'] = [];
    }
    $groups['Administración'][] = $linkZonas;

    return $groups;
}

/**
 * Restaura enlace Roles de Usuario si el ACL lo quitó (usuario configurador codigo USER).
 *
 * @param array<string, list<array{0: string, 1: string, 2: string}>> $groups
 * @return array<string, list<array{0: string, 1: string, 2: string}>>
 */
function sip_menu_catalog_patch_roles_link(array $groups, bool $puedeRolesPermisos): array
{
    if (!$puedeRolesPermisos) {
        return $groups;
    }
    $linkRoles = ['Roles de Usuario', 'modules/configuracion/roles_permisos/dashboard-roles-permisos.php', 'fa-shield-alt'];
    $adminLinks = $groups['Administración'] ?? [];
    foreach ($adminLinks as $ln) {
        if (is_array($ln) && !empty($ln[1]) && strpos((string) $ln[1], 'roles_permisos') !== false) {
            return $groups;
        }
    }
    if (!isset($groups['Administración']) || $groups['Administración'] === []) {
        $groups['Administración'] = sip_menu_catalog_links_administracion_publicos();
    }
    $groups['Administración'][] = $linkRoles;

    return $groups;
}

/**
 * Restaura enlace Mortalidad (admin app móvil) si el ACL lo quitó.
 *
 * @param array<string, list<array{0: string, 1: string, 2: string}>> $groups
 * @return array<string, list<array{0: string, 1: string, 2: string}>>
 */
function sip_menu_catalog_patch_mortalidad_admin_link(array $groups, bool $puedeMortalidadAdmin): array
{
    if (!$puedeMortalidadAdmin) {
        return $groups;
    }
    $link = ['Mortalidad', 'modules/configuracion/mortalidad_admin/dashboard-mortalidad-admin.php', 'fa-dove'];
    $adminLinks = $groups['Administración'] ?? [];
    foreach ($adminLinks as $ln) {
        if (is_array($ln) && !empty($ln[1]) && strpos((string) $ln[1], 'mortalidad_admin') !== false) {
            return $groups;
        }
    }
    if (!isset($groups['Administración']) || $groups['Administración'] === []) {
        $groups['Administración'] = sip_menu_catalog_links_administracion_publicos();
    }
    $groups['Administración'][] = $link;

    return $groups;
}

/**
 * Filas del menú: solo BD si ya hay catálogo; fallback canónico solo con tabla vacía.
 *
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_load_rows(mysqli $conn, int $idPrograma): array
{
    if (sip_menu_catalog_count_programa_rows($conn, $idPrograma) > 0) {
        return sip_menu_catalog_load_rows_db($conn, $idPrograma);
    }

    return sip_menu_catalog_load_rows_with_fallback($conn, $idPrograma);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function sip_menu_catalog_row_label(array $row): string
{
    return sip_acl_mod_label($row);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_children_of(array $rows, ?string $parentCod): array
{
    $parentNorm = $parentCod === null || $parentCod === '' ? null : (string) $parentCod;
    $out = [];
    foreach ($rows as $row) {
        $pad = $row['cod_padre'] ?? $row['parent_cod'] ?? null;
        $padNorm = $pad === null || $pad === '' ? null : (string) $pad;
        if ($padNorm !== $parentNorm) {
            continue;
        }
        $out[] = $row;
    }
    usort(
        $out,
        static function (array $a, array $b): int {
            return ((int) ($a['orden'] ?? 0)) <=> ((int) ($b['orden'] ?? 0));
        }
    );

    return $out;
}

/**
 * @param array<string, mixed> $row
 */
function sip_menu_catalog_row_to_mod_node(array $row): array
{
    $cod = (string) ($row['cod_mod'] ?? '');
    $tipo = (string) ($row['tipo'] ?? '');
    $ruta = trim((string) ($row['ruta'] ?? $row['url'] ?? ''));

    return [
        'id' => 'mod:' . $cod,
        'kind' => $tipo === 'item' ? 'item' : 'group',
        'cod_mod' => $cod,
        'idx_path' => null,
        'label' => sip_menu_catalog_row_label($row),
        'icon' => sip_acl_mod_icono($row),
        'url' => $ruta !== '' ? $ruta : null,
        'tipo' => $tipo,
        'orden' => (int) ($row['orden'] ?? 0),
        'parent_cod' => $row['cod_padre'] ?? $row['parent_cod'] ?? null,
        'checkable' => $cod !== '',
        'children' => [],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_proceso_children(?array $node, string $pathPrefix): array
{
    if (!is_array($node)) {
        return [];
    }
    $children = $node['children'] ?? null;
    if (!is_array($children) || $children === []) {
        return [];
    }
    $out = [];
    foreach ($children as $idx => $ch) {
        if (!is_array($ch)) {
            continue;
        }
        $path = $pathPrefix === '' ? (string) $idx : $pathPrefix . '.' . $idx;
        $sub = $ch['children'] ?? null;
        $hasKids = is_array($sub) && $sub !== [];
        $out[] = [
            'id' => 'idx:' . $path,
            'kind' => 'idx_path',
            'cod_mod' => null,
            'idx_path' => $path,
            'label' => trim((string) ($ch['name'] ?? 'Sin nombre')),
            'icon' => $hasKids ? 'fa-folder' : 'fa-file',
            'url' => null,
            'tipo' => 'idx_path',
            'orden' => (int) $idx,
            'parent_cod' => null,
            'checkable' => true,
            'children' => $hasKids ? sip_menu_catalog_proceso_children($ch, $path) : [],
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function sip_menu_catalog_load_proceso_arbol(mysqli $conn): ?array
{
    if (!function_exists('sip_est2_repo_table_exists')) {
        require_once __DIR__ . '/sip_estandares_tree_repository.php';
    }
    if (!sip_est2_repo_table_exists($conn, 'san_estandares_nodos')) {
        return null;
    }
    $rootId = sip_est2_repo_active_root_id($conn);
    if ($rootId === null || $rootId <= 0) {
        return null;
    }
    $ctx = sip_est2_repo_load_context($conn, $rootId);
    $arbol = $ctx['arbol'] ?? null;

    return is_array($arbol) && $arbol !== [] ? $arbol : null;
}

/**
 * Árbol de alcance (menú lateral + procesos bajo grp-sp-2), sin filtrar por ACL.
 *
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_build_scope_tree(
    mysqli $conn,
    int $idPrograma
): array {
    $rows = sip_menu_catalog_load_rows($conn, $idPrograma);
    $procesoArbol = sip_menu_catalog_load_proceso_arbol($conn);

    $top = sip_menu_catalog_children_of($rows, null);
    $tree = [];
    foreach ($top as $row) {
        $tipo = (string) ($row['tipo'] ?? '');
        $cod = (string) ($row['cod_mod'] ?? '');

        // Acceso rápido (item-sp-necro-q) es item raíz con hijos; debe aparecer en alcance.
        if ($tipo === 'item') {
            if (sip_menu_catalog_children_of($rows, $cod) === []) {
                continue;
            }
            $node = sip_menu_catalog_row_to_mod_node($row);
            $node['children'] = sip_menu_catalog_build_scope_children(
                $conn,
                $rows,
                $cod,
                $procesoArbol
            );
            $tree[] = $node;
            continue;
        }

        if ($tipo !== 'group' && $tipo !== 'config_group' && $tipo !== 'cgroup') {
            continue;
        }
        $node = sip_menu_catalog_row_to_mod_node($row);
        $node['children'] = sip_menu_catalog_build_scope_children(
            $conn,
            $rows,
            $cod,
            $procesoArbol
        );
        $tree[] = $node;
    }

    return $tree;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_build_scope_children(
    mysqli $conn,
    array $rows,
    string $parentCod,
    ?array $procesoArbol
): array {
    if ($parentCod === 'grp-sp-2') {
        // El árbol de procesos es referencial y se concede completo con la sección:
        // en el alcance solo aparece el checkbox de «Procesos», sin nodos del árbol.
        return [];
    }

    $kids = sip_menu_catalog_children_of($rows, $parentCod);
    $out = [];
    foreach ($kids as $row) {
        $tipo = (string) ($row['tipo'] ?? '');
        if ($tipo === 'item') {
            $out[] = sip_menu_catalog_row_to_mod_node($row);
            continue;
        }
        if (in_array($tipo, ['group', 'config_group', 'cgroup'], true)) {
            $node = sip_menu_catalog_row_to_mod_node($row);
            $cod = (string) ($row['cod_mod'] ?? '');
            $node['children'] = sip_menu_catalog_build_scope_children(
                $conn,
                $rows,
                $cod,
                $procesoArbol
            );
            $out[] = $node;
        }
    }

    return $out;
}

/**
 * Solo filas persistidas en amd_dashboard_modulos (sin fallback en memoria).
 *
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_load_rows_db(mysqli $conn, int $idPrograma): array
{
    $rows = sip_acl_load_modulos($conn, $idPrograma);
    if ($rows === []) {
        return [];
    }

    $rows = sip_menu_catalog_apply_canonical_row_patches($rows);

    return sip_menu_catalog_normalize_rows(sip_menu_catalog_filter_retired_rows($rows));
}

/**
 * Alinea filas de BD con parent/url del catálogo canónico (p. ej. Mortalidad bajo Reportes).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_apply_canonical_row_patches(array $rows): array
{
    $canonicalByCod = [];
    $retired = array_flip(sip_menu_catalog_retired_cod_mods());
    foreach (sip_menu_catalog_canonical_definition() as $def) {
        $canonicalByCod[(string) ($def['cod_mod'] ?? '')] = $def;
    }

    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cod = (string) ($row['cod_mod'] ?? '');
        if ($cod !== '') {
            $seen[$cod] = true;
        }
        if ($cod === '' || !isset($canonicalByCod[$cod])) {
            $out[] = $row;
            continue;
        }
        $def = $canonicalByCod[$cod];
        if (strncmp($cod, 'hub-plan_', 9) === 0
            || in_array($cod, ['grp-sp-dl', 'grp-sp-plan-q', 'grp-sp-necro-sub', 'item-sp-evf-quick'], true)
            || in_array($cod, ['grp-sp-2', 'item-sp-2-0', 'grp-sp-4-hc', 'grp-sp-4-mort', 'item-cfg-adm-7', 'item-sp-4-5', 'item-sp-4-8', 'item-sp-4-9', 'item-sp-dl-1', 'item-sp-dl-2', 'item-sp-4-mort', 'item-sp-4-mort-aud', 'item-sp-4-mort-hist', 'item-sp-4-mort-inf-ctb', 'item-sp-4-manual'], true)) {
            $row['parent_cod'] = $def['parent_cod'];
            $row['cod_padre'] = $def['parent_cod'];
        }
        if (in_array($cod, ['grp-sp-2', 'item-sp-2-0', 'grp-sp-4-hc', 'item-sp-4-8', 'item-sp-4-9'], true)
            || in_array($cod, ['item-sp-dl-1', 'item-sp-dl-2'], true)
            || in_array($cod, ['grp-sp-4-mort', 'item-sp-4-mort', 'item-sp-4-mort-aud', 'item-sp-4-mort-hist', 'item-sp-4-mort-inf-ctb'], true)) {
            $row['tipo'] = $def['tipo'];
            $row['nom_mod'] = $def['nom_mod'];
            $row['label_short'] = $def['label_short'];
            $row['titulo'] = $def['nom_mod'];
            $row['nom_menu'] = $def['label_short'] !== '' ? $def['label_short'] : $def['nom_mod'];
            $row['url'] = $def['url'];
            $row['ruta'] = $def['url'];
            $row['icono'] = $def['icono'];
        }
        if ($cod === 'item-sp-evf-quick') {
            $row['icono'] = $def['icono'];
            $row['nom_mod'] = $def['nom_mod'];
            $row['label_short'] = $def['label_short'];
            $row['url'] = $def['url'];
            $row['ruta'] = $def['url'];
        }
        $out[] = $row;
    }

    // Añadir items canónicos que falten en BD (p. ej. Mortalidad en producciones existentes)
    foreach (sip_menu_catalog_canonical_definition() as $def) {
        $cod = (string) ($def['cod_mod'] ?? '');
        if ($cod === '' || isset($seen[$cod]) || isset($retired[$cod])) {
            continue;
        }
        $out[] = [
            'cod_mod' => $cod,
            'tipo' => $def['tipo'],
            'nom_mod' => $def['nom_mod'],
            'label_short' => $def['label_short'],
            'titulo' => $def['nom_mod'],
            'nom_menu' => $def['label_short'] !== '' ? $def['label_short'] : $def['nom_mod'],
            'url' => $def['url'],
            'ruta' => $def['url'],
            'icono' => $def['icono'],
            'tipo_param' => $def['tipo_param'] ?? '',
            'parent_cod' => $def['parent_cod'],
            'cod_padre' => $def['parent_cod'],
            'orden' => $def['orden'],
        ];
    }

    return $out;
}

/**
 * Estado del catálogo en BD vs definición canónica de index.php.
 *
 * @return array{db_count: int, canonical_total: int, needs_seed: bool}
 */
function sip_menu_catalog_catalog_status(mysqli $conn, int $idPrograma): array
{
    $dbCount = sip_menu_catalog_count_programa_rows($conn, $idPrograma);

    return [
        'db_count' => $dbCount,
        'canonical_total' => count(sip_menu_catalog_canonical_definition()),
        'needs_seed' => $dbCount === 0,
    ];
}

/**
 * Primera carga: inserta el menú de index.php si la tabla está vacía para el programa.
 *
 * @return array{success: bool, skipped?: bool, inserted?: int, updated?: int, total?: int, message?: string}
 */
function sip_menu_catalog_ensure_initial_seed(mysqli $conn, ?int $idPrograma = null): array
{
    $idPrograma = $idPrograma ?? sip_acl_id_programa_sanidad($conn);
    if (sip_menu_catalog_count_programa_rows($conn, $idPrograma) > 0) {
        return [
            'success' => true,
            'skipped' => true,
            'message' => 'El catálogo ya tiene registros en la base de datos.',
        ];
    }

    return sip_menu_catalog_seed_modulos($conn, $idPrograma);
}

/**
 * Árbol editable solo desde amd_dashboard_modulos.
 *
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_build_mod_tree(mysqli $conn, int $idPrograma): array
{
    $rows = sip_menu_catalog_load_rows_db($conn, $idPrograma);

    return sip_menu_catalog_build_mod_children($rows, null);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_build_mod_children(array $rows, ?string $parentCod): array
{
    $kids = sip_menu_catalog_children_of($rows, $parentCod);
    $out = [];
    foreach ($kids as $row) {
        $node = sip_menu_catalog_row_to_mod_node($row);
        $cod = (string) ($row['cod_mod'] ?? '');
        $node['children'] = sip_menu_catalog_build_mod_children($rows, $cod);
        $node['db_id'] = (int) ($row['id'] ?? 0);
        $out[] = $node;
    }

    return $out;
}

/**
 * Expande cod_mod marcados con padres (misma lógica que sip_acl_expand_with_parents).
 *
 * @param list<string> $codMods
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function sip_menu_catalog_expand_cod_mods(array $codMods, array $rows): array
{
    $allowed = [];
    foreach ($codMods as $c) {
        $c = trim((string) $c);
        if ($c !== '') {
            $allowed[$c] = true;
        }
    }
    $expanded = sip_acl_expand_with_parents($allowed, $rows);

    return array_keys(array_filter($expanded));
}

/**
 * Definición canónica del menú lateral de index.php (programa Sanidad en amd_programas).
 *
 * @return list<array{parent_cod: ?string, cod_mod: string, tipo: string, nom_mod: string, label_short: string, icono: string, url: string, tipo_param: string, orden: int}>
 */
function sip_menu_catalog_canonical_definition(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rows = [];
    $orden = 0;
    $add = static function (
        ?string $parent,
        string $cod,
        string $tipo,
        string $label,
        string $url = '',
        string $icon = 'fas fa-folder',
        string $tipoParam = ''
    ) use (&$rows, &$orden): void {
        $orden += 10;
        $rows[] = [
            'parent_cod' => $parent,
            'cod_mod' => $cod,
            'tipo' => $tipo,
            'nom_mod' => $label,
            'label_short' => $label,
            'icono' => $icon,
            'url' => $url,
            'tipo_param' => $tipoParam,
            'orden' => $orden,
        ];
    };

    $add(null, 'grp-sp-1', 'group', 'Dashboards', '', 'fas fa-chart-pie');
    $add('grp-sp-1', 'item-sp-1-1', 'item', 'Vista general', 'modules/sip/dashboard-general.php', 'fas fa-home');
    $add('grp-sp-1', 'item-sp-1-2', 'item', 'Resumen laboratorio', 'modules/laboratorio/muestras/dashboard/dashboard-dashboard.php', 'fas fa-flask');
    $add('grp-sp-1', 'item-sp-1-3', 'item', 'Indicadores', 'modules/laboratorio/registro_laboratorio/dashboard-indicadores/dashboard-indicadores.php', 'fas fa-chart-line');
    $add('grp-sp-1', 'item-sp-1-4', 'item', 'Tracking envíos', 'modules/laboratorio/tracking/dashboard/dashboard-tracking.php', 'fas fa-shipping-fast');
    $add('grp-sp-1', 'item-sp-1-5', 'item', 'Planificación', 'modules/planificacion/dashboard/dashboard-planificacion.php', 'fas fa-calendar-alt');

    $add(null, 'item-sp-necro-q', 'item', 'Acceso rápido', '', 'fa-bolt', '');
    $add('item-sp-necro-q', 'grp-sp-necro-sub', 'group', 'Necropsias', '', 'fas fa-dove');
    $add('grp-sp-necro-sub', 'item-sp-necro-reg', 'item', 'Registro', 'modules/necropsias/dashboard-necropsias-registro.php', 'fas fa-file-medical');
    $add('grp-sp-necro-sub', 'item-sp-necro-lst', 'item', 'Listado', 'modules/necropsias/dashboard-necropsias-listado.php', 'fas fa-list');
    $add('grp-sp-necro-sub', 'item-sp-necro-cmp', 'item', 'Comparativos', 'modules/necropsias/dashboard-comparativos-necropsias.php', 'fas fa-chart-bar');
    $add('grp-sp-necro-sub', 'item-sp-necro-manual', 'item', 'Manual', 'modules/necropsias/manual/manual-necropsias.php', 'fas fa-book-open');
    $add('item-sp-necro-q', 'grp-sp-plan-q', 'group', 'Planificación', '', 'fas fa-calendar-alt');
    $add('grp-sp-plan-q', 'hub-plan_prog_reg', 'item', 'Registro programa', 'modules/planificacion/programas/dashboard-programas-registro.php', 'fas fa-clipboard-list', 'plan_prog_reg');
    $add('grp-sp-plan-q', 'hub-plan_prog_lst', 'item', 'Listado programas', 'modules/planificacion/programas/dashboard-programas-listado.php', 'fas fa-list', 'plan_prog_lst');
    $add('grp-sp-plan-q', 'hub-plan_asig_reg', 'item', 'Registro asignación', 'modules/planificacion/cronograma/dashboard-cronograma-registro.php', 'fas fa-calendar-plus', 'plan_asig_reg');
    $add('grp-sp-plan-q', 'hub-plan_asig_evt', 'item', 'Registro eventual', 'modules/planificacion/cronograma/dashboard-cronograma-asignacion-eventual.php', 'fas fa-calendar-day', 'plan_asig_evt');
    $add('grp-sp-plan-q', 'hub-plan_asig_lst', 'item', 'Listado asignaciones', 'modules/planificacion/cronograma/dashboard-cronograma-listado.php', 'fas fa-calendar-check', 'plan_asig_lst');
    $add('grp-sp-plan-q', 'hub-plan_manual', 'item', 'Manual', 'modules/planificacion/manual/manual-planificacion.php', 'fas fa-book-open', 'plan_manual');
    $add('item-sp-necro-q', 'grp-sp-dl', 'group', 'Dataloggers', '', 'fas fa-stopwatch');
    $add('grp-sp-dl', 'item-sp-dl-1', 'item', 'Temp y Hum Ambiental', 'modules/temperatura/dashboard-temperatura-datalogger.php', 'fas fa-thermometer-half');
    $add('grp-sp-dl', 'item-sp-dl-2', 'item', 'Temp y Hum Transporte BB', 'modules/temperatura/dashboard-temp-transporte-pollo-bb.php', 'fas fa-truck');
    $add('grp-sp-dl', 'item-sp-dl-manual', 'item', 'Manual', 'modules/temperatura/manual/manual-dataloggers.php', 'fas fa-book-open');
    $add('item-sp-necro-q', 'item-sp-evf-quick', 'item', 'Agentes causales', 'modules/evidencias/dashboard-evidencias-fotograficas.php', 'fas fa-camera-retro', 'agc');

    $add(null, 'grp-sp-2', 'group', 'Procesos', '', 'fas fa-sitemap');
    $add('grp-sp-2', 'item-sp-2-0', 'item', 'Árbol de procesos', '', 'fas fa-project-diagram');

    $add(null, 'grp-sp-3', 'group', 'Laboratorio', '', 'fas fa-flask');
    $add('grp-sp-3', 'item-sp-3-1', 'item', 'Registro de muestras', 'modules/laboratorio/muestras/registro_muestras/dashboard-registro-muestras.php', 'fas fa-vial');
    $add('grp-sp-3', 'item-sp-3-2', 'item', 'Listado de muestras', 'modules/laboratorio/muestras/listado_muestras/dashboard-reportes.php', 'fas fa-list');
    $add('grp-sp-3', 'item-sp-3-3', 'item', 'Registro de resultados', 'modules/laboratorio/registro_laboratorio/dashboard-rpta-laboratorio.php', 'fas fa-file-medical');
    $add('grp-sp-3', 'item-sp-3-4', 'item', 'Seguimiento', 'modules/laboratorio/seguimiento/dashboard-seguimiento.php', 'fas fa-chart-line');
    $add('grp-sp-3', 'item-sp-3-5', 'item', 'Trazabilidad', 'modules/laboratorio/trazabilidad/dashboard-trazabilidad.php', 'fas fa-route', 'mue_lst');
    $add('grp-sp-3', 'item-sp-3-manual', 'item', 'Manual', 'modules/laboratorio/manual/manual-laboratorio.php', 'fas fa-book-open');
    $add('grp-sp-3', 'grp-sp-3-trk', 'group', 'Tracking', '', 'fas fa-map-marked-alt');
    $add('grp-sp-3-trk', 'item-sp-3-trk-1', 'item', 'Escaneo', 'modules/laboratorio/tracking/escaneo/dashboard-escaneoQR.php', 'fas fa-qrcode');
    $add('grp-sp-3-trk', 'item-sp-3-trk-2', 'item', 'Seguimiento envíos', 'modules/laboratorio/tracking/seguimiento_envios/dashboard-tracking-muestra.php', 'fas fa-route');
    $add('grp-sp-3-trk', 'item-sp-3-trk-3', 'item', 'Listado tracking', 'modules/laboratorio/tracking/reporte/dashboard-reporte-tracking.php', 'fas fa-list-alt');

    $add(null, 'grp-sp-4', 'group', 'Reportes', '', 'fas fa-chart-bar');
    $add('grp-sp-4', 'item-sp-4-1', 'item', 'Cronograma', 'modules/planificacion/calendario/dashboard-calendario.php', 'fas fa-calendar');
    $add('grp-sp-4', 'item-sp-4-2', 'item', 'Plan vs desarrollo', 'modules/planificacion/cronograma/dashboard-comparativo.php', 'fas fa-balance-scale');
    $add('grp-sp-4', 'item-sp-4-3', 'item', 'Cumplimiento de estándares', 'modules/planificacion/cronograma/dashboard-estandares-necropsia.php', 'fas fa-check-double');
    $add('grp-sp-4', 'item-sp-4-4', 'item', 'Consumo', 'modules/planificacion/cronograma/dashboard-informe-productos-periodo.php', 'fas fa-boxes');
    $add('grp-sp-4', 'grp-sp-4-mort', 'group', 'Mortalidad', '', 'fas fa-dove');
    $add('grp-sp-4-mort', 'item-sp-4-mort', 'item', 'Mortalidad', 'modules/mortalidad/listado/dashboard-listado.php', 'fas fa-dove');
    $add('grp-sp-4-mort', 'item-sp-4-mort-hist', 'item', 'Historial', 'modules/mortalidad/historial/dashboard-historial.php', 'fas fa-history');
    $add('grp-sp-4-mort', 'item-sp-4-mort-aud', 'item', 'Auditoría', 'modules/mortalidad/auditoria/dashboard-auditoria.php', 'fas fa-clipboard-check');
    $add('grp-sp-4-mort', 'item-sp-4-mort-inf-ctb', 'item', 'Informe Contable', 'modules/mortalidad/informe_contable/dashboard-informe-contable.php', 'fas fa-file-invoice-dollar');
    $add('grp-sp-4-mort', 'item-sp-4-mort-vnt', 'item', 'Ventas', 'modules/mortalidad/ventas/dashboard-ventas.php', 'fas fa-hand-holding-usd');
    $add('grp-sp-4-mort', 'item-sp-4-mort-dsp', 'item', 'Despacho', 'modules/mortalidad/despacho/dashboard-despacho.php', 'fas fa-truck-loading');
    $add('grp-sp-4-mort', 'item-sp-4-mort-grf', 'item', 'Gráficas', 'modules/mortalidad/graficas/dashboard-graficas.php', 'fas fa-chart-line');
    $add('grp-sp-4', 'grp-sp-4-hc', 'group', 'Historia clínica', '', 'fas fa-notes-medical');
    $add('grp-sp-4-hc', 'item-sp-4-5', 'item', 'Historia clínica', 'modules/sip/historia-clinica/dashboard-historia-clinica.php', 'fas fa-notes-medical', 'rep_hc');
    $add('grp-sp-4-hc', 'item-sp-4-11', 'item', 'Seguimiento de crianza', 'modules/sip/historia-clinica/dashboard-seguimiento-crianza.php', 'fas fa-clipboard-list', 'rep_hc_seg');
    $add('grp-sp-4-hc', 'item-sp-4-8', 'item', 'Gráficas', 'modules/sip/historia-clinica-graficas/dashboard-historia-clinica-graficas.php', 'fas fa-chart-line', 'rep_hc_liq');
    $add('grp-sp-4-hc', 'item-sp-4-9', 'item', 'Gatilladores', 'modules/sip/gatilladores/dashboard-gatilladores.php', 'fas fa-bolt', 'rep_hc_gat');
    $add('grp-sp-4-hc', 'item-sp-4-10', 'item', 'Consultas', 'modules/sip/historia-clinica/dashboard-consultas.php', 'fas fa-search', 'rep_hc_con');
    $add('grp-sp-4-hc', 'item-sp-4-hc-interconsultas', 'item', 'Interconsultas', 'modules/sip/interconsultas/dashboard-interconsultas.php', 'fas fa-comments');
    $add('grp-sp-4', 'item-sp-4-manual', 'item', 'Manual', 'modules/reportes/manual/manual-reportes.php', 'fas fa-book-open');

    $add(null, 'grp-sp-5', 'group', 'Configuración', '', 'fas fa-cogs');
    $add('grp-sp-5', 'cfg-grp-cuenta', 'config_group', 'Mi cuenta', '', 'fas fa-user-circle');
    $add('cfg-grp-cuenta', 'item-cfg-cuenta-1', 'item', 'Cambiar contraseña', 'modules/configuracion/mi_cuenta/dashboard-mi-cuenta.php', 'fa-key');
    $add('grp-sp-5', 'cfg-grp-ml', 'config_group', 'Muestras y Laboratorio', '', 'fas fa-flask');
    $add('cfg-grp-ml', 'item-cfg-ml-1', 'item', 'Laboratorios', 'modules/configuracion/laboratorio/dashboard-laboratorio.php', 'fa-flask');
    $add('cfg-grp-ml', 'item-cfg-ml-2', 'item', 'Tipos muestra', 'modules/configuracion/tipo_muestra/dashboard-tipo-muestra.php', 'fa-vial');
    $add('cfg-grp-ml', 'item-cfg-ml-3', 'item', 'Tipos análisis', 'modules/configuracion/tipo_analisis/dashboard-analisis.php', 'fa-microscope');
    $add('cfg-grp-ml', 'item-cfg-ml-4', 'item', 'Paquetes', 'modules/configuracion/paquete_analisis/dashboard-paquete-analisis.php', 'fa-box');
    $add('cfg-grp-ml', 'item-cfg-ml-5', 'item', 'Tipos respuesta', 'modules/configuracion/tipo_respuesta/dashboard-respuesta.php', 'fa-reply');
    $add('cfg-grp-ml', 'item-cfg-ml-6', 'item', 'Emp. transporte', 'modules/configuracion/empTransporte/dashboard-empresas-transporte.php', 'fa-truck');
    $add('cfg-grp-ml', 'item-cfg-ml-7', 'item', 'Correo', 'modules/configuracion/correo_contacto/dashboard-correo-contactos.php', 'fa-envelope');
    $add('cfg-grp-ml', 'item-cfg-ml-8', 'item', 'Procedencia', 'modules/configuracion/origen_aves/dashboard-origen-aves.php', 'fa-map-marker-alt');

    $add('grp-sp-5', 'cfg-grp-pl', 'config_group', 'Planificación', '', 'fas fa-calendar-alt');
    $add('cfg-grp-pl', 'item-cfg-pl-1', 'item', 'Tipos programa', 'modules/configuracion/tipoPrograma/dashboard-tipo-programa.php', 'fa-list');
    $add('cfg-grp-pl', 'item-cfg-pl-2', 'item', 'Proveedor', 'modules/configuracion/proveedor/dashboard-proveedor.php', 'fa-handshake');
    $add('cfg-grp-pl', 'item-cfg-pl-3', 'item', 'Productos', 'modules/configuracion/productos/dashboard-productos.php', 'fa-cubes');
    $add('cfg-grp-pl', 'item-cfg-pl-4', 'item', 'Enfermedades', 'modules/configuracion/enfermedades/dashboard-enfermedades.php', 'fa-notes-medical');

    $add('grp-sp-5', 'cfg-grp-adm', 'config_group', 'Administración', '', 'fas fa-tools');
    $add('cfg-grp-adm', 'item-cfg-adm-1', 'item', 'Tareas y Parámetros', 'modules/configuracion/tareasParametros/dashboard-tareas-parametros.php', 'fa-list-check');
    $add('cfg-grp-adm', 'item-cfg-adm-2', 'item', 'Estándares de Procesos', 'modules/configuracion/estandares_nuevo/dashboard-estandares2.php', 'fa-sitemap');
    $add('cfg-grp-adm', 'item-cfg-adm-3', 'item', 'Notificaciones Whatsapp', 'modules/configuracion/notificaciones_whatsapp/dashboard-notificaciones-whatsapp.php', 'fa-mobile-alt');
    $add('cfg-grp-adm', 'item-cfg-adm-4', 'item', 'Notificación usuarios', 'modules/configuracion/notificaciones_usuarios/dashboard-notificaciones-usuarios.php', 'fa-users');
    $add('cfg-grp-adm', 'item-cfg-adm-8', 'item', 'Destinatarios alertas gatilladores', 'modules/configuracion/gt_alertas_destinatarios/dashboard-gt-alertas-destinatarios.php', 'fa-bell');
    $add('cfg-grp-adm', 'item-cfg-adm-5', 'item', 'Asignación de Zonas', 'modules/configuracion/mis_zonas/dashboard-mis-zonas.php', 'fa-map');
    $add('cfg-grp-adm', 'item-cfg-adm-6', 'item', 'Roles de Usuario', 'modules/configuracion/roles_permisos/dashboard-roles-permisos.php', 'fa-shield-alt');
    $add('cfg-grp-adm', 'item-cfg-adm-7', 'item', 'Mortalidad', 'modules/configuracion/mortalidad_admin/dashboard-mortalidad-admin.php', 'fa-dove');

    return $cache = $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_canonical_as_mod_rows(int $idPrograma): array
{
    $out = [];
    $fakeId = 1;
    foreach (sip_menu_catalog_canonical_definition() as $def) {
        $row = [
            'id' => $fakeId++,
            'id_programa' => (string) $idPrograma,
            'cod_mod' => $def['cod_mod'],
            'parent_cod' => $def['parent_cod'],
            'tipo' => $def['tipo'],
            'nom_mod' => $def['nom_mod'],
            'label_short' => $def['label_short'],
            'icono' => $def['icono'],
            'url' => $def['url'],
            'tipo_param' => $def['tipo_param'],
            'orden' => $def['orden'],
        ];
        $out[] = sip_acl_normalize_mod_row($row);
    }

    return $out;
}

/**
 * Cuenta filas del catálogo en BD para el programa.
 */
function sip_menu_catalog_count_programa_rows(mysqli $conn, int $idPrograma): int
{
    $idProgStr = (string) $idPrograma;
    $st = $conn->prepare('SELECT COUNT(*) AS c FROM amd_dashboard_modulos WHERE id_programa = ?');
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $idProgStr);
    $st->execute();
    $res = $st->get_result();
    $c = 0;
    if ($res && ($row = $res->fetch_assoc())) {
        $c = (int) ($row['c'] ?? 0);
    }
    $st->close();

    return $c;
}

/**
 * Valor de tipo persistido en amd_dashboard_modulos (columna corta / ENUM).
 */
function sip_menu_catalog_tipo_for_db(string $tipo): string
{
    $t = trim($tipo);
    if ($t === 'config_group' || $t === 'config_subgroup') {
        return 'cgroup';
    }

    return $t;
}

/**
 * Tipo usado en PHP, sidebar y editor (cgroup → config_group).
 */
function sip_menu_catalog_tipo_from_db(string $tipo): string
{
    $t = trim($tipo);
    if ($t === 'cgroup') {
        return 'config_group';
    }

    return $t;
}

/**
 * Orden de secciones principales del sidebar (como index.php legado).
 *
 * @return array<string, int>
 */
function sip_menu_catalog_top_level_orden_map(): array
{
    return [
        'grp-sp-1' => 10,
        'item-sp-necro-q' => 20,
        'grp-sp-2' => 30,
        'grp-sp-3' => 40,
        'grp-sp-4' => 50,
        'grp-sp-5' => 60,
    ];
}

/**
 * Ajusta filas del catálogo para el render del menú lateral.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sip_menu_catalog_normalize_row(array $row): array
{
    if (isset($row['tipo'])) {
        $row['tipo'] = sip_menu_catalog_tipo_from_db((string) $row['tipo']);
    }
    $cod = (string) ($row['cod_mod'] ?? '');
    if (strncmp($cod, 'hub-plan_', 9) === 0) {
        $row['parent_cod'] = 'grp-sp-plan-q';
        $row['cod_padre'] = 'grp-sp-plan-q';
    }
    foreach (['grp-sp-dl', 'grp-sp-plan-q', 'grp-sp-necro-sub', 'item-sp-evf-quick'] as $quickChild) {
        if ($cod === $quickChild) {
            $row['parent_cod'] = 'item-sp-necro-q';
            $row['cod_padre'] = 'item-sp-necro-q';
            break;
        }
    }
    if ($cod === 'item-sp-evf-quick') {
        $row['icono'] = 'fas fa-camera-retro';
    }
    $pad = $row['parent_cod'] ?? $row['cod_padre'] ?? null;
    $isTop = $pad === null || $pad === '';
    if ($isTop && isset(sip_menu_catalog_top_level_orden_map()[$cod])) {
        $row['orden'] = sip_menu_catalog_top_level_orden_map()[$cod];
    }

    return $row;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_normalize_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = sip_menu_catalog_normalize_row($row);
    }
    usort(
        $out,
        static function (array $a, array $b): int {
            return ((int) ($a['orden'] ?? 0)) <=> ((int) ($b['orden'] ?? 0));
        }
    );

    return $out;
}

/**
 * BD + filas canónicas que falten (fallback en memoria para el sidebar).
 *
 * @return list<array<string, mixed>>
 */
function sip_menu_catalog_load_rows_with_fallback(mysqli $conn, int $idPrograma): array
{
    $db = sip_acl_load_modulos($conn, $idPrograma);
    $byCod = [];
    foreach ($db as $row) {
        $byCod[(string) ($row['cod_mod'] ?? '')] = $row;
    }
    foreach (sip_menu_catalog_canonical_as_mod_rows($idPrograma) as $row) {
        $cod = (string) ($row['cod_mod'] ?? '');
        if ($cod === '' || isset($byCod[$cod])) {
            continue;
        }
        $byCod[$cod] = $row;
    }

    $patched = sip_menu_catalog_apply_canonical_row_patches(array_values($byCod));

    return sip_menu_catalog_normalize_rows(sip_menu_catalog_filter_retired_rows($patched));
}

/**
 * Elimina de BD módulos retirados del catálogo.
 */
function sip_menu_catalog_purge_retired_modulos(mysqli $conn, int $idPrograma): void
{
    $retired = sip_menu_catalog_retired_cod_mods();
    if ($retired === []) {
        return;
    }
    $idProgStr = (string) $idPrograma;
    $st = $conn->prepare('DELETE FROM amd_dashboard_modulos WHERE id_programa = ? AND cod_mod = ?');
    if (!$st) {
        return;
    }
    foreach ($retired as $cod) {
        $st->bind_param('ss', $idProgStr, $cod);
        $st->execute();
    }
    $st->close();
}

/**
 * Inserta en BD solo los módulos canónicos que aún no existen (sin sobrescribir personalizados).
 */
function sip_menu_catalog_upsert_missing_from_canonical(mysqli $conn, ?int $idPrograma = null): int
{
    $idPrograma = $idPrograma ?? sip_acl_id_programa_sanidad($conn);
    $idProgStr = (string) $idPrograma;
    $st = $conn->prepare('SELECT cod_mod FROM amd_dashboard_modulos WHERE id_programa = ?');
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $idProgStr);
    $st->execute();
    $res = $st->get_result();
    $existing = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $c = trim((string) ($row['cod_mod'] ?? ''));
        if ($c !== '') {
            $existing[$c] = true;
        }
    }
    $st->close();

    $inserted = 0;
    foreach (sip_menu_catalog_canonical_definition() as $def) {
        $cod = trim((string) ($def['cod_mod'] ?? ''));
        if ($cod === '' || isset($existing[$cod])) {
            continue;
        }
        $parent = $def['parent_cod'];
        $tipo = sip_menu_catalog_tipo_for_db((string) $def['tipo']);
        $stIns = $conn->prepare(
            'INSERT INTO amd_dashboard_modulos (id_programa, cod_mod, tipo, parent_cod, nom_mod, label_short, icono, url, tipo_param, orden)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stIns) {
            continue;
        }
        $nom = $def['nom_mod'];
        $label = $def['label_short'];
        $icon = $def['icono'];
        $url = $def['url'];
        $tp = $def['tipo_param'];
        $orden = (int) $def['orden'];
        $stIns->bind_param('sssssssssi', $idProgStr, $cod, $tipo, $parent, $nom, $label, $icon, $url, $tp, $orden);
        if ($stIns->execute()) {
            $inserted++;
            $existing[$cod] = true;
        }
        $stIns->close();
    }

    return $inserted;
}

/**
 * Inserta o actualiza el catálogo canónico en amd_dashboard_modulos.
 *
 * @return array{success: bool, inserted: int, updated: int, total: int, message?: string}
 */
function sip_menu_catalog_seed_modulos(mysqli $conn, ?int $idPrograma = null): array
{
    $idPrograma = $idPrograma ?? sip_acl_id_programa_sanidad($conn);
    $defs = sip_menu_catalog_canonical_definition();
    $idProgStr = (string) $idPrograma;
    $sql = 'INSERT INTO amd_dashboard_modulos (id_programa, cod_mod, tipo, parent_cod, nom_mod, label_short, icono, url, tipo_param, orden)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              tipo = VALUES(tipo),
              parent_cod = VALUES(parent_cod),
              nom_mod = VALUES(nom_mod),
              label_short = VALUES(label_short),
              icono = VALUES(icono),
              url = VALUES(url),
              tipo_param = VALUES(tipo_param),
              orden = VALUES(orden)';
    $st = $conn->prepare($sql);
    if (!$st) {
        return ['success' => false, 'inserted' => 0, 'updated' => 0, 'total' => 0, 'message' => $conn->error];
    }

    $inserted = 0;
    $updated = 0;
    foreach ($defs as $def) {
        $parent = $def['parent_cod'];
        $cod = $def['cod_mod'];
        $tipo = sip_menu_catalog_tipo_for_db((string) $def['tipo']);
        $nom = $def['nom_mod'];
        $label = $def['label_short'];
        $icon = $def['icono'];
        $url = $def['url'];
        $tp = $def['tipo_param'];
        $orden = (int) $def['orden'];

        $st->bind_param('sssssssssi', $idProgStr, $cod, $tipo, $parent, $nom, $label, $icon, $url, $tp, $orden);
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();

            return ['success' => false, 'inserted' => $inserted, 'updated' => $updated, 'total' => count($defs), 'message' => $err];
        }
        if ($st->affected_rows === 1) {
            $inserted++;
        } elseif ($st->affected_rows === 2) {
            $updated++;
        }
    }
    $st->close();
    sip_menu_catalog_purge_retired_modulos($conn, $idPrograma);

    return [
        'success' => true,
        'inserted' => $inserted,
        'updated' => $updated,
        'total' => count($defs),
        'message' => 'Catálogo del menú sincronizado (' . count($defs) . ' módulos).',
    ];
}

/**
 * Reemplazo total: elimina todos los módulos del programa en BD y los reinserta desde el canónico.
 * Usado por el botón "Sincronizar con index" en la pestaña Estructura menú.
 *
 * @return array{success: bool, inserted: int, message?: string}
 */
function sip_menu_catalog_sync_from_canonical(mysqli $conn, ?int $idPrograma = null): array
{
    $idPrograma = $idPrograma ?? sip_acl_id_programa_sanidad($conn);
    $idProgStr = (string) $idPrograma;

    $del = $conn->prepare('DELETE FROM amd_dashboard_modulos WHERE id_programa = ?');
    if (!$del) {
        return ['success' => false, 'inserted' => 0, 'message' => $conn->error];
    }
    $del->bind_param('s', $idProgStr);
    $del->execute();
    $del->close();

    return sip_menu_catalog_seed_modulos($conn, $idPrograma);
}
