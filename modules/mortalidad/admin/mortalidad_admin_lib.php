<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../core/lib/sip_acl_sanidad.php';
require_once __DIR__ . '/../../../core/lib/roles_permisos/roles_permisos_lib.php';

function mort_admin_json_error(string $message, int $code = 400): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    $json = json_encode(
        ['success' => false, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo $json !== false ? $json : '{"success":false,"message":"Error al generar JSON."}';
    exit;
}

function mort_admin_json_ok(array $data = []): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $json = json_encode(
        array_merge(['success' => true], $data),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        mort_admin_json_error('Error al generar JSON.', 500);
    }
    echo $json;
    exit;
}

function mort_admin_normalizar_codigo(string $codigo): string
{
    return strtoupper(substr(trim($codigo), 0, 10));
}

/**
 * @return list<array{key: string, label: string}>
 */
function mort_admin_tipos_mortalidad(): array
{
    return [
        ['key' => 'incubacion', 'label' => 'Planta de incubación'],
        ['key' => 'transporte', 'label' => 'Transporte'],
        ['key' => 'produccion', 'label' => 'Producción'],
        ['key' => 'despacho', 'label' => 'Despacho'],
    ];
}

/**
 * Lista completa de permisos de la app (san_mortalidad_acceso_app).
 *
 * @return list<array{key: string, label: string, section: string}>
 */
function mort_admin_permisos_app_list(): array
{
    return [
        ['key' => 'verDashboard',        'label' => 'Dashboard',              'section' => 'drawer'],
        ['key' => 'verRegMortalidad',    'label' => 'Registro Mortalidad',    'section' => 'drawer'],
        ['key' => 'verRegProduccion',    'label' => 'Producción',             'section' => 'registro'],
        ['key' => 'verRegDespacho',      'label' => 'Despacho',               'section' => 'registro'],
        ['key' => 'verRegPI',            'label' => 'Planta Incubación',      'section' => 'registro'],
        ['key' => 'verRegTransporte',    'label' => 'Transporte',             'section' => 'registro'],
        ['key' => 'verReportes',         'label' => 'Listado',                'section' => 'drawer'],
        ['key' => 'verLiquidacion',      'label' => 'Liquidación',            'section' => 'drawer'],
        ['key' => 'verAuditoria',        'label' => 'Auditoría',              'section' => 'drawer'],
        ['key' => 'verLiquidacionTodos', 'label' => 'Ver todas las liquidaciones', 'section' => 'liquidacion'],
    ];
}

/**
 * @return array<string, bool> Todos los permisos en false
 */
function mort_admin_permisos_defecto_fila(): array
{
    $def = [];
    foreach (mort_admin_permisos_app_list() as $p) {
        $def[$p['key']] = false;
    }
    return $def;
}

/**
 * Lee todos los permisos de todos los usuarios desde san_mortalidad_acceso_app.
 *
 * @return array<string, array<string, bool>>
 */
function mort_admin_permisos_map_all(mysqli $conn): array
{
    $map = [];
    $r = @$conn->query(
        'SELECT usuario, permiso, valor FROM san_mortalidad_acceso_app'
    );
    if (!$r) {
        return $map;
    }

    while ($row = $r->fetch_assoc()) {
        $usuario = mort_admin_normalizar_codigo((string) ($row['usuario'] ?? ''));
        if ($usuario === '') {
            continue;
        }
        if (!isset($map[$usuario])) {
            $map[$usuario] = mort_admin_permisos_defecto_fila();
        }
        $map[$usuario][$row['permiso']] = ((int) ($row['valor'] ?? 0)) === 1;
    }

    return $map;
}

/**
 * @return array<string, bool> Todos los permisos en false (compatibilidad).
 */
function mort_admin_visibilidad_defecto_fila(): array
{
    return mort_admin_permisos_defecto_fila();
}

/**
 * Lee permisos de un usuario desde san_mortalidad_acceso_app.
 *
 * @return array<string, bool>
 */
function mort_admin_visibilidad_get(mysqli $conn, string $codigoUsuario): array
{
    $def = mort_admin_permisos_defecto_fila();
    $codigo = mort_admin_normalizar_codigo($codigoUsuario);
    if ($codigo === '') {
        return $def;
    }

    $st = $conn->prepare(
        'SELECT permiso, valor FROM san_mortalidad_acceso_app WHERE usuario = ?'
    );
    if (!$st) {
        return $def;
    }
    $st->bind_param('s', $codigo);
    $st->execute();
    $res = $st->get_result();
    $st->close();

    $permisos = $def;
    while ($row = $res->fetch_assoc()) {
        if (array_key_exists($row['permiso'], $permisos)) {
            $permisos[$row['permiso']] = ((int) ($row['valor'] ?? 0)) === 1;
        }
    }

    return $permisos;
}

/**
 * @return list<array{codigo: string, nombre: string, visibilidad: array<string, bool>}>
 */
function mort_admin_usuarios_directorio(mysqli $conn): array
{
    $visMap = mort_admin_permisos_map_all($conn);
    $defVis = mort_admin_permisos_defecto_fila();
    $rows = [];
    foreach (rp_usuarios_list($conn) as $u) {
        $codigo = mort_admin_normalizar_codigo((string) ($u['codigo'] ?? ''));
        if ($codigo === '') {
            continue;
        }
        $rows[] = [
            'codigo' => $codigo,
            'nombre' => trim((string) ($u['nombre'] ?? '')),
            'visibilidad' => $visMap[$codigo] ?? $defVis,
        ];
    }

    return $rows;
}

/**
 * Búsqueda ligera para Select2 (no carga todo el directorio).
 *
 * @return list<array{id: string, text: string, codigo: string, nombre: string}>
 */
function mort_admin_usuarios_buscar(mysqli $conn, string $q, int $limit = 30): array
{
    $limit = max(1, min(50, $limit));
    $estado = 'A';
    $q = trim($q);

    if ($q === '') {
        $st = $conn->prepare(
            'SELECT codigo, nombre FROM usuario WHERE estado = ? ORDER BY nombre ASC LIMIT ?'
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('si', $estado, $limit);
    } else {
        $like = '%' . $q . '%';
        $st = $conn->prepare(
            'SELECT codigo, nombre FROM usuario
             WHERE estado = ?
               AND (codigo LIKE ? OR nombre LIKE ?)
             ORDER BY nombre ASC
             LIMIT ?'
        );
        if (!$st) {
            return [];
        }
        $st->bind_param('sssi', $estado, $like, $like, $limit);
    }

    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $codigo = mort_admin_normalizar_codigo((string) ($row['codigo'] ?? ''));
        if ($codigo === '') {
            continue;
        }
        $nombre = trim((string) ($row['nombre'] ?? ''));
        $rows[] = [
            'id' => $codigo,
            'text' => $codigo . ' — ' . ($nombre !== '' ? $nombre : $codigo),
            'codigo' => $codigo,
            'nombre' => $nombre,
        ];
    }
    $st->close();

    return $rows;
}

/**
 * @param array<string, mixed> $vis
 */
function mort_admin_visibilidad_save(mysqli $conn, string $codigoUsuario, array $vis): array
{
    $codigo = mort_admin_normalizar_codigo($codigoUsuario);
    if ($codigo === '') {
        return ['success' => false, 'message' => 'Usuario no válido.'];
    }
    if (!rp_usuario_existe($conn, $codigo)) {
        return ['success' => false, 'message' => 'Usuario no encontrado en la tabla usuario.'];
    }

    $permisosValidos = [];
    foreach (mort_admin_permisos_app_list() as $p) {
        $k = $p['key'];
        $permisosValidos[] = $k;
    }

    // MyISAM (no transacciones): primero borrar, luego insertar
    $stDel = $conn->prepare('DELETE FROM san_mortalidad_acceso_app WHERE usuario = ?');
    if (!$stDel) {
        return ['success' => false, 'message' => 'Error al preparar eliminación: ' . $conn->error];
    }
    $stDel->bind_param('s', $codigo);
    $stDel->execute();
    $stDel->close();

    // Insertar permisos
    $stIns = $conn->prepare(
        'INSERT INTO san_mortalidad_acceso_app (usuario, permiso, valor) VALUES (?, ?, ?)'
    );
    if (!$stIns) {
        return ['success' => false, 'message' => 'Error al preparar inserción: ' . $conn->error];
    }

    foreach ($permisosValidos as $k) {
        $valor = !empty($vis[$k]) ? 1 : 0;
        $stIns->bind_param('ssi', $codigo, $k, $valor);
        if (!$stIns->execute()) {
            $err = $stIns->error;
            $stIns->close();
            return ['success' => false, 'message' => 'Error al guardar permiso ' . $k . ': ' . $err];
        }
    }
    $stIns->close();

    return ['success' => true];
}

function mort_admin_codigo_por_dni(mysqli $conn, string $dni): string
{
    $dni = substr(preg_replace('/\D+/', '', trim($dni)), 0, 8);
    if ($dni === '') {
        return '';
    }

    if (rp_usuario_columna_existe($conn, 'libtri')) {
        $st = $conn->prepare(
            "SELECT codigo FROM usuario WHERE libtri = ? AND estado = 'A' LIMIT 1"
        );
        if ($st) {
            $st->bind_param('s', $dni);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row && !empty($row['codigo'])) {
                return mort_admin_normalizar_codigo((string) $row['codigo']);
            }
        }
    }

    $st2 = $conn->prepare(
        "SELECT codigo FROM usuario WHERE codigo = ? AND estado = 'A' LIMIT 1"
    );
    if ($st2) {
        $st2->bind_param('s', $dni);
        $st2->execute();
        $row2 = $st2->get_result()->fetch_assoc();
        $st2->close();
        if ($row2 && !empty($row2['codigo'])) {
            return mort_admin_normalizar_codigo((string) $row2['codigo']);
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $payload
 * @return array{success: bool, message?: string, id?: int, dni?: string, nombre?: string}
 */
function mort_admin_usuario_create(mysqli $conn, array $payload, string $codigoSesion = ''): array
{
    if (!rp_usuarios_tabla_existe($conn)) {
        return ['success' => false, 'message' => 'La tabla usuarios no existe en la base de datos.'];
    }

    $dni = preg_replace('/\D+/', '', trim((string) ($payload['dni'] ?? '')));
    $nombre = trim((string) ($payload['nombre'] ?? ''));
    $telefoRaw = trim((string) ($payload['telefo'] ?? $payload['telefono'] ?? ''));
    $correo = trim((string) ($payload['correo'] ?? $payload['email'] ?? ''));
    $clave = trim((string) ($payload['password'] ?? $payload['clave'] ?? ''));

    if ($dni === '' || !preg_match('/^\d{8}$/', $dni)) {
        return ['success' => false, 'message' => 'Ingrese un DNI válido de 8 dígitos.'];
    }
    if ($nombre === '') {
        return ['success' => false, 'message' => 'Ingrese el nombre del usuario.'];
    }
    if (strlen($nombre) > 120) {
        return ['success' => false, 'message' => 'El nombre admite como máximo 120 caracteres.'];
    }
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Ingrese un correo electrónico válido.'];
    }
    if (strlen($correo) > 120) {
        return ['success' => false, 'message' => 'El correo admite como máximo 120 caracteres.'];
    }
    if ($clave === '') {
        $clave = $dni;
    }
    if (strlen($clave) > 64) {
        return ['success' => false, 'message' => 'La contraseña es demasiado larga.'];
    }

    $idPrograma = rp_id_programa($conn);
    if (rp_usuarios_plataforma_existe($conn, $idPrograma, $dni)) {
        return ['success' => false, 'message' => 'Ya existe un usuario con ese DNI en la plataforma.'];
    }

    $r = rp_usuario_plataforma_create($conn, [
        'id_programa' => $idPrograma,
        'dni' => $dni,
        'nombre' => $nombre,
        'password' => $clave,
        'email' => $correo,
        'celular' => $telefoRaw,
        'id_user_create' => rp_usuarios_id_creador($conn, $codigoSesion),
    ]);

    if (empty($r['success'])) {
        return ['success' => false, 'message' => (string) ($r['message'] ?? 'No se pudo crear el usuario.')];
    }

    $codigoVis = mort_admin_codigo_por_dni($conn, $dni);
    if ($codigoVis !== '') {
        $permisosPayload = [];
        foreach (mort_admin_permisos_app_list() as $p) {
            $k = $p['key'];
            $permisosPayload[$k] = !empty($payload[$k]);
        }
        mort_admin_visibilidad_save($conn, $codigoVis, $permisosPayload);
    }

    return [
        'success' => true,
        'id' => (int) ($r['id'] ?? 0),
        'dni' => $dni,
        'nombre' => $nombre,
    ];
}

/**
 * @return array{incubacion: bool, transporte: bool, produccion: bool, despacho: bool, todos: bool}
 */
function mort_admin_visibilidad_defecto_todas(): array
{
    return [
        'incubacion' => true,
        'transporte' => true,
        'produccion' => true,
        'despacho' => true,
        'todos' => true,
        'codigo_usuario' => '',
    ];
}

/**
 * Resuelve usuario.codigo desde código o DNI.
 */
function mort_admin_resolver_codigo_sanidad(mysqli $conn, string $codigo = '', string $dni = ''): string
{
    $codigo = mort_admin_normalizar_codigo($codigo);
    if ($codigo !== '' && rp_usuario_existe($conn, $codigo)) {
        return $codigo;
    }

    $dni = substr(preg_replace('/\D+/', '', trim($dni)), 0, 8);
    if ($dni !== '') {
        $porDni = mort_admin_codigo_por_dni($conn, $dni);
        if ($porDni !== '') {
            return $porDni;
        }
    }

    if ($codigo !== '') {
        return $codigo;
    }

    return '';
}

/**
 * Visibilidad para la app móvil (formato antiguo, compatibilidad).
 * Ahora mapea desde los nuevos permisos de san_mortalidad_acceso_app.
 *
 * @return array{incubacion: bool, transporte: bool, produccion: bool, despacho: bool, todos: bool, codigo_usuario: string}
 */
function mort_admin_visibilidad_para_app(mysqli $conn, string $codigo = '', string $dni = ''): array
{
    $def = mort_admin_visibilidad_defecto_todas();
    $codigoSanidad = mort_admin_resolver_codigo_sanidad($conn, $codigo, $dni);
    if ($codigoSanidad === '' || !rp_usuario_existe($conn, $codigoSanidad)) {
        return $def;
    }

    $vis = mort_admin_visibilidad_get($conn, $codigoSanidad);
    // Mapear nuevos keys a keys antiguos
    $map = [
        'produccion' => $vis['verRegProduccion'] ?? false,
        'despacho' => $vis['verRegDespacho'] ?? false,
        'incubacion' => $vis['verRegPI'] ?? false,
        'transporte' => $vis['verRegTransporte'] ?? false,
    ];
    $alguno = $map['produccion'] || $map['despacho'] || $map['incubacion'] || $map['transporte'];
    if (!$alguno) {
        $def['codigo_usuario'] = $codigoSanidad;
        return $def;
    }

    return [
        'incubacion' => $map['incubacion'],
        'transporte' => $map['transporte'],
        'produccion' => $map['produccion'],
        'despacho' => $map['despacho'],
        'todos' => false,
        'codigo_usuario' => $codigoSanidad,
    ];
}

/** @deprecated Use mort_admin_visibilidad_para_app */
function mort_admin_visibilidad_por_dni(mysqli $conn, string $dni): array
{
    return mort_admin_visibilidad_para_app($conn, '', $dni);
}
