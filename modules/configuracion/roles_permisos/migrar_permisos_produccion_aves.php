<?php

declare(strict_types=1);

/**
 * Migración ACL: Sanidad → Producción Aves.
 *
 * Crea un id_programa nuevo para «ProduccionAves» y migra a ese programa
 * únicamente el bloque Mortalidad de los roles de Sanidad:
 *
 *   1. adm_rol: garantiza UNIQUE (id_programa, cod_rol) para poder reutilizar
 *      los mismos cod_rol de Sanidad en el programa nuevo.
 *   2. amd_programas: crea el programa con el siguiente id_programa libre.
 *   3. amd_dashboard_modulos: siembra el menú Mortalidad del proyecto PA.
 *   4. adm_rol: clona los roles Sistemas y Visualizador de mortalidad con el
 *      cod_rol real de Sanidad (resuelto dinámicamente por nom_rol).
 *   5. adm_rol_progr_modulo: copia los permisos del bloque Mortalidad (cod_mod mapeados).
 *   6. adm_usuario_rol: asegura la asignación de los usuarios que hoy tienen esos roles.
 *
 * Vista previa (sin cambios en BD): migrar_permisos_produccion_aves.php
 * Ejecutar:                        migrar_permisos_produccion_aves.php?ejecutar=1
 * CLI simulación:                  php migrar_permisos_produccion_aves.php
 * CLI ejecución:                   php migrar_permisos_produccion_aves.php --ejecutar
 *
 * NOTA: al reutilizarse los mismos cod_rol, los usuarios ya presentes en
 * adm_usuario_rol heredan el acceso al programa nuevo. El paso 6 es idempotente:
 * solo confirma/crea las filas que falten.
 */

$esCli = (PHP_SAPI === 'cli');
$ejecutar = $esCli
    ? in_array('--ejecutar', $argv ?? [], true)
    : !empty($_GET['ejecutar']);

require_once __DIR__ . '/../../../core/config.php';

if (!$esCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['active']) || empty($_SESSION['usuario'])) {
        header('Location: ../../../core/auth/login.php');
        exit;
    }
}

require_once PA_DB_PATH;

/** Nombre del programa destino (canónico). */
const PMIG_NOMBRE_PROGRAMA = 'ProduccionAves';

/**
 * Nombres previos del programa destino. Si se encuentra uno, se renombra al
 * canónico conservando su id_programa, para no crear un programa duplicado.
 */
const PMIG_NOMBRE_PROGRAMA_ALIAS = [
    'Produccion Aves',
    'Producción Aves',
];

/** Nombre del programa origen. */
const PMIG_NOMBRE_PROGRAMA_SANIDAD = 'Sanidad';

/**
 * Roles que se migran, identificados por NOMBRE (fuente de verdad).
 *
 * El cod_rol NO se hardcodea: se resuelve dinámicamente desde adm_rol
 * buscando por nom_rol, porque en producción el código puede ser distinto
 * (p. ej. «SIS», «VISMORT»). El mismo cod_rol hallado en Sanidad se reutiliza
 * en el programa destino, ya que adm_rol tiene UNIQUE (id_programa, cod_rol).
 */
const PMIG_ROLES_NOMBRES = [
    'Sistemas',
    'Gestion Aves',
];

/**
 * cod_rol objetivo en producción (valor real en adm_rol).
 * Se resuelve en PA y en Sanidad; no se hardcodea id_rol.
 */
const PMIG_ROLES_COD_OBJETIVO = [
    'SISTEMAS GESTIONAVES',
];

/** adm_rol.id_programa es tinyint(4): límite superior permitido. */
const PMIG_MAX_ID_PROGRAMA = 127;

/** Códigos de módulo de Sanidad que componen el bloque Mortalidad. */
const PMIG_SANIDAD_COD_MODS_MORT = [
    'grp-sp-4',
    'grp-sp-4-mort',
    'item-sp-4-mort',
    'item-sp-4-mort-hist',
    'item-sp-4-mort-aud',
    'item-sp-4-mort-inf-ctb',
];

/** Nombre del índice UNIQUE compuesto que se necesita en adm_rol. */
const PMIG_UK_PROGRAMA_COD = 'uk_adm_rol_programa_cod';

/**
 * Menú Mortalidad de Producción Aves (amd_dashboard_modulos).
 *
 * @return list<array{cod_mod:string,tipo:string,parent_cod:?string,nom_mod:string,label_short:string,icono:string,url:string,orden:int}>
 */
function pmig_menu_definicion(): array
{
    return [
        [
            'cod_mod' => 'grp-pa-1',
            'tipo' => 'group',
            'parent_cod' => null,
            'nom_mod' => 'Produccion Aves',
            'label_short' => 'Produccion Aves',
            'icono' => 'fas fa-egg',
            'url' => '',
            'orden' => 10,
        ],
        [
            'cod_mod' => 'grp-pa-mort',
            'tipo' => 'group',
            'parent_cod' => 'grp-pa-1',
            'nom_mod' => 'Mortalidad',
            'label_short' => 'Mortalidad',
            'icono' => 'fas fa-dove',
            'url' => '',
            'orden' => 20,
        ],
        [
            'cod_mod' => 'item-pa-mort',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Listado',
            'label_short' => 'Listado',
            'icono' => 'fas fa-list',
            'url' => 'modules/mortalidad/listado/dashboard-listado.php',
            'orden' => 30,
        ],
        [
            'cod_mod' => 'item-pa-mort-hist',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Historial',
            'label_short' => 'Historial',
            'icono' => 'fas fa-history',
            'url' => 'modules/mortalidad/historial/dashboard-historial.php',
            'orden' => 40,
        ],
        [
            'cod_mod' => 'item-pa-mort-aud',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Auditoría',
            'label_short' => 'Auditoría',
            'icono' => 'fas fa-clipboard-check',
            'url' => 'modules/mortalidad/auditoria/dashboard-auditoria.php',
            'orden' => 50,
        ],
        [
            'cod_mod' => 'item-pa-mort-inf-ctb',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Informe Contable',
            'label_short' => 'Informe Contable',
            'icono' => 'fas fa-file-invoice-dollar',
            'url' => 'modules/mortalidad/informe_contable/dashboard-informe-contable.php',
            'orden' => 60,
        ],
        [
            'cod_mod' => 'item-pa-mort-ven',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Ventas',
            'label_short' => 'Ventas',
            'icono' => 'fas fa-hand-holding-usd',
            'url' => 'modules/mortalidad/ventas/dashboard-ventas.php',
            'orden' => 70,
        ],
        [
            'cod_mod' => 'item-pa-mort-dsp',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Despacho',
            'label_short' => 'Despacho',
            'icono' => 'fas fa-truck-loading',
            'url' => 'modules/mortalidad/despacho/dashboard-despacho.php',
            'orden' => 75,
        ],
        [
            'cod_mod' => 'item-pa-mort-grf',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Gráficas',
            'label_short' => 'Gráficas',
            'icono' => 'fas fa-chart-line',
            'url' => 'modules/mortalidad/graficas/dashboard-graficas.php',
            'orden' => 80,
        ],
        [
            'cod_mod' => 'item-pa-mort-grfh',
            'tipo' => 'item',
            'parent_cod' => 'grp-pa-mort',
            'nom_mod' => 'Gráficas horizontales',
            'label_short' => 'Gráficas horizontales',
            'icono' => 'fas fa-chart-bar',
            'url' => 'modules/mortalidad/graficas-horizontales/dashboard-graficas-horizontales.php',
            'orden' => 90,
        ],
    ];
}

/**
 * cod_mod del bloque Mortalidad de Producción Aves (se otorgan al completo).
 *
 * @return list<string>
 */
function pmig_menu_cod_mods(): array
{
    $out = [];
    foreach (pmig_menu_definicion() as $row) {
        $out[] = $row['cod_mod'];
    }

    return $out;
}

/** Mapeo cod_mod Sanidad → Producción Aves. */
function pmig_map_cod_mod(string $codModSanidad): string
{
    $map = [
        'grp-sp-4' => 'grp-pa-1',
        'grp-sp-4-mort' => 'grp-pa-mort',
        'item-sp-4-mort' => 'item-pa-mort',
        'item-sp-4-mort-hist' => 'item-pa-mort-hist',
        'item-sp-4-mort-aud' => 'item-pa-mort-aud',
        'item-sp-4-mort-inf-ctb' => 'item-pa-mort-inf-ctb',
        'item-sp-4-mort-vnt' => 'item-pa-mort-ven',
        'item-sp-4-mort-dsp' => 'item-pa-mort-dsp',
        'item-sp-4-mort-grf' => 'item-pa-mort-grf',
        'item-sp-4-mort-grfh' => 'item-pa-mort-grfh',
    ];

    return $map[$codModSanidad] ?? $codModSanidad;
}

/**
 * Roles destino (Sistemas / Gestión Aves) por cod_rol y nom_rol en PA o Sanidad.
 *
 * @return list<array{cod_rol: string, nom_rol: string, id_sanidad: int, id_pa: int}>
 */
function pmig_roles_objetivo_resueltos(mysqli $conn, int $idProgPa, int $idProgSanidad): array
{
    $out = [];

    $merge = static function (?array $row, bool $esSanidad) use (&$out): void {
        if ($row === null || trim((string) ($row['cod_rol'] ?? '')) === '') {
            return;
        }
        $key = strtoupper(trim((string) $row['cod_rol']));
        if (!isset($out[$key])) {
            $out[$key] = [
                'cod_rol' => trim((string) $row['cod_rol']),
                'nom_rol' => trim((string) ($row['nom_rol'] ?? '')),
                'id_sanidad' => 0,
                'id_pa' => 0,
            ];
        }
        if ($esSanidad) {
            $out[$key]['id_sanidad'] = (int) ($row['id'] ?? 0);
        } else {
            $out[$key]['id_pa'] = (int) ($row['id'] ?? 0);
        }
        if ($out[$key]['nom_rol'] === '') {
            $out[$key]['nom_rol'] = trim((string) ($row['nom_rol'] ?? ''));
        }
    };

    foreach (PMIG_ROLES_COD_OBJETIVO as $codRol) {
        $codRol = trim((string) $codRol);
        if ($codRol === '') {
            continue;
        }
        $merge(pmig_rol_por_cod($conn, $idProgPa, $codRol), false);
        $merge(pmig_rol_por_cod($conn, $idProgSanidad, $codRol), true);
    }

    foreach (PMIG_ROLES_NOMBRES as $nomRol) {
        $merge(pmig_rol_por_nombre($conn, $idProgPa, $nomRol), false);
        $merge(pmig_rol_por_nombre($conn, $idProgSanidad, $nomRol), true);
    }

    return array_values($out);
}

/** cod_mod Sanidad mínimos para ver la sección Despacho en el shell Sanidad. */
function pmig_cod_mods_sanidad_despacho(): array
{
    return [
        'grp-sp-4',
        'grp-sp-4-mort',
        'item-sp-4-mort-dsp',
    ];
}

function pmig_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Texto legible del id_rol destino.
 *
 * En simulación, un rol que todavía no existe en el programa destino no tiene
 * id (aún no se ha insertado), por lo que se informa como «nuevo» en lugar de
 * mostrar un «0» que parece un error.
 */
function pmig_txt_id_rol_pa(int $idRolPa, bool $ejecutar): string
{
    if ($idRolPa > 0) {
        return 'id_rol=' . $idRolPa;
    }

    return $ejecutar ? 'id_rol=?' : 'rol nuevo (se creará al ejecutar)';
}

// ─────────────────────────────────────────────────────────────────────────────
// Introspección de esquema
// ─────────────────────────────────────────────────────────────────────────────

function pmig_indices(mysqli $conn, string $tabla): array
{
    $r = @$conn->query('SHOW INDEX FROM `' . str_replace('`', '``', $tabla) . '`');
    if (!$r) {
        return [];
    }
    $porNombre = [];
    while ($row = $r->fetch_assoc()) {
        $nombre = (string) ($row['Key_name'] ?? '');
        if ($nombre === '' || $nombre === 'PRIMARY') {
            continue;
        }
        if (!isset($porNombre[$nombre])) {
            $porNombre[$nombre] = ['non_unique' => (int) ($row['Non_unique'] ?? 1), 'cols' => []];
        }
        $porNombre[$nombre]['cols'][(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
    }
    foreach ($porNombre as $nombre => $meta) {
        ksort($porNombre[$nombre]['cols']);
        $porNombre[$nombre]['cols'] = array_values($porNombre[$nombre]['cols']);
    }

    return $porNombre;
}

/** Índices UNIQUE compuestos solo por cod_rol (bloquean reutilizar códigos). */
function pmig_unique_solo_cod_rol(mysqli $conn): array
{
    $out = [];
    foreach (pmig_indices($conn, 'adm_rol') as $nombre => $meta) {
        if ($meta['non_unique'] === 0 && $meta['cols'] === ['cod_rol']) {
            $out[] = $nombre;
        }
    }

    return $out;
}

function pmig_tiene_unique_programa_cod(mysqli $conn): bool
{
    foreach (pmig_indices($conn, 'adm_rol') as $meta) {
        if ($meta['non_unique'] === 0 && $meta['cols'] === ['id_programa', 'cod_rol']) {
            return true;
        }
    }

    return false;
}

function pmig_adm_rol_tiene_id_programa(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `adm_rol` LIKE 'id_programa'");
    $cache = $r && $r->num_rows > 0;

    return $cache;
}

/**
 * Garantiza UNIQUE (id_programa, cod_rol) en adm_rol (equivalente a
 * sanidad/modules/configuracion/roles_permisos/migrar_adm_rol_unique_programa.php).
 *
 * @return array{ok:bool,mensajes:list<string>}
 */
function pmig_asegurar_unique_programa_cod(mysqli $conn, bool $ejecutar): array
{
    $mensajes = [];

    if (!pmig_adm_rol_tiene_id_programa($conn)) {
        return ['ok' => false, 'mensajes' => ['adm_rol no tiene columna id_programa: no se puede continuar.']];
    }

    $ukViejos = pmig_unique_solo_cod_rol($conn);
    $yaTiene = pmig_tiene_unique_programa_cod($conn);

    if ($yaTiene && $ukViejos === []) {
        $mensajes[] = 'adm_rol ya tiene UNIQUE (id_programa, cod_rol). Sin cambios.';

        return ['ok' => true, 'mensajes' => $mensajes];
    }

    if (!$ejecutar) {
        foreach ($ukViejos as $nombre) {
            $mensajes[] = 'Simulación: se eliminaría el índice UNIQUE `' . $nombre . '` (solo cod_rol).';
        }
        if (!$yaTiene) {
            $mensajes[] = 'Simulación: se crearía UNIQUE `' . PMIG_UK_PROGRAMA_COD . '` (id_programa, cod_rol).';
        }

        return ['ok' => true, 'mensajes' => $mensajes];
    }

    try {
        foreach ($ukViejos as $nombre) {
            if (!$conn->query('ALTER TABLE `adm_rol` DROP INDEX `' . str_replace('`', '``', $nombre) . '`')) {
                throw new RuntimeException('No se pudo eliminar el índice `' . $nombre . '`: ' . $conn->error);
            }
            $mensajes[] = 'Eliminado índice UNIQUE `' . $nombre . '`.';
        }
        if (!pmig_tiene_unique_programa_cod($conn)) {
            if (!$conn->query(
                'ALTER TABLE `adm_rol` ADD UNIQUE KEY `' . PMIG_UK_PROGRAMA_COD . '` (`id_programa`, `cod_rol`)'
            )) {
                throw new RuntimeException('No se pudo crear UNIQUE (id_programa, cod_rol): ' . $conn->error);
            }
            $mensajes[] = 'Creado UNIQUE `' . PMIG_UK_PROGRAMA_COD . '` (id_programa, cod_rol).';
        }
    } catch (Throwable $e) {
        $mensajes[] = 'ERROR: ' . $e->getMessage();

        return ['ok' => false, 'mensajes' => $mensajes];
    }

    return ['ok' => true, 'mensajes' => $mensajes];
}

// ─────────────────────────────────────────────────────────────────────────────
// Programa
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{id:int,nombre:string}|null */
function pmig_programa_por_nombre(mysqli $conn, string $nombre): ?array
{
    $st = $conn->prepare('SELECT id_programa AS id, nombre FROM amd_programas WHERE UPPER(TRIM(nombre)) = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    $nombreUp = strtoupper(trim($nombre));
    $st->bind_param('s', $nombreUp);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || (int) ($row['id'] ?? 0) <= 0) {
        return null;
    }

    return ['id' => (int) $row['id'], 'nombre' => trim((string) ($row['nombre'] ?? ''))];
}

function pmig_siguiente_id_programa(mysqli $conn): int
{
    $row = $conn->query('SELECT COALESCE(MAX(id_programa), 0) + 1 AS n FROM amd_programas')->fetch_assoc();

    return max(1, (int) ($row['n'] ?? 1));
}

/**
 * Renombra el programa al nombre canónico conservando su id_programa.
 *
 * @return array{ok:bool,mensaje:string}
 */
function pmig_renombrar_programa(mysqli $conn, int $idPrograma, bool $ejecutar): array
{
    if (!$ejecutar) {
        return [
            'ok' => true,
            'mensaje' => 'Simulación: se renombraría el programa id_programa=' . $idPrograma
                . ' a «' . PMIG_NOMBRE_PROGRAMA . '».',
        ];
    }

    $st = $conn->prepare('UPDATE amd_programas SET nombre = ? WHERE id_programa = ? LIMIT 1');
    if (!$st) {
        return ['ok' => false, 'mensaje' => 'No se pudo preparar el renombrado del programa.'];
    }
    $nom = PMIG_NOMBRE_PROGRAMA;
    $st->bind_param('si', $nom, $idPrograma);
    $ok = $st->execute();
    $err = $st->error;
    $st->close();
    if (!$ok) {
        return ['ok' => false, 'mensaje' => 'No se pudo renombrar el programa: ' . $err];
    }

    return [
        'ok' => true,
        'mensaje' => 'Programa id_programa=' . $idPrograma . ' renombrado a «' . PMIG_NOMBRE_PROGRAMA . '».',
    ];
}

/**
 * @return array{ok:bool,id:int,creado:bool,mensajes:list<string>}
 */
function pmig_asegurar_programa(mysqli $conn, bool $ejecutar): array
{
    $existente = pmig_programa_por_nombre($conn, PMIG_NOMBRE_PROGRAMA);
    if ($existente !== null) {
        // Si el nombre en BD difiere del canónico (mayúsculas/espacios), se normaliza.
        if ($existente['nombre'] !== PMIG_NOMBRE_PROGRAMA) {
            $ren = pmig_renombrar_programa($conn, $existente['id'], $ejecutar);
            if (!$ren['ok']) {
                return [
                    'ok' => false,
                    'id' => $existente['id'],
                    'creado' => false,
                    'mensajes' => [$ren['mensaje']],
                ];
            }

            return [
                'ok' => true,
                'id' => $existente['id'],
                'creado' => false,
                'mensajes' => [$ren['mensaje']],
            ];
        }

        return [
            'ok' => true,
            'id' => $existente['id'],
            'creado' => false,
            'mensajes' => ['Programa «' . PMIG_NOMBRE_PROGRAMA . '» ya existe con id_programa=' . $existente['id'] . '.'],
        ];
    }

    // Nombre previo: se reutiliza el mismo id_programa y se renombra al canónico.
    foreach (PMIG_NOMBRE_PROGRAMA_ALIAS as $alias) {
        $heredado = pmig_programa_por_nombre($conn, $alias);
        if ($heredado === null) {
            continue;
        }
        $ren = pmig_renombrar_programa($conn, $heredado['id'], $ejecutar);

        return [
            'ok' => $ren['ok'],
            'id' => $heredado['id'],
            'creado' => false,
            'mensajes' => [$ren['mensaje']],
        ];
    }

    $nuevoId = pmig_siguiente_id_programa($conn);
    if ($nuevoId > PMIG_MAX_ID_PROGRAMA) {
        return [
            'ok' => false,
            'id' => 0,
            'creado' => false,
            'mensajes' => [
                'El siguiente id_programa libre es ' . $nuevoId . ', pero adm_rol.id_programa es tinyint(4) y admite hasta '
                . PMIG_MAX_ID_PROGRAMA . '. Amplíe la columna antes de continuar.',
            ],
        ];
    }

    if (!$ejecutar) {
        return [
            'ok' => true,
            'id' => $nuevoId,
            'creado' => false,
            'mensajes' => ['Simulación: se crearía «' . PMIG_NOMBRE_PROGRAMA . '» con id_programa=' . $nuevoId . '.'],
        ];
    }

    $stmt = $conn->prepare('INSERT INTO amd_programas (id_programa, nombre) VALUES (?, ?)');
    if (!$stmt) {
        return ['ok' => false, 'id' => 0, 'creado' => false, 'mensajes' => ['No se pudo preparar el INSERT en amd_programas.']];
    }
    $nombrePrograma = PMIG_NOMBRE_PROGRAMA;
    $stmt->bind_param('is', $nuevoId, $nombrePrograma);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    if (!$ok) {
        return ['ok' => false, 'id' => 0, 'creado' => false, 'mensajes' => ['No se pudo crear el programa: ' . $err]];
    }

    return [
        'ok' => true,
        'id' => $nuevoId,
        'creado' => true,
        'mensajes' => ['Creado programa «' . PMIG_NOMBRE_PROGRAMA . '» con id_programa=' . $nuevoId . '.'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Menú (amd_dashboard_modulos)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Inserta o actualiza el menú Mortalidad PA (amd_dashboard_modulos).
 *
 * @return array{ok:bool,insertados:int,actualizados:int,total:int}
 */
function pmig_asegurar_menu(mysqli $conn, int $idPrograma, bool $ejecutar): array
{
    $idProgStr = (string) $idPrograma;
    $insertados = 0;
    $actualizados = 0;
    $total = count(pmig_menu_definicion());

    if (!$ejecutar) {
        return ['ok' => true, 'insertados' => $total, 'actualizados' => 0, 'total' => $total];
    }

    $sql = 'INSERT INTO amd_dashboard_modulos (id_programa, cod_mod, tipo, parent_cod, nom_mod, label_short, icono, url, orden)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              tipo = VALUES(tipo),
              parent_cod = VALUES(parent_cod),
              nom_mod = VALUES(nom_mod),
              label_short = VALUES(label_short),
              icono = VALUES(icono),
              url = VALUES(url),
              orden = VALUES(orden)';
    $st = $conn->prepare($sql);
    if (!$st) {
        return ['ok' => false, 'insertados' => 0, 'actualizados' => 0, 'total' => $total];
    }

    foreach (pmig_menu_definicion() as $row) {
        $codMod = (string) $row['cod_mod'];
        $tipo = (string) $row['tipo'];
        $parent = $row['parent_cod'];
        $nom = (string) $row['nom_mod'];
        $label = (string) $row['label_short'];
        $icono = (string) $row['icono'];
        $url = (string) $row['url'];
        $orden = (int) $row['orden'];
        $st->bind_param('ssssssssi', $idProgStr, $codMod, $tipo, $parent, $nom, $label, $icono, $url, $orden);
        if (!$st->execute()) {
            continue;
        }
        if ($st->affected_rows === 1) {
            $insertados++;
        } elseif ($st->affected_rows === 2) {
            $actualizados++;
        }
    }
    $st->close();

    return ['ok' => true, 'insertados' => $insertados, 'actualizados' => $actualizados, 'total' => $total];
}

/**
 * Sincroniza menú y permisos Mortalidad PA para roles SISTEMAS / GESTINAVES (y alias por nom_rol).
 * Idempotente: se puede ejecutar tras cada despliegue con módulos nuevos (p. ej. Despacho).
 *
 * @return array{ok:bool,acciones:list<string>,detalleRoles:list<array<string,mixed>>,error?:string}
 */
function pmig_sincronizar_acl_mortalidad_pa(mysqli $conn, bool $ejecutar): array
{
    $acciones = [];
    $detalleRoles = [];

    require_once __DIR__ . '/../../../core/lib/sip_menu_catalog_lib.php';

    $progSanidad = pmig_programa_por_nombre($conn, PMIG_NOMBRE_PROGRAMA_SANIDAD);
    if ($progSanidad === null) {
        return ['ok' => false, 'acciones' => [], 'detalleRoles' => [], 'error' => 'No se encontró el programa Sanidad.'];
    }
    $idProgSanidad = (int) $progSanidad['id'];

    $prog = pmig_asegurar_programa($conn, $ejecutar);
    foreach ($prog['mensajes'] as $m) {
        $acciones[] = $m;
    }
    if (!$prog['ok']) {
        return ['ok' => false, 'acciones' => $acciones, 'detalleRoles' => [], 'error' => 'Programa PA no disponible.'];
    }
    $idProgPa = (int) $prog['id'];

    $menu = pmig_asegurar_menu($conn, $idProgPa, $ejecutar);
    $acciones[] = 'Menú PA: ' . $menu['insertados'] . ' insertados, ' . ($menu['actualizados'] ?? 0)
        . ' actualizados (total definición ' . ($menu['total'] ?? 0) . ').';

    if ($ejecutar) {
        $seedSan = sip_menu_catalog_seed_modulos($conn, $idProgSanidad);
        $acciones[] = 'Menú Sanidad (catálogo canónico): ' . ($seedSan['message'] ?? 'ok');
    } else {
        $acciones[] = 'Simulación: se ejecutaría sip_menu_catalog_seed_modulos en Sanidad.';
    }

    $codModsPa = pmig_menu_cod_mods();
    $codModsSanDesp = pmig_cod_mods_sanidad_despacho();
    $roles = pmig_roles_objetivo_resueltos($conn, $idProgPa, $idProgSanidad);
    if ($roles === []) {
        $acciones[] = 'AVISO: no se encontraron roles objetivo (cod_rol '
            . implode(', ', PMIG_ROLES_COD_OBJETIVO) . ' o nom_rol ' . implode(', ', PMIG_ROLES_NOMBRES) . ').';
    }

    foreach ($roles as $meta) {
        $codRol = (string) $meta['cod_rol'];
        $nomRol = (string) ($meta['nom_rol'] !== '' ? $meta['nom_rol'] : $codRol);

        $origenSan = null;
        if ((int) ($meta['id_sanidad'] ?? 0) > 0) {
            $origenSan = [
                'id' => (int) $meta['id_sanidad'],
                'cod_rol' => $codRol,
                'nom_rol' => $nomRol,
            ];
        } else {
            $origenSan = pmig_rol_por_cod($conn, $idProgSanidad, $codRol)
                ?? pmig_rol_por_nombre($conn, $idProgSanidad, $nomRol);
        }

        $modsOrigen = [];
        if ($origenSan !== null) {
            $modsOrigen = pmig_permisos_mortalidad_rol($conn, (int) $origenSan['id'], $idProgSanidad);
        }

        $r = pmig_asegurar_rol($conn, $idProgPa, $codRol, $nomRol, $ejecutar);
        $acciones[] = $r['mensaje'];
        if (!$r['ok']) {
            continue;
        }
        $idRolPa = (int) $r['id'];

        $permPa = pmig_asegurar_permisos($conn, $idRolPa, $idProgPa, $codModsPa, $ejecutar);
        $acciones[] = 'Permisos PA rol ' . $codRol . ': ' . $permPa['insertados'] . ' nuevos, '
            . $permPa['existentes'] . ' ya existían.';

        if ($origenSan !== null) {
            $permSan = pmig_asegurar_permisos(
                $conn,
                (int) $origenSan['id'],
                $idProgSanidad,
                $codModsSanDesp,
                $ejecutar
            );
            $acciones[] = 'Permisos Sanidad (Despacho) rol ' . $codRol . ': ' . $permSan['insertados']
                . ' nuevos, ' . $permSan['existentes'] . ' ya existían.';
        }

        $detalleRoles[] = [
            'cod_rol' => $codRol,
            'nom_rol' => $nomRol,
            'id_rol_pa' => $idRolPa,
            'mods_origen_sanidad' => $modsOrigen,
        ];
    }

    return ['ok' => true, 'acciones' => $acciones, 'detalleRoles' => $detalleRoles];
}

// ─────────────────────────────────────────────────────────────────────────────
// Roles (adm_rol)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Busca el rol por NOMBRE (fuente de verdad) dentro de un programa.
 * Devuelve el cod_rol real almacenado en BD, que puede variar entre entornos.
 *
 * @return array{id:int,cod_rol:string,nom_rol:string}|null
 */
function pmig_rol_por_nombre(mysqli $conn, int $idPrograma, string $nomRol): ?array
{
    $st = $conn->prepare(
        'SELECT id, cod_rol, nom_rol FROM adm_rol
         WHERE UPPER(TRIM(nom_rol)) = UPPER(TRIM(?)) AND CAST(id_programa AS UNSIGNED) = ?
         ORDER BY activo DESC, id ASC LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('si', $nomRol, $idPrograma);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'cod_rol' => trim((string) ($row['cod_rol'] ?? '')),
        'nom_rol' => trim((string) ($row['nom_rol'] ?? '')),
    ];
}

/** @return array{id:int,cod_rol:string,nom_rol:string}|null */
function pmig_rol_por_cod(mysqli $conn, int $idPrograma, string $codRol): ?array
{
    $st = $conn->prepare(
        'SELECT id, cod_rol, nom_rol FROM adm_rol
         WHERE UPPER(TRIM(cod_rol)) = ? AND CAST(id_programa AS UNSIGNED) = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $cod = strtoupper(trim($codRol));
    $st->bind_param('si', $cod, $idPrograma);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'cod_rol' => trim((string) ($row['cod_rol'] ?? '')),
        'nom_rol' => trim((string) ($row['nom_rol'] ?? '')),
    ];
}

/**
 * @return array{ok:bool,id:int,creado:bool,mensaje:string}
 */
function pmig_asegurar_rol(mysqli $conn, int $idPrograma, string $codRol, string $nomRol, bool $ejecutar): array
{
    $existente = pmig_rol_por_cod($conn, $idPrograma, $codRol);
    if ($existente !== null) {
        return [
            'ok' => true,
            'id' => $existente['id'],
            'creado' => false,
            'mensaje' => 'Rol ' . $codRol . ' («' . $existente['nom_rol'] . '») ya existe en el programa (id=' . $existente['id'] . ').',
        ];
    }

    if (!$ejecutar) {
        return ['ok' => true, 'id' => 0, 'creado' => false, 'mensaje' => 'Simulación: se crearía el rol ' . $codRol . ' («' . $nomRol . '»).'];
    }

    $descripcion = 'Clonado desde Sanidad (bloque Mortalidad)';
    $st = $conn->prepare(
        'INSERT INTO adm_rol (cod_rol, nom_rol, activo, descripcion, id_programa, fecha_update)
         VALUES (?, ?, 1, ?, ?, NOW())'
    );
    if (!$st) {
        return ['ok' => false, 'id' => 0, 'creado' => false, 'mensaje' => 'No se pudo preparar el INSERT en adm_rol.'];
    }
    $st->bind_param('sssi', $codRol, $nomRol, $descripcion, $idPrograma);
    $ok = $st->execute();
    $err = $st->error;
    $nuevoId = (int) $conn->insert_id;
    $st->close();
    if (!$ok) {
        return ['ok' => false, 'id' => 0, 'creado' => false, 'mensaje' => 'No se pudo crear el rol ' . $codRol . ': ' . $err];
    }

    return ['ok' => true, 'id' => $nuevoId, 'creado' => true, 'mensaje' => 'Creado rol ' . $codRol . ' («' . $nomRol . '») id=' . $nuevoId . '.'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Permisos (adm_rol_progr_modulo)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * cod_mod del bloque Mortalidad que el rol origen tiene realmente en Sanidad.
 *
 * @return list<string>
 */
function pmig_permisos_mortalidad_rol(mysqli $conn, int $idRol, int $idProgSanidad): array
{
    $mods = PMIG_SANIDAD_COD_MODS_MORT;
    $ph = implode(',', array_fill(0, count($mods), '?'));
    $sql = "SELECT cod_mod FROM adm_rol_progr_modulo
            WHERE id_rol = ? AND CAST(id_programa AS UNSIGNED) = ? AND cod_mod IN ({$ph})";
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $types = 'ii' . str_repeat('s', count($mods));
    $bind = array_merge([$idRol, $idProgSanidad], $mods);
    $args = [&$types];
    for ($i = 0; $i < count($bind); $i++) {
        $args[] = &$bind[$i];
    }
    $ref = new ReflectionMethod('mysqli_stmt', 'bind_param');
    $ref->invokeArgs($st, $args);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $c = trim((string) ($row['cod_mod'] ?? ''));
        if ($c !== '') {
            $out[] = $c;
        }
    }
    $st->close();

    return array_values(array_unique($out));
}

/**
 * @param list<string> $codMods
 * @return array{insertados:int,existentes:int}
 */
function pmig_asegurar_permisos(mysqli $conn, int $idRol, int $idPrograma, array $codMods, bool $ejecutar): array
{
    $insertados = 0;
    $existentes = 0;
    if ($idRol <= 0) {
        return ['insertados' => count($codMods), 'existentes' => 0];
    }

    $stChk = $conn->prepare('SELECT 1 FROM adm_rol_progr_modulo WHERE id_rol = ? AND id_programa = ? AND cod_mod = ? LIMIT 1');
    $stIns = $ejecutar
        ? $conn->prepare(
            'INSERT IGNORE INTO adm_rol_progr_modulo (id_rol, id_programa, cod_mod, fecha_actualizacion, user_update)
             VALUES (?, ?, ?, NOW(), ?)'
        )
        : null;
    $userUpdate = 'migracion_sanidad_pa';

    foreach ($codMods as $codMod) {
        $codMod = (string) $codMod;
        $existe = false;
        if ($stChk) {
            $stChk->bind_param('iis', $idRol, $idPrograma, $codMod);
            $stChk->execute();
            $existe = $stChk->get_result()->fetch_assoc() !== null;
        }
        if ($existe) {
            $existentes++;
            continue;
        }
        if (!$ejecutar || !$stIns) {
            $insertados++;
            continue;
        }
        $stIns->bind_param('iiss', $idRol, $idPrograma, $codMod, $userUpdate);
        if ($stIns->execute()) {
            $insertados++;
        }
    }

    if ($stChk) {
        $stChk->close();
    }
    if ($stIns) {
        $stIns->close();
    }

    return ['insertados' => $insertados, 'existentes' => $existentes];
}

// ─────────────────────────────────────────────────────────────────────────────
// Usuarios (adm_usuario_rol)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Usuarios activos que tienen el cod_rol indicado en el programa de Sanidad.
 *
 * @return list<array{codigo:string,nombre:string,dni:string}>
 */
function pmig_usuarios_con_rol(mysqli $conn, int $idProgSanidad, string $codRol): array
{
    $hasDni = pmig_usuario_tiene_libtri($conn);
    $colDni = $hasDni ? 'u.libtri' : "''";
    $sql = 'SELECT DISTINCT u.codigo, u.nombre, ' . $colDni . ' AS dni
            FROM adm_usuario_rol aur
            INNER JOIN usuario u ON u.codigo = aur.codigo AND u.estado = ?
            INNER JOIN adm_rol r ON UPPER(TRIM(r.cod_rol)) = UPPER(TRIM(aur.cod_rol))
                               AND CAST(r.id_programa AS UNSIGNED) = ?
            WHERE UPPER(TRIM(aur.cod_rol)) = ?
            ORDER BY u.nombre ASC';
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $estado = 'A';
    $cod = strtoupper(trim($codRol));
    $st->bind_param('sis', $estado, $idProgSanidad, $cod);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[] = [
            'codigo' => trim((string) ($row['codigo'] ?? '')),
            'nombre' => trim((string) ($row['nombre'] ?? '')),
            'dni' => trim((string) ($row['dni'] ?? '')),
        ];
    }
    $st->close();

    return $out;
}

/**
 * Usuarios que quedan efectivamente con cada rol migrado en el programa destino,
 * leídos desde BD tras la migración (DNI, nombre, rol y id_programa).
 *
 * @param list<string> $codRoles cod_rol dinámicos resueltos para el programa destino
 * @return list<array{dni:string,nombre:string,codigo:string,rol:string,id_rol:int,id_programa:int,programa:string}>
 */
function pmig_listado_migrados(mysqli $conn, int $idProgPa, array $codRoles): array
{
    if ($codRoles === []) {
        return [];
    }
    $hasDni = pmig_usuario_tiene_libtri($conn);
    $colDni = $hasDni ? 'u.libtri' : "''";
    $ph = implode(',', array_fill(0, count($codRoles), '?'));

    $sql = 'SELECT DISTINCT ' . $colDni . ' AS dni,
                   u.nombre,
                   u.codigo,
                   r.cod_rol,
                   COALESCE(NULLIF(TRIM(r.nom_rol), \'\'), r.cod_rol) AS nom_rol,
                   r.id AS id_rol,
                   r.id_programa,
                   COALESCE(NULLIF(TRIM(p.nombre), \'\'), \'\') AS programa
            FROM adm_usuario_rol aur
            INNER JOIN adm_rol r ON UPPER(TRIM(r.cod_rol)) = UPPER(TRIM(aur.cod_rol))
                               AND CAST(r.id_programa AS UNSIGNED) = ?
            INNER JOIN usuario u ON u.codigo = aur.codigo
            LEFT JOIN amd_programas p ON p.id_programa = CAST(r.id_programa AS UNSIGNED)
            WHERE UPPER(TRIM(r.cod_rol)) IN (' . $ph . ')
            ORDER BY nom_rol ASC, u.nombre ASC';

    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $upper = array_map(static function ($c) {
        return strtoupper(trim((string) $c));
    }, $codRoles);
    $types = 'i' . str_repeat('s', count($upper));
    $bind = array_merge([$idProgPa], $upper);
    $args = [&$types];
    for ($i = 0; $i < count($bind); $i++) {
        $args[] = &$bind[$i];
    }
    $ref = new ReflectionMethod('mysqli_stmt', 'bind_param');
    $ref->invokeArgs($st, $args);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $out[] = [
            'dni' => trim((string) ($row['dni'] ?? '')),
            'nombre' => trim((string) ($row['nombre'] ?? '')),
            'codigo' => trim((string) ($row['codigo'] ?? '')),
            'cod_rol' => trim((string) ($row['cod_rol'] ?? '')),
            'rol' => trim((string) ($row['nom_rol'] ?? '')),
            'id_rol' => (int) ($row['id_rol'] ?? 0),
            'id_programa' => (int) ($row['id_programa'] ?? $idProgPa),
            'programa' => trim((string) ($row['programa'] ?? '')),
        ];
    }
    $st->close();

    return $out;
}

/**
 * La tabla `usuario` puede no tener columna libtri en algún entorno.
 */
function pmig_usuario_tiene_libtri(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW COLUMNS FROM `usuario` LIKE 'libtri'");
    $cache = $r && $r->num_rows > 0;

    return $cache;
}

/**
 * Listado equivalente al de BD pero sin tocar la base (modo simulación).
 *
 * @param list<array<string,mixed>> $detalleRoles
 * @return list<array{dni:string,nombre:string,codigo:string,cod_rol:string,rol:string,id_rol:int,id_programa:int,programa:string}>
 */
function pmig_listado_simulado(array $detalleRoles, int $idProgPa, string $nomPrograma): array
{
    $out = [];
    foreach ($detalleRoles as $d) {
        foreach (($d['usuarios'] ?? []) as $u) {
            $out[] = [
                'dni' => trim((string) ($u['dni'] ?? '')),
                'nombre' => trim((string) ($u['nombre'] ?? '')),
                'codigo' => trim((string) ($u['codigo'] ?? '')),
                'cod_rol' => (string) ($d['cod_rol'] ?? ''),
                'rol' => (string) ($d['nom_rol'] ?? ''),
                'id_rol' => (int) ($d['id_rol_pa'] ?? 0),
                'id_programa' => $idProgPa,
                'programa' => $nomPrograma,
            ];
        }
    }
    usort($out, static function (array $a, array $b): int {
        $c = strcasecmp($a['rol'], $b['rol']);

        return $c !== 0 ? $c : strcasecmp($a['nombre'], $b['nombre']);
    });

    return $out;
}

/**
 * @param list<string> $codigos
 * @return array{nuevos:int,existentes:int,errores:list<string>}
 */
function pmig_asignar_usuarios(mysqli $conn, string $codRol, array $codigos, bool $ejecutar): array
{
    $nuevos = 0;
    $existentes = 0;
    $errores = [];

    $stChk = $conn->prepare('SELECT 1 FROM adm_usuario_rol WHERE codigo = ? AND cod_rol = ? LIMIT 1');
    $stIns = $ejecutar
        ? $conn->prepare('INSERT IGNORE INTO adm_usuario_rol (codigo, cod_rol) VALUES (?, ?)')
        : null;

    foreach ($codigos as $codigo) {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            continue;
        }
        $existe = false;
        if ($stChk) {
            $stChk->bind_param('ss', $codigo, $codRol);
            $stChk->execute();
            $existe = $stChk->get_result()->fetch_assoc() !== null;
        }
        if ($existe) {
            $existentes++;
            continue;
        }
        if (!$ejecutar || !$stIns) {
            $nuevos++;
            continue;
        }
        $stIns->bind_param('ss', $codigo, $codRol);
        if ($stIns->execute()) {
            $nuevos++;
        } else {
            $errores[] = $codigo . ': ' . $stIns->error;
        }
    }

    if ($stChk) {
        $stChk->close();
    }
    if ($stIns) {
        $stIns->close();
    }

    return ['nuevos' => $nuevos, 'existentes' => $existentes, 'errores' => $errores];
}

// ─────────────────────────────────────────────────────────────────────────────
// Ejecución (solo cuando este archivo es el script principal, no al require)
// ─────────────────────────────────────────────────────────────────────────────

$pmigEsScriptPrincipal = false;
if ($esCli) {
    $pmigEsScriptPrincipal = realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)
        || basename((string) ($argv[0] ?? '')) === basename(__FILE__);
} else {
    $pmigEsScriptPrincipal = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__);
}

if (!$pmigEsScriptPrincipal) {
    return;
}

$acciones = [];
$detalleRoles = [];
$codRolesMigrados = [];
$listadoMigrados = [];
$errorFatal = '';

$conn = conectar_joya_mysqli();
if (!$conn) {
    $errorFatal = 'No se pudo conectar a la base de datos.';
} else {
    try {
        $progSanidad = pmig_programa_por_nombre($conn, PMIG_NOMBRE_PROGRAMA_SANIDAD);
        if ($progSanidad === null) {
            throw new RuntimeException('No se encontró el programa «' . PMIG_NOMBRE_PROGRAMA_SANIDAD . '» en amd_programas.');
        }
        $idProgSanidad = $progSanidad['id'];
        $acciones[] = 'Programa origen: «' . $progSanidad['nombre'] . '» id_programa=' . $idProgSanidad . '.';

        // Paso 1: índice UNIQUE (id_programa, cod_rol) para reutilizar los cod_rol de Sanidad.
        $uk = pmig_asegurar_unique_programa_cod($conn, $ejecutar);
        foreach ($uk['mensajes'] as $m) {
            $acciones[] = $m;
        }
        if (!$uk['ok']) {
            throw new RuntimeException('Prerrequisito de adm_rol no cumplido.');
        }

        // Paso 2: programa destino.
        $prog = pmig_asegurar_programa($conn, $ejecutar);
        foreach ($prog['mensajes'] as $m) {
            $acciones[] = $m;
        }
        if (!$prog['ok']) {
            throw new RuntimeException('No se pudo resolver el programa destino.');
        }
        $idProgPa = (int) $prog['id'];
        if ($idProgPa <= 0) {
            throw new RuntimeException('id_programa destino inválido.');
        }

        // Paso 3: menú Mortalidad del proyecto PA.
        $menu = pmig_asegurar_menu($conn, $idProgPa, $ejecutar);
        $acciones[] = 'Menú Mortalidad PA: ' . $menu['insertados'] . ' insertados, '
            . ($menu['actualizados'] ?? 0) . ' actualizados.';

        // Pasos 4-6: roles SISTEMAS / GESTINAVES (cod_rol) y alias por nom_rol.
        $codModsPa = pmig_menu_cod_mods();
        $rolesObjetivo = pmig_roles_objetivo_resueltos($conn, $idProgPa, $idProgSanidad);

        foreach ($rolesObjetivo as $metaRol) {
            $codRol = (string) $metaRol['cod_rol'];
            $nomRol = (string) ($metaRol['nom_rol'] !== '' ? $metaRol['nom_rol'] : $codRol);

            $origen = null;
            if ((int) ($metaRol['id_sanidad'] ?? 0) > 0) {
                $origen = [
                    'id' => (int) $metaRol['id_sanidad'],
                    'cod_rol' => $codRol,
                    'nom_rol' => $nomRol,
                ];
            } else {
                $origen = pmig_rol_por_cod($conn, $idProgSanidad, $codRol)
                    ?? pmig_rol_por_nombre($conn, $idProgSanidad, $nomRol);
            }
            if ($origen === null) {
                $acciones[] = 'AVISO: rol cod_rol=' . $codRol . ' no encontrado en Sanidad; se omite migración de usuarios.';
                continue;
            }
            $acciones[] = 'Rol «' . $nomRol . '» (cod_rol=' . $codRol . ') id Sanidad=' . (int) $origen['id'] . '.';

            $modsOrigen = pmig_permisos_mortalidad_rol($conn, (int) $origen['id'], $idProgSanidad);
            $modsOrigenPa = array_map('pmig_map_cod_mod', $modsOrigen);

            $r = pmig_asegurar_rol($conn, $idProgPa, $codRol, $nomRol, $ejecutar);
            $acciones[] = $r['mensaje'];
            if (!$r['ok']) {
                throw new RuntimeException($r['mensaje']);
            }
            $idRolPa = (int) $r['id'];

            $perm = pmig_asegurar_permisos($conn, $idRolPa, $idProgPa, $codModsPa, $ejecutar);
            $acciones[] = 'Permisos PA rol ' . $codRol . ': ' . $perm['insertados'] . ' por otorgar/otorgados, '
                . $perm['existentes'] . ' ya existentes.';

            $permSanDesp = pmig_asegurar_permisos(
                $conn,
                (int) $origen['id'],
                $idProgSanidad,
                pmig_cod_mods_sanidad_despacho(),
                $ejecutar
            );
            $acciones[] = 'Permisos Sanidad (Despacho) rol ' . $codRol . ': ' . $permSanDesp['insertados']
                . ' nuevos, ' . $permSanDesp['existentes'] . ' ya existían.';

            $usuarios = pmig_usuarios_con_rol($conn, $idProgSanidad, $codRol);
            $asig = pmig_asignar_usuarios($conn, $codRol, array_column($usuarios, 'codigo'), $ejecutar);
            $acciones[] = 'Usuarios rol ' . $codRol . ': ' . count($usuarios) . ' encontrados, '
                . $asig['nuevos'] . ' por asignar/asignados, ' . $asig['existentes'] . ' ya asignados.';
            foreach ($asig['errores'] as $e) {
                $acciones[] = 'Aviso al asignar: ' . $e;
            }

            $detalleRoles[] = [
                'cod_rol' => $codRol,
                'nom_rol' => $nomRol,
                'id_rol_sanidad' => (int) $origen['id'],
                'id_rol_pa' => $idRolPa,
                'mods_origen' => $modsOrigen,
                'mods_origen_pa' => $modsOrigenPa,
                'usuarios' => $usuarios,
                'asignados_nuevos' => $asig['nuevos'],
                'asignados_existentes' => $asig['existentes'],
            ];

            $codRolesMigrados[] = $codRol;
        }

        // Listado final leído desde BD: DNI, nombre, rol, id_programa y programa.
        $progNom = pmig_programa_por_nombre($conn, PMIG_NOMBRE_PROGRAMA);
        $nomProgPa = $progNom !== null ? $progNom['nombre'] : PMIG_NOMBRE_PROGRAMA;
        if (!$ejecutar) {
            $listadoMigrados = pmig_listado_simulado($detalleRoles, $idProgPa, $nomProgPa);
        } else {
            $listadoMigrados = pmig_listado_migrados($conn, $idProgPa, $codRolesMigrados);
        }

        $acciones[] = 'Programa destino id_programa=' . $idProgPa . '.';
        $acciones[] = 'Usuarios con acceso al programa destino: ' . count($listadoMigrados) . '.';
    } catch (Throwable $e) {
        $errorFatal = $e->getMessage();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Salida
// ─────────────────────────────────────────────────────────────────────────────

if ($esCli) {
    echo ($ejecutar ? "EJECUCIÓN" : "SIMULACIÓN") . " migración permisos Sanidad → " . PMIG_NOMBRE_PROGRAMA . "\n";
    echo str_repeat('-', 78) . "\n";
    foreach ($acciones as $a) {
        echo '  · ' . $a . "\n";
    }
    foreach ($detalleRoles as $d) {
        echo "\nRol " . $d['cod_rol'] . " («" . $d['nom_rol'] . "»)\n";
        echo '  Sanidad id_rol=' . $d['id_rol_sanidad'] . ' → PA ' . pmig_txt_id_rol_pa((int) $d['id_rol_pa'], $ejecutar) . "\n";
        echo '  Sanidad permiso mortalidad: ' . (implode(', ', $d['mods_origen']) ?: '(ninguno)') . "\n";
        echo '  Mapeado a PA: ' . (implode(', ', $d['mods_origen_pa']) ?: '(ninguno)') . "\n";
    }

    if ($listadoMigrados !== []) {
        echo "\n" . str_repeat('-', 126) . "\n";
        echo "DNI        | NOMBRE                            | COD_ROL      | ROL                        | ID_PROGRAMA | PROGRAMA\n";
        echo str_repeat('-', 126) . "\n";
        foreach ($listadoMigrados as $f) {
            printf(
                "%-10s | %-33s | %-12s | %-26s | %-11d | %s\n",
                $f['dni'] !== '' ? $f['dni'] : '(sin DNI)',
                substr($f['nombre'], 0, 33),
                substr($f['cod_rol'], 0, 12),
                substr($f['rol'], 0, 26),
                (int) $f['id_programa'],
                $f['programa'] !== '' ? $f['programa'] : '(sin nombre)'
            );
        }
        echo str_repeat('-', 126) . "\n";
        echo 'Total: ' . count($listadoMigrados) . " usuario(s).\n";
    }

    if ($errorFatal !== '') {
        echo "\nERROR: " . $errorFatal . "\n";
        exit(1);
    }
    exit(0);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migración permisos Sanidad → ProduccionAves</title>
</head>
<body style="font-family:system-ui,sans-serif;padding:1.25rem;max-width:1000px;margin:0 auto;line-height:1.5;">
    <h1>Migración de permisos: Sanidad → ProduccionAves</h1>
    <p style="color:#64748b;">
        <?= $ejecutar ? 'Modo <strong>ejecución</strong>.' : 'Modo <strong>simulación</strong> (sin cambios en BD).' ?>
        Se crea un <code>id_programa</code> nuevo y se migran solo los permisos del
        bloque <strong>Mortalidad</strong> de los roles con
        <code>cod_rol</code> <?= pmig_esc(implode(', ', PMIG_ROLES_COD_OBJETIVO)) ?>
        (o <code>nom_rol</code> <?= pmig_esc(implode(' / ', PMIG_ROLES_NOMBRES)) ?>).
    </p>
    <p style="color:#64748b;">
        Para actualizar menú y permisos tras un despliegue (p. ej. módulo Despacho), use
        <a href="sync_acl_mortalidad_pa.php">sync_acl_mortalidad_pa.php</a>.
    </p>
    <?php if ($errorFatal !== ''): ?>
        <p style="padding:.75rem 1rem;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">
            <strong>Error:</strong> <?= pmig_esc($errorFatal) ?>
        </p>
    <?php endif; ?>

    <h2>Resumen</h2>
    <ul>
        <?php foreach ($acciones as $a): ?>
            <li><?= pmig_esc($a) ?></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($detalleRoles !== []): ?>
        <h2>Detalle por rol</h2>
        <?php foreach ($detalleRoles as $d): ?>
            <h3>
                <code><?= pmig_esc($d['cod_rol']) ?></code> — <?= pmig_esc($d['nom_rol']) ?>
                <small style="color:#64748b;">
                    (Sanidad id_rol=<?= (int) $d['id_rol_sanidad'] ?> → PA <?= pmig_esc(pmig_txt_id_rol_pa((int) $d['id_rol_pa'], $ejecutar)) ?>)
                </small>
            </h3>
            <p style="margin:.25rem 0;">
                <strong>Permisos mortalidad en Sanidad:</strong>
                <?= $d['mods_origen'] !== [] ? pmig_esc(implode(', ', $d['mods_origen'])) : '(ninguno)' ?>
            </p>
            <p style="margin:.25rem 0;">
                <strong>Mapeados al menú PA:</strong>
                <?= $d['mods_origen_pa'] !== [] ? pmig_esc(implode(', ', $d['mods_origen_pa'])) : '(ninguno)' ?>
            </p>
            <p style="margin:.25rem 0;">
                <strong>Usuarios (<?= count($d['usuarios']) ?>):</strong>
                <?= (int) $d['asignados_nuevos'] ?> por asignar/asignados,
                <?= (int) $d['asignados_existentes'] ?> ya asignados.
            </p>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($listadoMigrados !== []): ?>
        <h2>Usuarios con acceso a <?= pmig_esc(PMIG_NOMBRE_PROGRAMA) ?></h2>
        <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:.85rem;width:100%;">
            <thead style="background:#f1f5f9;">
                <tr>
                    <th align="left">DNI</th>
                    <th align="left">Nombre</th>
                    <th align="left">cod_rol</th>
                    <th align="left">Rol</th>
                    <th align="left">id_programa</th>
                    <th align="left">Programa</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($listadoMigrados as $f): ?>
                <tr>
                    <td><?= $f['dni'] !== '' ? pmig_esc($f['dni']) : '<span style="color:#94a3b8;">(sin DNI)</span>' ?></td>
                    <td><?= pmig_esc($f['nombre']) ?></td>
                    <td><code><?= $f['cod_rol'] !== '' ? pmig_esc($f['cod_rol']) : '<span style="color:#94a3b8;">(sin código)</span>' ?></code></td>
                    <td><?= pmig_esc($f['rol']) ?></td>
                    <td><?= (int) $f['id_programa'] ?></td>
                    <td><?= $f['programa'] !== '' ? pmig_esc($f['programa']) : '<span style="color:#94a3b8;">(sin nombre)</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot style="background:#f8fafc;">
                <tr>
                    <td colspan="6"><strong>Total: <?= count($listadoMigrados) ?> usuario(s).</strong></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if (!$ejecutar && $errorFatal === ''): ?>
        <p style="margin-top:1.25rem;">
            <a href="?ejecutar=1"
               style="display:inline-block;padding:.6rem 1.1rem;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;"
               onclick="return confirm('¿Ejecutar la migración en la base de datos?');">Ejecutar en BD</a>
        </p>
        <p style="color:#64748b;font-size:.85rem;">
            Requisito previo: <code>adm_rol</code> debe permitir repetir <code>cod_rol</code> por programa
            (este script lo aplica automáticamente: quita <code>UNIQUE (cod_rol)</code> y crea
            <code>UNIQUE (id_programa, cod_rol)</code>).
        </p>
    <?php endif; ?>

    <p style="margin-top:1.25rem;"><a href="../../../index.php">Volver al inicio</a></p>
</body>
</html>
