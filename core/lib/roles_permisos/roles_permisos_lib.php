<?php

declare(strict_types=1);

require_once __DIR__ . '/../sip_acl_sanidad.php';
require_once __DIR__ . '/../sip_menu_catalog_lib.php';
require_once __DIR__ . '/../usuario_empresa_lib.php';

function rp_id_programa(mysqli $conn): int
{
    return sip_acl_id_programa_sanidad($conn);
}

function rp_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function rp_json_ok(array $data = []): void
{
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array<string, bool>
 */
function rp_rol_columnas(mysqli $conn): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $r = @$conn->query('SHOW COLUMNS FROM adm_rol');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $cache[(string) $row['Field']] = true;
        }
    }

    return $cache;
}

function rp_rol_tiene_columna(mysqli $conn, string $columna): bool
{
    return !empty(rp_rol_columnas($conn)[$columna]);
}

/** Condición SQL: rol del programa Sanidad (id_programa resuelto en amd_programas). */
function rp_rol_sql_id_programa_eq(string $columna = 'id_programa'): string
{
    return 'CAST(' . $columna . ' AS UNSIGNED) = ?';
}

/**
 * @return list<array<string, mixed>>
 */
function rp_roles_list(mysqli $conn): array
{
    if (!rp_rol_tiene_columna($conn, 'id_programa')) {
        return [];
    }

    $select = ['id', 'cod_rol', 'nom_rol'];
    foreach (['descripcion', 'activo', 'id_programa', 'fecha_update', 'fecha_actualizacion'] as $col) {
        if (rp_rol_tiene_columna($conn, $col) && !in_array($col, $select, true)) {
            $select[] = $col;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM adm_rol WHERE ' . rp_rol_sql_id_programa_eq('id_programa')
        . ' ORDER BY nom_rol ASC';

    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $idProg = rp_id_programa($conn);
    $st->bind_param('i', $idProg);
    $st->execute();
    $res = $st->get_result();

    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $fechaUpd = (string) ($row['fecha_update'] ?? $row['fecha_actualizacion'] ?? '');
        $rows[] = [
            'id' => (int) $row['id'],
            'cod_rol' => (string) $row['cod_rol'],
            'nom_rol' => (string) $row['nom_rol'],
            'descripcion' => (string) ($row['descripcion'] ?? ''),
            'activo' => array_key_exists('activo', $row) ? (int) $row['activo'] : 1,
            'id_programa' => rp_id_programa($conn),
            'solo_lectura' => false,
            'fecha_update' => $fechaUpd,
        ];
    }
    $st->close();

    return $rows;
}

function rp_rol_codigo_existe_programa(mysqli $conn, string $codRol): bool
{
    $cod = strtoupper(trim($codRol));
    if ($cod === '') {
        return false;
    }
    if (!rp_rol_tiene_columna($conn, 'id_programa')) {
        $st = $conn->prepare('SELECT id FROM adm_rol WHERE UPPER(cod_rol) = ? LIMIT 1');
        if (!$st) {
            return false;
        }
        $st->bind_param('s', $cod);
        $st->execute();
        $ok = $st->get_result()->fetch_assoc() !== null;
        $st->close();

        return $ok;
    }
    $st = $conn->prepare(
        'SELECT id FROM adm_rol WHERE UPPER(cod_rol) = ? AND ' . rp_rol_sql_id_programa_eq('id_programa') . ' LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    $idProg = rp_id_programa($conn);
    $st->bind_param('si', $cod, $idProg);
    $st->execute();
    $ok = $st->get_result()->fetch_assoc() !== null;
    $st->close();

    return $ok;
}

function rp_generar_cod_rol(mysqli $conn, string $nom): string
{
    $base = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $nom) ?: '', 0, 6));
    if ($base === '') {
        $base = 'ROL';
    }
    $cod = substr($base, 0, 10);
    $n = 1;
    $dupSql = 'SELECT id FROM adm_rol WHERE UPPER(cod_rol) = ? LIMIT 1';
    if (rp_rol_tiene_columna($conn, 'id_programa')) {
        $dupSql = 'SELECT id FROM adm_rol WHERE UPPER(cod_rol) = ? AND ' . rp_rol_sql_id_programa_eq('id_programa') . ' LIMIT 1';
    }
    while ($n < 100) {
        $st = $conn->prepare($dupSql);
        if (!$st) {
            return substr($cod, 0, 10);
        }
        if (rp_rol_tiene_columna($conn, 'id_programa')) {
            $idProg = rp_id_programa($conn);
            $st->bind_param('si', $cod, $idProg);
        } else {
            $st->bind_param('s', $cod);
        }
        $st->execute();
        $exists = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$exists) {
            return $cod;
        }
        $suf = (string) $n;
        $cod = substr($base, 0, max(1, 10 - strlen($suf))) . $suf;
        $n++;
    }

    return substr($base . bin2hex(random_bytes(2)), 0, 10);
}

/**
 * @param array<string, mixed> $data
 */
function rp_rol_save(mysqli $conn, array $data, string $userUpdate): array
{
    $id = (int) ($data['id'] ?? 0);
    $cod = strtoupper(trim((string) ($data['cod_rol'] ?? '')));
    $nom = trim((string) ($data['nom_rol'] ?? ''));
    $desc = trim((string) ($data['descripcion'] ?? ''));
    $activo = !empty($data['activo']) ? 1 : 0;

    if ($nom === '') {
        return ['success' => false, 'message' => 'El nombre es obligatorio.'];
    }

    if ($id <= 0 && $cod === '') {
        $cod = rp_generar_cod_rol($conn, $nom);
    }

    if ($cod === '') {
        return ['success' => false, 'message' => 'No se pudo generar el código del rol.'];
    }

    if ($id > 0) {
        if (!rp_rol_tiene_columna($conn, 'id_programa')) {
            return ['success' => false, 'message' => 'La tabla adm_rol no tiene id_programa; no se puede editar desde Sanidad.'];
        }
        $stChk = $conn->prepare(
            'SELECT id FROM adm_rol WHERE id = ? AND ' . rp_rol_sql_id_programa_eq('id_programa') . ' LIMIT 1'
        );
        if ($stChk) {
            $idProg = rp_id_programa($conn);
            $stChk->bind_param('ii', $id, $idProg);
            $stChk->execute();
            $rowChk = $stChk->get_result()->fetch_assoc();
            $stChk->close();
            if (!$rowChk) {
                return ['success' => false, 'message' => 'Solo se pueden editar roles del programa Sanidad.'];
            }
        }
        $stDup = $conn->prepare(
            'SELECT id FROM adm_rol WHERE UPPER(cod_rol) = ? AND id <> ? AND ' . rp_rol_sql_id_programa_eq('id_programa') . ' LIMIT 1'
        );
        if ($stDup) {
            $idProg = rp_id_programa($conn);
            $stDup->bind_param('sii', $cod, $id, $idProg);
            $stDup->execute();
            if ($stDup->get_result()->fetch_assoc()) {
                $stDup->close();

                return ['success' => false, 'message' => 'El código de rol ya existe en este programa.'];
            }
            $stDup->close();
        }

        $sets = ['cod_rol = ?', 'nom_rol = ?'];
        $types = 'ss';
        $params = [$cod, $nom];
        if (rp_rol_tiene_columna($conn, 'descripcion')) {
            $sets[] = 'descripcion = ?';
            $types .= 's';
            $params[] = $desc;
        }
        if (rp_rol_tiene_columna($conn, 'activo')) {
            $sets[] = 'activo = ?';
            $types .= 'i';
            $params[] = $activo;
        }
        if (rp_rol_tiene_columna($conn, 'fecha_update')) {
            $sets[] = 'fecha_update = NOW()';
        } elseif (rp_rol_tiene_columna($conn, 'fecha_actualizacion')) {
            $sets[] = 'fecha_actualizacion = NOW()';
        }
        $where = ' WHERE id = ? AND ' . rp_rol_sql_id_programa_eq('id_programa');
        $types .= 'ii';
        $params[] = $id;
        $params[] = rp_id_programa($conn);
        $sql = 'UPDATE adm_rol SET ' . implode(', ', $sets) . $where;
        $st = $conn->prepare($sql);
        if (!$st) {
            return ['success' => false, 'message' => 'Error al actualizar: ' . $conn->error];
        }
        $st->bind_param($types, ...$params);
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();

            return ['success' => false, 'message' => 'Error al actualizar: ' . $err];
        }
        $st->close();

        return ['success' => true, 'id' => $id, 'cod_rol' => $cod];
    }

    if (!rp_rol_tiene_columna($conn, 'id_programa')) {
        return ['success' => false, 'message' => 'La tabla adm_rol debe tener la columna id_programa para crear roles de Sanidad.'];
    }

    $stDup = $conn->prepare(
        'SELECT id FROM adm_rol WHERE UPPER(cod_rol) = ? AND ' . rp_rol_sql_id_programa_eq('id_programa') . ' LIMIT 1'
    );
    if ($stDup) {
        $idProg = rp_id_programa($conn);
        $stDup->bind_param('si', $cod, $idProg);
        $stDup->execute();
        if ($stDup->get_result()->fetch_assoc()) {
            $stDup->close();

            return ['success' => false, 'message' => 'El código de rol ya existe en el programa Sanidad.'];
        }
        $stDup->close();
    }

    $fields = ['cod_rol', 'nom_rol', 'id_programa'];
    $placeholders = ['?', '?', '?'];
    $types = 'ssi';
    $params = [$cod, $nom, rp_id_programa($conn)];
    if (rp_rol_tiene_columna($conn, 'descripcion')) {
        $fields[] = 'descripcion';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $desc;
    }
    if (rp_rol_tiene_columna($conn, 'activo')) {
        $fields[] = 'activo';
        $placeholders[] = '?';
        $types .= 'i';
        $params[] = $activo;
    }
    $fechaCol = null;
    if (rp_rol_tiene_columna($conn, 'fecha_update')) {
        $fechaCol = 'fecha_update';
    } elseif (rp_rol_tiene_columna($conn, 'fecha_actualizacion')) {
        $fechaCol = 'fecha_actualizacion';
    }
    if ($fechaCol !== null) {
        $fields[] = $fechaCol;
        $placeholders[] = 'NOW()';
    }
    $sql = 'INSERT INTO adm_rol (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $st = $conn->prepare($sql);
    if (!$st) {
        return ['success' => false, 'message' => 'Error al crear rol: ' . $conn->error];
    }
    $st->bind_param($types, ...$params);
    if (!$st->execute()) {
        $err = $st->error;
        $st->close();

        return ['success' => false, 'message' => 'Error al crear rol: ' . $err];
    }
    $newId = (int) $conn->insert_id;
    $st->close();

    return ['success' => true, 'id' => $newId, 'cod_rol' => $cod, 'id_programa' => rp_id_programa($conn)];
}

function rp_rol_toggle(mysqli $conn, int $id, int $activo): array
{
    if (!rp_rol_tiene_columna($conn, 'activo')) {
        return ['success' => true, 'message' => 'La tabla no tiene columna activo.'];
    }
    $sets = ['activo = ?'];
    $types = 'i';
    $params = [$activo];
    if (rp_rol_tiene_columna($conn, 'fecha_update')) {
        $sets[] = 'fecha_update = NOW()';
    }
    if (!rp_rol_tiene_columna($conn, 'id_programa')) {
        return ['success' => false, 'message' => 'La tabla adm_rol no tiene id_programa.'];
    }
    $where = ' WHERE id = ? AND ' . rp_rol_sql_id_programa_eq('id_programa');
    $types .= 'ii';
    $params[] = $id;
    $params[] = rp_id_programa($conn);
    $sql = 'UPDATE adm_rol SET ' . implode(', ', $sets) . $where;
    $st = $conn->prepare($sql);
    if (!$st) {
        return ['success' => false, 'message' => 'Error al cambiar estado: ' . $conn->error];
    }
    $st->bind_param($types, ...$params);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();

    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Rol no encontrado en el programa Sanidad.'];
}

function rp_usuario_columna_existe(mysqli $conn, string $columna, bool $refresh = false): bool
{
    static $cache = null;
    if ($refresh || !is_array($cache)) {
        $cache = [];
        $r = @$conn->query('SHOW COLUMNS FROM usuario');
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $cache[(string) $row['Field']] = true;
            }
        }
    }

    return !empty($cache[$columna]);
}

function rp_usuario_ensure_columna_empresa(mysqli $conn): void
{
    sip_usuario_ensure_columna_empresa($conn);
    rp_usuario_columna_existe($conn, 'empresa', true);
}

function rp_usuario_existe(mysqli $conn, string $codigo): bool
{
    $cod = trim($codigo);
    if ($cod === '') {
        return false;
    }
    $st = $conn->prepare('SELECT codigo FROM usuario WHERE codigo = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('s', $cod);
    $st->execute();
    $ok = $st->get_result()->fetch_assoc() !== null;
    $st->close();

    return $ok;
}

function rp_usuario_libtri_existe(mysqli $conn, string $dni): bool
{
    if (!rp_usuario_columna_existe($conn, 'libtri')) {
        return false;
    }
    $dni = trim($dni);
    if ($dni === '') {
        return false;
    }
    $st = $conn->prepare('SELECT codigo FROM usuario WHERE libtri = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('s', $dni);
    $st->execute();
    $ok = $st->get_result()->fetch_assoc() !== null;
    $st->close();

    return $ok;
}

function rp_usuario_normalizar_ascii(string $texto): string
{
    $t = trim($texto);
    if ($t === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if (is_string($conv) && $conv !== '') {
            $t = $conv;
        }
    }

    return strtoupper(preg_replace('/[^A-Z0-9 ]/i', '', $t));
}

/**
 * Sugiere siglas o abreviatura a partir del nombre (máx. 7 caracteres).
 */
function rp_usuario_base_codigo_desde_nombre(string $nombre): string
{
    $norm = rp_usuario_normalizar_ascii($nombre);
    $partes = array_values(array_filter(preg_split('/\s+/', $norm) ?: []));
    if ($partes === []) {
        return '';
    }

    $maxLen = 7;
    if (count($partes) === 1) {
        return substr($partes[0], 0, $maxLen);
    }
    if (count($partes) === 2) {
        $a = substr($partes[0], 0, 4);
        $b = substr($partes[1], 0, 3);

        return substr($a . $b, 0, $maxLen);
    }

    $siglas = '';
    foreach ($partes as $p) {
        if ($p !== '') {
            $siglas .= $p[0];
        }
    }
    $siglas = substr($siglas, 0, $maxLen);
    if (strlen($siglas) >= 3) {
        return $siglas;
    }

    return substr($partes[0], 0, $maxLen);
}

function rp_usuario_codigo_disponible(mysqli $conn, string $nombre): ?string
{
    $base = rp_usuario_base_codigo_desde_nombre($nombre);
    $base = strtoupper(preg_replace('/[^A-Z0-9]/', '', $base));
    if ($base === '') {
        return null;
    }

    $maxLen = 7;
    $base = substr($base, 0, $maxLen);
    if (!rp_usuario_existe($conn, $base)) {
        return $base;
    }

    for ($n = 1; $n <= 99; $n++) {
        $suffix = (string) $n;
        $try = substr($base, 0, $maxLen - strlen($suffix)) . $suffix;
        if ($try !== '' && !rp_usuario_existe($conn, $try)) {
            return $try;
        }
    }

    return null;
}

/**
 * @return array{token: string, base_url: string}
 */
function rp_factiliza_config(): array
{
    $token = '';
    $baseUrl = 'https://api.factiliza.com/v1';
    $configPath = __DIR__ . '/../../../config/factiliza.php';
    if (is_file($configPath)) {
        require_once $configPath;
    }
    if (defined('FACTILIZA_API_TOKEN')) {
        $token = trim((string) FACTILIZA_API_TOKEN);
    }
    if (defined('FACTILIZA_API_BASE_URL')) {
        $baseUrl = trim((string) FACTILIZA_API_BASE_URL);
    }

    return [
        'token' => $token,
        'base_url' => rtrim($baseUrl, '/'),
    ];
}

/**
 * @param array<string, mixed> $data
 */
function rp_nombre_desde_datos_dni(array $data): string
{
    $nombres = trim((string) ($data['nombres'] ?? ''));
    $apPat = trim((string) ($data['apellido_paterno'] ?? ''));
    $apMat = trim((string) ($data['apellido_materno'] ?? ''));
    $partes = array_values(array_filter([$nombres, $apPat, $apMat], static function (string $p): bool {
        return $p !== '';
    }));
    $nombre = implode(' ', $partes);

    if ($nombre === '') {
        $nombre = trim((string) ($data['nombre_completo'] ?? ''));
        if (strpos($nombre, ',') !== false) {
            $chunks = array_map('trim', explode(',', $nombre, 2));
            $apellidos = $chunks[0] ?? '';
            $noms = $chunks[1] ?? '';
            $nombre = trim($noms . ' ' . $apellidos);
        }
    }

    $nombre = preg_replace('/\s+/', ' ', trim($nombre)) ?? '';
    if ($nombre === '') {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($nombre, 0, 30, 'UTF-8')
        : substr($nombre, 0, 30);
}

/**
 * @return array{success: bool, message?: string, nombre?: string, data?: array<string, mixed>}
 */
function rp_consultar_dni_factiliza(string $dni): array
{
    $dni = preg_replace('/\D+/', '', trim($dni));
    if ($dni === '' || !preg_match('/^\d{8}$/', $dni)) {
        return ['success' => false, 'message' => 'Ingrese un DNI de 8 dígitos.'];
    }

    $cfg = rp_factiliza_config();
    if ($cfg['token'] === '') {
        return ['success' => false, 'message' => 'API de consulta DNI no configurada.'];
    }

    $url = $cfg['base_url'] . '/dni/info/' . rawurlencode($dni);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['success' => false, 'message' => 'No se pudo consultar el DNI.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['token'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlErr !== '') {
        return ['success' => false, 'message' => 'No se pudo consultar el DNI.'];
    }

    if ($httpCode === 401 || $httpCode === 403) {
        return ['success' => false, 'message' => 'Consulta DNI no autorizada. Revise el token en config/factiliza.php.'];
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json) || empty($json['success'])) {
        $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';
        if ($msg === '') {
            if ($httpCode === 404) {
                $msg = 'DNI no encontrado.';
            } elseif ($httpCode >= 500) {
                $msg = 'El servicio de consulta DNI no está disponible.';
            } else {
                $msg = 'DNI no encontrado.';
            }
        }

        return ['success' => false, 'message' => $msg];
    }

    $data = is_array($json['data'] ?? null) ? $json['data'] : [];
    $nombre = rp_nombre_desde_datos_dni($data);
    if ($nombre === '') {
        return ['success' => false, 'message' => 'No se obtuvo nombre para el DNI.'];
    }

    return [
        'success' => true,
        'nombre' => $nombre,
        'data' => $data,
    ];
}

function rp_usuarios_tabla_existe(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = @$conn->query("SHOW TABLES LIKE 'usuarios'");
    $cache = $r && $r->num_rows > 0;

    return $cache;
}

function rp_usuarios_plataforma_existe(mysqli $conn, int $idPrograma, string $dni): bool
{
    if (!rp_usuarios_tabla_existe($conn) || $idPrograma <= 0) {
        return false;
    }
    $dni = substr(preg_replace('/\D+/', '', trim($dni)), 0, 8);
    if ($dni === '') {
        return false;
    }
    $st = $conn->prepare('SELECT id FROM usuarios WHERE id_programa = ? AND dni = ? LIMIT 1');
    if (!$st) {
        return false;
    }
    $st->bind_param('is', $idPrograma, $dni);
    $st->execute();
    $ok = $st->get_result()->fetch_assoc() !== null;
    $st->close();

    return $ok;
}

function rp_usuarios_id_creador(mysqli $conn, string $codigoSesion): ?int
{
    if (!rp_usuarios_tabla_existe($conn) || trim($codigoSesion) === '') {
        return null;
    }
    if (!rp_usuario_columna_existe($conn, 'libtri')) {
        return null;
    }

    $st = $conn->prepare(
        'SELECT up.id
         FROM usuarios up
         INNER JOIN usuario u ON u.libtri = up.dni
         WHERE u.codigo = ?
         LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $codigo = trim($codigoSesion);
    $st->bind_param('s', $codigo);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || !isset($row['id'])) {
        return null;
    }

    $id = (int) $row['id'];

    return $id > 0 ? $id : null;
}

/**
 * @param array<string, mixed> $data
 * @return array{success: bool, message?: string, id?: int}
 */
function rp_usuario_plataforma_create(mysqli $conn, array $data): array
{
    if (!rp_usuarios_tabla_existe($conn)) {
        return ['success' => true];
    }

    $idPrograma = (int) ($data['id_programa'] ?? 0);
    $dni = substr(preg_replace('/\D+/', '', trim((string) ($data['dni'] ?? ''))), 0, 8);
    $nombre = trim((string) ($data['nombre'] ?? ''));
    $clave = trim((string) ($data['password'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $celularRaw = trim((string) ($data['celular'] ?? ''));
    $idUserCreate = isset($data['id_user_create']) ? (int) $data['id_user_create'] : 0;

    if ($idPrograma <= 0 || strlen($dni) !== 8 || $nombre === '' || $clave === '') {
        return ['success' => false, 'message' => 'Datos incompletos para registrar en usuarios.'];
    }

    $hash = password_hash($clave, PASSWORD_BCRYPT);
    if ($hash === false) {
        return ['success' => false, 'message' => 'No se pudo cifrar la contraseña para usuarios.'];
    }

    $celular = null;
    if ($celularRaw !== '') {
        $celular = preg_replace('/\D+/', '', $celularRaw);
        if (strlen($celular) > 9) {
            $celular = substr($celular, -9);
        }
        if ($celular === '') {
            $celular = null;
        }
    }

    if ($idUserCreate > 0) {
        $sql = 'INSERT INTO usuarios (id_programa, dni, nombre, password_bcrypt, email, celular, activo, id_user_create, date_create)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())';
        $st = $conn->prepare($sql);
        if (!$st) {
            return ['success' => false, 'message' => 'Error al preparar el registro en usuarios.'];
        }
        $st->bind_param('isssssi', $idPrograma, $dni, $nombre, $hash, $email, $celular, $idUserCreate);
    } else {
        $sql = 'INSERT INTO usuarios (id_programa, dni, nombre, password_bcrypt, email, celular, activo, date_create)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())';
        $st = $conn->prepare($sql);
        if (!$st) {
            return ['success' => false, 'message' => 'Error al preparar el registro en usuarios.'];
        }
        $st->bind_param('isssss', $idPrograma, $dni, $nombre, $hash, $email, $celular);
    }

    $ok = $st->execute();
    $err = $st->error;
    $newId = (int) $conn->insert_id;
    $st->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'No se pudo crear el usuario en usuarios.' . ($err !== '' ? ' ' . $err : '')];
    }

    return ['success' => true, 'id' => $newId];
}

/**
 * @param array<string, mixed> $payload
 * @return array{success: bool, message?: string, codigo?: string, nombre?: string, libtri?: string, id_usuarios?: int}
 */
function rp_usuario_create(mysqli $conn, array $payload, string $codigoSesion = ''): array
{
    $dni = preg_replace('/\D+/', '', trim((string) ($payload['dni'] ?? $payload['libtri'] ?? $payload['codigo'] ?? '')));
    $nombre = trim((string) ($payload['nombre'] ?? ''));
    $telefoRaw = trim((string) ($payload['telefo'] ?? $payload['telefono'] ?? ''));
    $correo = trim((string) ($payload['correo'] ?? $payload['email'] ?? ''));
    $clave = trim((string) ($payload['password'] ?? $payload['clave'] ?? ''));
    $codigoManual = strtoupper(trim((string) ($payload['codigo_usuario'] ?? '')));
    $codigoManual = preg_replace('/[^A-Z0-9]/', '', $codigoManual);
    $codigoManual = substr($codigoManual, 0, 7);

    if ($dni === '' || !preg_match('/^\d{8}$/', $dni)) {
        return ['success' => false, 'message' => 'Ingrese un DNI válido de 8 dígitos.'];
    }
    if (strlen($nombre) > 30) {
        return ['success' => false, 'message' => 'El nombre admite como máximo 30 caracteres.'];
    }
    if ($nombre === '') {
        return ['success' => false, 'message' => 'Ingrese el nombre del usuario.'];
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
    if (strlen($clave) > 8) {
        return ['success' => false, 'message' => 'La contraseña admite como máximo 8 caracteres.'];
    }

    $telefo = '';
    if ($telefoRaw !== '') {
        $telefo = preg_replace('/\D+/', '', $telefoRaw);
        if (!preg_match('/^\d{9,15}$/', $telefo)) {
            return ['success' => false, 'message' => 'El teléfono debe tener entre 9 y 15 dígitos.'];
        }
        if (strlen($telefo) > 12) {
            return ['success' => false, 'message' => 'El teléfono admite como máximo 12 dígitos.'];
        }
    }

    if (rp_usuario_libtri_existe($conn, $dni)) {
        return ['success' => false, 'message' => 'Ya existe un usuario con ese DNI.'];
    }

    rp_usuario_ensure_columna_empresa($conn);
    $empresaNorm = sip_usuario_empresa_normalizar($conn, $payload['empresa'] ?? '');
    if (empty($empresaNorm['ok'])) {
        return ['success' => false, 'message' => (string) ($empresaNorm['message'] ?? 'Laboratorio no válido.')];
    }
    $empresaCod = $empresaNorm['codigo'];

    $idPrograma = rp_id_programa($conn);
    $dniPlataforma = $dni;
    if (rp_usuarios_tabla_existe($conn)) {
        if (rp_usuarios_plataforma_existe($conn, $idPrograma, $dniPlataforma)) {
            return ['success' => false, 'message' => 'Ya existe un usuario de plataforma con ese DNI.'];
        }
    }

    if ($codigoManual !== '') {
        if (rp_usuario_existe($conn, $codigoManual)) {
            return ['success' => false, 'message' => 'El código de usuario ya está en uso.'];
        }
        $codigo = $codigoManual;
    } else {
        $codigo = rp_usuario_codigo_disponible($conn, $nombre);
        if ($codigo === null || $codigo === '') {
            return ['success' => false, 'message' => 'No se pudo generar un código de usuario único.'];
        }
    }

    $stEmp = $conn->prepare("SELECT 1 FROM conempre WHERE epre = 'RS' LIMIT 1");
    if (!$stEmp) {
        return ['success' => false, 'message' => 'No se pudo obtener la clave de cifrado.'];
    }
    $stEmp->execute();
    $okEmp = $stEmp->get_result()->fetch_assoc() !== null;
    $stEmp->close();
    if (!$okEmp) {
        return ['success' => false, 'message' => 'Configuración de empresa (conempre) no disponible.'];
    }

    $cols = ['codigo', 'nombre', 'password', 'estado'];
    $select = ['?', '?', 'LEFT(AES_ENCRYPT(?, c.enom), 8)', '?'];
    $params = [$codigo, $nombre, $clave, 'A'];

    if (rp_usuario_columna_existe($conn, 'libtri')) {
        $cols[] = 'libtri';
        $select[] = '?';
        $params[] = $dni;
    }
    if (rp_usuario_columna_existe($conn, 'telefo')) {
        $cols[] = 'telefo';
        $select[] = '?';
        $params[] = $telefo;
    }
    if (rp_usuario_columna_existe($conn, 'reduser')) {
        $reduser = strtolower(preg_replace('/\s+/', '', $nombre));
        if ($reduser === '') {
            $reduser = strtolower($codigo);
        }
        $reduser = substr($reduser, 0, 30);
        $cols[] = 'reduser';
        $select[] = '?';
        $params[] = $reduser;
    }
    if (rp_usuario_columna_existe($conn, 'notificar')) {
        $cols[] = 'notificar';
        $select[] = '?';
        $params[] = '0';
    }
    if (rp_usuario_columna_existe($conn, 'rol_sanidad')) {
        $cols[] = 'rol_sanidad';
        $select[] = '?';
        $params[] = 'USER';
    }
    if (rp_usuario_columna_existe($conn, 'epre')) {
        $cols[] = 'epre';
        $select[] = '?';
        $params[] = 'RS';
    }
    if (rp_usuario_columna_existe($conn, 'empresa') && $empresaCod !== null) {
        $cols[] = 'empresa';
        $select[] = '?';
        $params[] = (string) $empresaCod;
    }

    $conn->begin_transaction();

    $sql = 'INSERT INTO usuario (' . implode(', ', $cols) . ') SELECT '
        . implode(', ', $select)
        . " FROM conempre c WHERE c.epre = 'RS' LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        $conn->rollback();

        return ['success' => false, 'message' => 'Error al preparar el registro de usuario.'];
    }

    $types = str_repeat('s', count($params));
    $bindArgs = array_merge([$types], $params);
    $refs = [];
    foreach ($bindArgs as $i => $value) {
        $refs[$i] = &$bindArgs[$i];
    }
    call_user_func_array([$st, 'bind_param'], $refs);

    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    if (!$ok) {
        $conn->rollback();

        return ['success' => false, 'message' => 'No se pudo crear el usuario.' . ($err !== '' ? ' ' . $err : '')];
    }

    $rPlataforma = rp_usuario_plataforma_create($conn, [
        'id_programa' => $idPrograma,
        'dni' => $dniPlataforma,
        'nombre' => $nombre,
        'password' => $clave,
        'email' => $correo,
        'celular' => $telefo,
        'id_user_create' => rp_usuarios_id_creador($conn, $codigoSesion),
    ]);
    if (empty($rPlataforma['success'])) {
        $conn->rollback();

        return ['success' => false, 'message' => (string) ($rPlataforma['message'] ?? 'No se pudo crear el usuario en usuarios.')];
    }

    $passwordCorreo = '';
    $stCorreo = $conn->prepare(
        'INSERT INTO san_correo_sanidad (codigo, correo, password) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE correo = VALUES(correo)'
    );
    if (!$stCorreo) {
        $conn->rollback();

        return ['success' => false, 'message' => 'Error al guardar el correo del usuario.'];
    }
    $stCorreo->bind_param('sss', $codigo, $correo, $passwordCorreo);
    $okCorreo = $stCorreo->execute();
    $errCorreo = $stCorreo->error;
    $stCorreo->close();

    if (!$okCorreo) {
        $conn->rollback();

        return ['success' => false, 'message' => 'No se pudo guardar el correo.' . ($errCorreo !== '' ? ' ' . $errCorreo : '')];
    }

    if (!$conn->commit()) {
        $conn->rollback();

        return ['success' => false, 'message' => 'No se pudo confirmar el registro del usuario.'];
    }

    $result = [
        'success' => true,
        'codigo' => $codigo,
        'nombre' => $nombre,
        'libtri' => $dni,
        'correo' => $correo,
    ];
    if (!empty($rPlataforma['id'])) {
        $result['id_usuarios'] = (int) $rPlataforma['id'];
    }

    return $result;
}

/**
 * @param array<string, mixed> $payload
 * @return array{success: bool, message?: string, codigo?: string, nombre?: string}
 */
function rp_usuario_generico_create(mysqli $conn, array $payload): array
{
    $codigo = strtoupper(trim((string) ($payload['codigo'] ?? '')));
    $descripcion = trim((string) ($payload['descripcion'] ?? ''));
    $clave = trim((string) ($payload['password'] ?? $payload['clave'] ?? ''));

    if (!preg_match('/^[A-Z]{4}$/', $codigo)) {
        return ['success' => false, 'message' => 'El código debe tener exactamente 4 letras (A-Z).'];
    }

    if ($descripcion === '') {
        return ['success' => false, 'message' => 'Ingrese la descripción del usuario genérico.'];
    }
    if (strlen($descripcion) > 30) {
        return ['success' => false, 'message' => 'La descripción admite como máximo 30 caracteres.'];
    }
    if ($clave === '') {
        return ['success' => false, 'message' => 'Ingrese la contraseña del usuario genérico.'];
    }
    if (strlen($clave) > 8) {
        return ['success' => false, 'message' => 'La contraseña admite como máximo 8 caracteres.'];
    }

    if (rp_usuario_existe($conn, $codigo)) {
        return ['success' => false, 'message' => 'El código de usuario ya está en uso.'];
    }

    rp_usuario_ensure_columna_empresa($conn);
    $empresaNorm = sip_usuario_empresa_normalizar($conn, $payload['empresa'] ?? '');
    if (empty($empresaNorm['ok'])) {
        return ['success' => false, 'message' => (string) ($empresaNorm['message'] ?? 'Laboratorio no válido.')];
    }
    $empresaCod = $empresaNorm['codigo'];

    $stEmp = $conn->prepare("SELECT 1 FROM conempre WHERE epre = 'RS' LIMIT 1");
    if (!$stEmp) {
        return ['success' => false, 'message' => 'No se pudo obtener la clave de cifrado.'];
    }
    $stEmp->execute();
    $okEmp = $stEmp->get_result()->fetch_assoc() !== null;
    $stEmp->close();
    if (!$okEmp) {
        return ['success' => false, 'message' => 'Configuración de empresa (conempre) no disponible.'];
    }

    $cols = ['codigo', 'nombre', 'password', 'estado'];
    $select = ['?', '?', 'LEFT(AES_ENCRYPT(?, c.enom), 8)', '?'];
    $params = [$codigo, $descripcion, $clave, 'A'];

    if (rp_usuario_columna_existe($conn, 'libtri')) {
        $cols[] = 'libtri';
        $select[] = '?';
        $params[] = $clave;
    }
    if (rp_usuario_columna_existe($conn, 'notificar')) {
        $cols[] = 'notificar';
        $select[] = '?';
        $params[] = '0';
    }
    if (rp_usuario_columna_existe($conn, 'rol_sanidad')) {
        $cols[] = 'rol_sanidad';
        $select[] = '?';
        $params[] = 'USER';
    }
    if (rp_usuario_columna_existe($conn, 'epre')) {
        $cols[] = 'epre';
        $select[] = '?';
        $params[] = 'RS';
    }
    if (rp_usuario_columna_existe($conn, 'empresa') && $empresaCod !== null) {
        $cols[] = 'empresa';
        $select[] = '?';
        $params[] = (string) $empresaCod;
    }

    $conn->begin_transaction();

    $sql = 'INSERT INTO usuario (' . implode(', ', $cols) . ') SELECT '
        . implode(', ', $select)
        . " FROM conempre c WHERE c.epre = 'RS' LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        $conn->rollback();

        return ['success' => false, 'message' => 'Error al preparar el registro de usuario genérico.'];
    }

    $types = str_repeat('s', count($params));
    $bindArgs = array_merge([$types], $params);
    $refs = [];
    foreach ($bindArgs as $i => $value) {
        $refs[$i] = &$bindArgs[$i];
    }
    call_user_func_array([$st, 'bind_param'], $refs);

    $ok = $st->execute();
    $err = $st->error;
    $st->close();

    if (!$ok) {
        $conn->rollback();

        return ['success' => false, 'message' => 'No se pudo crear el usuario genérico.' . ($err !== '' ? ' ' . $err : '')];
    }

    if (!$conn->commit()) {
        $conn->rollback();

        return ['success' => false, 'message' => 'No se pudo confirmar el registro del usuario genérico.'];
    }

    return [
        'success' => true,
        'codigo' => $codigo,
        'nombre' => $descripcion,
    ];
}

/**
 * @return list<array<string, string>>
 */
function rp_usuarios_list(mysqli $conn): array
{
    rp_usuario_ensure_columna_empresa($conn);
    $hasEmpresa = rp_usuario_columna_existe($conn, 'empresa');
    $sql = $hasEmpresa
        ? 'SELECT u.codigo, u.nombre, u.empresa, l.nombre AS empresa_nombre
           FROM usuario u
           LEFT JOIN san_dim_laboratorio l ON l.codigo = u.empresa
           WHERE u.estado = ?
           ORDER BY u.nombre ASC'
        : 'SELECT codigo, nombre FROM usuario WHERE estado = ? ORDER BY nombre ASC';
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $estado = 'A';
    $st->bind_param('s', $estado);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $empresaRaw = trim((string) ($row['empresa'] ?? ''));
        $empresaCod = ($empresaRaw !== '' && $empresaRaw !== '0') ? (int) $empresaRaw : 0;
        $rows[] = [
            'codigo' => trim((string) ($row['codigo'] ?? '')),
            'nombre' => trim((string) ($row['nombre'] ?? '')),
            'empresa' => $empresaCod > 0 ? $empresaCod : null,
            'empresa_nombre' => trim((string) ($row['empresa_nombre'] ?? '')),
        ];
    }
    $st->close();

    return $rows;
}

/**
 * Todos los usuarios activos con sus roles (vacío si no tiene ninguno).
 *
 * @return list<array{codigo: string, nombre: string, roles: list<array{cod_rol: string, nom_rol: string}>}>
 */
function rp_usuarios_directorio(mysqli $conn): array
{
    $usuarios = rp_usuarios_list($conn);
    $rolesPorCodigo = [];
    foreach (rp_usuarios_con_roles_list($conn) as $u) {
        $cod = trim((string) ($u['codigo'] ?? ''));
        if ($cod !== '') {
            $rolesPorCodigo[$cod] = $u['roles'] ?? [];
        }
    }

    $out = [];
    foreach ($usuarios as $u) {
        $cod = trim((string) ($u['codigo'] ?? ''));
        if ($cod === '') {
            continue;
        }
        $out[] = [
            'codigo' => $cod,
            'nombre' => trim((string) ($u['nombre'] ?? '')),
            'empresa' => $u['empresa'] ?? null,
            'empresa_nombre' => trim((string) ($u['empresa_nombre'] ?? '')),
            'roles' => $rolesPorCodigo[$cod] ?? [],
        ];
    }

    return $out;
}

/**
 * @return list<string>
 */
function rp_usuario_roles_get(mysqli $conn, string $codigo): array
{
    $detalle = rp_usuario_roles_detalle($conn, $codigo);
    $codes = [];
    foreach ($detalle as $r) {
        $codes[] = (string) $r['cod_rol'];
    }

    return $codes;
}

/**
 * Roles asignados a un usuario (código + nombre).
 *
 * @return list<array{cod_rol: string, nom_rol: string}>
 */
function rp_usuario_roles_detalle(mysqli $conn, string $codigo): array
{
    $codigo = trim($codigo);
    if ($codigo === '') {
        return [];
    }
    if (!sip_acl_table_exists($conn, 'adm_usuario_rol')) {
        return [];
    }

    $joinProg = '';
    $types = 's';
    $bindCodigo = $codigo;
    $bindProg = null;
    if (rp_rol_tiene_columna($conn, 'id_programa')) {
        try {
            $bindProg = rp_id_programa($conn);
        } catch (RuntimeException $e) {
            return [];
        }
        $joinProg = ' AND ' . rp_rol_sql_id_programa_eq('r.id_programa');
        $types = 'is';
    }

    $sql = 'SELECT aur.cod_rol,
                   COALESCE(NULLIF(TRIM(r.nom_rol), \'\'), aur.cod_rol) AS nom_rol
            FROM adm_usuario_rol aur
            INNER JOIN adm_rol r
              ON UPPER(TRIM(r.cod_rol)) = UPPER(TRIM(aur.cod_rol))'
        . $joinProg . '
            WHERE aur.codigo = ?
            ORDER BY aur.cod_rol ASC';
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    if ($bindProg !== null) {
        $st->bind_param($types, $bindProg, $bindCodigo);
    } else {
        $st->bind_param($types, $bindCodigo);
    }
    if (!$st->execute()) {
        $st->close();

        return [];
    }
    $res = $st->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $cod = trim((string) ($row['cod_rol'] ?? ''));
        if ($cod === '') {
            continue;
        }
        $rows[] = [
            'cod_rol' => $cod,
            'nom_rol' => trim((string) ($row['nom_rol'] ?? $cod)),
        ];
    }
    $st->close();

    return $rows;
}

/**
 * Usuarios activos con al menos un rol asignado.
 *
 * @return list<array{codigo: string, nombre: string, roles: list<array{cod_rol: string, nom_rol: string}>}>
 */
function rp_usuarios_con_roles_list(mysqli $conn): array
{
    if (!sip_acl_table_exists($conn, 'adm_usuario_rol')) {
        return [];
    }

    $joinProg = '';
    $types = 'si';
    $bindProg = null;
    if (rp_rol_tiene_columna($conn, 'id_programa')) {
        try {
            $bindProg = rp_id_programa($conn);
        } catch (RuntimeException $e) {
            return [];
        }
        $joinProg = ' AND ' . rp_rol_sql_id_programa_eq('r.id_programa');
    } else {
        $types = 's';
    }

    $sql = 'SELECT u.codigo,
                   u.nombre,
                   aur.cod_rol,
                   COALESCE(NULLIF(TRIM(r.nom_rol), \'\'), aur.cod_rol) AS nom_rol
            FROM adm_usuario_rol aur
            INNER JOIN usuario u ON u.codigo = aur.codigo AND u.estado = ?
            INNER JOIN adm_rol r
              ON UPPER(TRIM(r.cod_rol)) = UPPER(TRIM(aur.cod_rol))'
        . $joinProg . '
            ORDER BY u.nombre ASC, aur.cod_rol ASC';
    $st = $conn->prepare($sql);
    if (!$st) {
        return [];
    }
    $estado = 'A';
    if ($bindProg !== null) {
        $st->bind_param($types, $estado, $bindProg);
    } else {
        $st->bind_param($types, $estado);
    }
    if (!$st->execute()) {
        $st->close();

        return [];
    }
    $res = $st->get_result();
    $byCodigo = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $codigo = trim((string) ($row['codigo'] ?? ''));
        if ($codigo === '') {
            continue;
        }
        if (!isset($byCodigo[$codigo])) {
            $byCodigo[$codigo] = [
                'codigo' => $codigo,
                'nombre' => trim((string) ($row['nombre'] ?? '')),
                'roles' => [],
            ];
        }
        $codRol = trim((string) ($row['cod_rol'] ?? ''));
        if ($codRol === '') {
            continue;
        }
        $byCodigo[$codigo]['roles'][] = [
            'cod_rol' => $codRol,
            'nom_rol' => trim((string) ($row['nom_rol'] ?? $codRol)),
        ];
    }
    $st->close();

    $out = [];
    foreach ($byCodigo as $u) {
        if (($u['roles'] ?? []) !== []) {
            $out[] = $u;
        }
    }

    return $out;
}

/**
 * @param list<string> $codRoles
 */
function rp_usuario_roles_save(mysqli $conn, string $codigo, array $codRoles): array
{
    $codigo = trim($codigo);
    if ($codigo === '') {
        return ['success' => false, 'message' => 'Usuario inválido.'];
    }

    $validCodes = [];
    foreach ($codRoles as $cr) {
        $cr = trim((string) $cr);
        if ($cr !== '' && rp_rol_codigo_existe_programa($conn, $cr)) {
            $validCodes[] = $cr;
        }
    }
    $validCodes = array_values(array_unique($validCodes));

    $conn->begin_transaction();
    try {
        $stDel = $conn->prepare('DELETE FROM adm_usuario_rol WHERE codigo = ?');
        if (!$stDel) {
            throw new RuntimeException('Error al limpiar roles.');
        }
        $stDel->bind_param('s', $codigo);
        $stDel->execute();
        $stDel->close();

        if ($validCodes !== []) {
            $stIns = $conn->prepare('INSERT INTO adm_usuario_rol (codigo, cod_rol) VALUES (?, ?)');
            if (!$stIns) {
                throw new RuntimeException('Error al asignar roles.');
            }
            foreach ($validCodes as $cr) {
                $stIns->bind_param('ss', $codigo, $cr);
                $stIns->execute();
            }
            $stIns->close();
        }

        $conn->commit();

        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();

        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rp_usuario_empresa_save(mysqli $conn, string $codigo, $empresaRaw): array
{
    $codigo = trim($codigo);
    if ($codigo === '' || !rp_usuario_existe($conn, $codigo)) {
        return ['success' => false, 'message' => 'Usuario inválido.'];
    }
    rp_usuario_ensure_columna_empresa($conn);
    if (!rp_usuario_columna_existe($conn, 'empresa')) {
        return ['success' => false, 'message' => 'No se pudo crear el campo empresa en la tabla usuario.'];
    }
    $norm = sip_usuario_empresa_normalizar($conn, $empresaRaw);
    if (empty($norm['ok'])) {
        return ['success' => false, 'message' => (string) ($norm['message'] ?? 'Laboratorio no válido.')];
    }
    $empCod = $norm['codigo'];
    if ($empCod === null) {
        $st = $conn->prepare('UPDATE usuario SET empresa = NULL WHERE codigo = ?');
        if (!$st) {
            return ['success' => false, 'message' => 'Error al actualizar la empresa.'];
        }
        $st->bind_param('s', $codigo);
    } else {
        $st = $conn->prepare('UPDATE usuario SET empresa = ? WHERE codigo = ?');
        if (!$st) {
            return ['success' => false, 'message' => 'Error al actualizar la empresa.'];
        }
        $st->bind_param('is', $empCod, $codigo);
    }
    $ok = $st->execute();
    $err = $st->error;
    $st->close();
    if (!$ok) {
        return ['success' => false, 'message' => 'No se pudo guardar la empresa.' . ($err !== '' ? ' ' . $err : '')];
    }

    return ['success' => true, 'empresa' => $empCod];
}

/**
 * @return array{cod_mods: list<string>, idx_paths: list<string>}
 */
function rp_scope_get(mysqli $conn, int $idRol): array
{
    $idProg = rp_id_programa($conn);
    $codMods = [];
    $st = $conn->prepare('SELECT cod_mod FROM adm_rol_progr_modulo WHERE id_rol = ? AND id_programa = ?');
    if ($st) {
        $st->bind_param('ii', $idRol, $idProg);
        $st->execute();
        $res = $st->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $c = trim((string) ($row['cod_mod'] ?? ''));
            if ($c !== '') {
                $codMods[] = $c;
            }
        }
        $st->close();
    }

    $idxPaths = [];
    if (sip_acl_table_exists($conn, 'adm_rol_proceso_idxpath')) {
        $st2 = $conn->prepare('SELECT idx_path FROM adm_rol_proceso_idxpath WHERE id_rol = ? AND id_programa = ?');
        if ($st2) {
            $st2->bind_param('ii', $idRol, $idProg);
            $st2->execute();
            $res2 = $st2->get_result();
            while ($res2 && ($row = $res2->fetch_assoc())) {
                $p = trim((string) ($row['idx_path'] ?? ''));
                if ($p !== '') {
                    $idxPaths[] = $p;
                }
            }
            $st2->close();
        }
    }

    return ['cod_mods' => $codMods, 'idx_paths' => $idxPaths];
}

/**
 * @param list<string> $codMods
 * @param list<string> $idxPaths
 */
function rp_scope_save(mysqli $conn, int $idRol, array $codMods, array $idxPaths, string $userUpdate): array
{
    $rows = sip_menu_catalog_load_rows($conn, rp_id_programa($conn));
    $codMods = sip_menu_catalog_expand_cod_mods($codMods, $rows);

    $idxPaths = array_values(array_unique(array_filter(array_map(
        static function ($p) {
            return trim((string) $p);
        },
        $idxPaths
    ))));

    // Si el rol tiene la sección «Procesos» (grp-sp-2 / item-sp-2-0), el árbol de procesos
    // queda concedido por completo: descartamos los idx_paths para no recortar nodos nuevos
    // que se agreguen al árbol en el futuro.
    if (in_array('grp-sp-2', $codMods, true) || in_array('item-sp-2-0', $codMods, true)) {
        $idxPaths = [];
    }

    $idProg = rp_id_programa($conn);
    $conn->begin_transaction();
    try {
        $stDel = $conn->prepare('DELETE FROM adm_rol_progr_modulo WHERE id_rol = ? AND id_programa = ?');
        if (!$stDel) {
            throw new RuntimeException('Error al limpiar permisos.');
        }
        $stDel->bind_param('ii', $idRol, $idProg);
        $stDel->execute();
        $stDel->close();

        if ($codMods !== []) {
            $stIns = $conn->prepare(
                'INSERT INTO adm_rol_progr_modulo (id_rol, id_programa, cod_mod, user_update, fecha_actualizacion)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            if (!$stIns) {
                throw new RuntimeException('Error al guardar permisos.');
            }
            foreach ($codMods as $cm) {
                $stIns->bind_param('iiss', $idRol, $idProg, $cm, $userUpdate);
                $stIns->execute();
            }
            $stIns->close();
        }

        if (sip_acl_table_exists($conn, 'adm_rol_proceso_idxpath')) {
            $stDel2 = $conn->prepare('DELETE FROM adm_rol_proceso_idxpath WHERE id_rol = ? AND id_programa = ?');
            if ($stDel2) {
                $stDel2->bind_param('ii', $idRol, $idProg);
                $stDel2->execute();
                $stDel2->close();
            }
            if ($idxPaths !== []) {
                $stIns2 = $conn->prepare(
                    'INSERT INTO adm_rol_proceso_idxpath (id_rol, id_programa, idx_path) VALUES (?, ?, ?)'
                );
                if ($stIns2) {
                    foreach ($idxPaths as $path) {
                        $stIns2->bind_param('iis', $idRol, $idProg, $path);
                        $stIns2->execute();
                    }
                    $stIns2->close();
                }
            }
        }

        $conn->commit();

        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();

        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * @param list<array<string, mixed>> $nodes
 */
function rp_scope_collect_checked(array $nodes, array &$codMods, array &$idxPaths): void
{
    foreach ($nodes as $n) {
        if (!is_array($n)) {
            continue;
        }
        if (!empty($n['checked'])) {
            $kind = (string) ($n['kind'] ?? '');
            if ($kind === 'idx_path' && !empty($n['idx_path'])) {
                $idxPaths[] = (string) $n['idx_path'];
            } elseif (!empty($n['cod_mod'])) {
                $codMods[] = (string) $n['cod_mod'];
            }
        }
        $ch = $n['children'] ?? [];
        if (is_array($ch) && $ch !== []) {
            rp_scope_collect_checked($ch, $codMods, $idxPaths);
        }
    }
}

function rp_menu_next_orden(mysqli $conn, int $idPrograma, ?string $parentCod): int
{
    $idProgStr = (string) $idPrograma;
    if ($parentCod === null || $parentCod === '') {
        $st = $conn->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 AS n FROM amd_dashboard_modulos WHERE id_programa = ? AND (parent_cod IS NULL OR parent_cod = \'\')'
        );
        if (!$st) {
            return 1;
        }
        $st->bind_param('s', $idProgStr);
    } else {
        $st = $conn->prepare(
            'SELECT COALESCE(MAX(orden), 0) + 1 AS n FROM amd_dashboard_modulos WHERE id_programa = ? AND parent_cod = ?'
        );
        if (!$st) {
            return 1;
        }
        $st->bind_param('ss', $idProgStr, $parentCod);
    }
    $st->execute();
    $res = $st->get_result();
    $n = 1;
    if ($res && ($row = $res->fetch_assoc())) {
        $n = (int) ($row['n'] ?? 1);
    }
    $st->close();

    return $n;
}

function rp_menu_generate_cod_mod(mysqli $conn, string $prefix = 'mod'): string
{
    do {
        $cod = $prefix . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $st = $conn->prepare('SELECT id FROM amd_dashboard_modulos WHERE cod_mod = ? AND id_programa = ? LIMIT 1');
        if (!$st) {
            break;
        }
        $idProgStr = (string) rp_id_programa($conn);
        $st->bind_param('ss', $cod, $idProgStr);
        $st->execute();
        $exists = $st->get_result()->fetch_assoc();
        $st->close();
    } while ($exists);

    return $cod;
}

/**
 * @param array<string, mixed> $data
 */
function rp_menu_node_save(mysqli $conn, array $data): array
{
    $codMod = trim((string) ($data['cod_mod'] ?? ''));
    $nom = trim((string) ($data['nom_mod'] ?? ''));
    $label = trim((string) ($data['label_short'] ?? ''));
    $url = trim((string) ($data['url'] ?? ''));
    $icon = trim((string) ($data['icono'] ?? 'fa-folder'));
    $tipo = sip_menu_catalog_tipo_for_db(trim((string) ($data['tipo'] ?? 'item')));
    $parent = trim((string) ($data['parent_cod'] ?? ''));
    $parent = $parent === '' ? null : $parent;
    $orden = (int) ($data['orden'] ?? 0);

    if ($codMod === '') {
        return ['success' => false, 'message' => 'Código de módulo inválido.'];
    }
    if ($nom === '') {
        $nom = $label !== '' ? $label : $codMod;
    }

    $idProgStr = (string) rp_id_programa($conn);
    $st = $conn->prepare(
        'INSERT INTO amd_dashboard_modulos (id_programa, cod_mod, tipo, parent_cod, nom_mod, label_short, icono, url, orden)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           tipo = VALUES(tipo),
           parent_cod = VALUES(parent_cod),
           nom_mod = VALUES(nom_mod),
           label_short = VALUES(label_short),
           icono = VALUES(icono),
           url = VALUES(url),
           orden = VALUES(orden)'
    );
    if (!$st) {
        return ['success' => false, 'message' => 'Error al guardar nodo.'];
    }
    $st->bind_param('ssssssssi', $idProgStr, $codMod, $tipo, $parent, $nom, $label, $icon, $url, $orden);
    $st->execute();
    $st->close();

    return ['success' => true, 'cod_mod' => $codMod];
}

function rp_menu_node_add(mysqli $conn, ?string $parentCod, string $tipo): array
{
    $cod = rp_menu_generate_cod_mod($conn, 'mod');
    $orden = rp_menu_next_orden($conn, rp_id_programa($conn), $parentCod);
    $nom = $tipo === 'item' ? 'Nuevo enlace' : ($tipo === 'config_group' ? 'Nuevo grupo config' : 'Nueva sección');

    return rp_menu_node_save($conn, [
        'cod_mod' => $cod,
        'nom_mod' => $nom,
        'label_short' => $nom,
        'url' => '',
        'icono' => $tipo === 'item' ? 'fa-link' : 'fa-folder',
        'tipo' => $tipo,
        'parent_cod' => $parentCod,
        'orden' => $orden,
    ]);
}

function rp_menu_reindex_siblings(mysqli $conn, ?string $parentCod): void
{
    $idProgStr = (string) rp_id_programa($conn);
    if ($parentCod === null || $parentCod === '') {
        $st = $conn->prepare(
            'SELECT cod_mod FROM amd_dashboard_modulos WHERE id_programa = ? AND (parent_cod IS NULL OR parent_cod = \'\') ORDER BY orden ASC, id ASC'
        );
        if (!$st) {
            return;
        }
        $st->bind_param('s', $idProgStr);
    } else {
        $st = $conn->prepare(
            'SELECT cod_mod FROM amd_dashboard_modulos WHERE id_programa = ? AND parent_cod = ? ORDER BY orden ASC, id ASC'
        );
        if (!$st) {
            return;
        }
        $st->bind_param('ss', $idProgStr, $parentCod);
    }
    $st->execute();
    $res = $st->get_result();
    $codes = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $codes[] = (string) ($row['cod_mod'] ?? '');
    }
    $st->close();

    if ($codes === []) {
        return;
    }

    $stUp = $conn->prepare('UPDATE amd_dashboard_modulos SET orden = ? WHERE id_programa = ? AND cod_mod = ?');
    if (!$stUp) {
        return;
    }
    $orden = 1;
    foreach ($codes as $cod) {
        if ($cod === '') {
            continue;
        }
        $stUp->bind_param('iss', $orden, $idProgStr, $cod);
        $stUp->execute();
        $orden++;
    }
    $stUp->close();
}

function rp_menu_node_delete(mysqli $conn, string $codMod): array
{
    if ($codMod === '' || $codMod === '__menu_root__') {
        return ['success' => false, 'message' => 'No se puede eliminar el nodo raíz.'];
    }

    $idProgStr = (string) rp_id_programa($conn);
    $parentCod = null;
    $stParent = $conn->prepare('SELECT parent_cod FROM amd_dashboard_modulos WHERE id_programa = ? AND cod_mod = ? LIMIT 1');
    if ($stParent) {
        $stParent->bind_param('ss', $idProgStr, $codMod);
        $stParent->execute();
        $parentRow = $stParent->get_result()->fetch_assoc();
        $stParent->close();
        if (!$parentRow) {
            return ['success' => false, 'message' => 'Nodo no encontrado.'];
        }
        $parentRaw = $parentRow['parent_cod'] ?? null;
        $parentCod = ($parentRaw === null || $parentRaw === '') ? null : (string) $parentRaw;
    }

    $stCh = $conn->prepare('SELECT COUNT(*) AS c FROM amd_dashboard_modulos WHERE id_programa = ? AND parent_cod = ?');
    if ($stCh) {
        $stCh->bind_param('ss', $idProgStr, $codMod);
        $stCh->execute();
        $row = $stCh->get_result()->fetch_assoc();
        $stCh->close();
        if ($row && (int) ($row['c'] ?? 0) > 0) {
            return ['success' => false, 'message' => 'Elimine primero los elementos hijos.'];
        }
    }

    $st = $conn->prepare('DELETE FROM amd_dashboard_modulos WHERE id_programa = ? AND cod_mod = ?');
    if (!$st) {
        return ['success' => false, 'message' => 'Error al eliminar.'];
    }
    $st->bind_param('ss', $idProgStr, $codMod);
    $st->execute();
    $ok = $st->affected_rows > 0;
    $st->close();

    if ($ok) {
        rp_menu_reindex_siblings($conn, $parentCod);

        return ['success' => true];
    }

    return ['success' => false, 'message' => 'Nodo no encontrado.'];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function rp_menu_collect_descendant_cods(array $rows, string $rootCod): array
{
    $out = [];
    $queue = [$rootCod];
    while ($queue !== []) {
        $parent = array_shift($queue);
        foreach ($rows as $row) {
            $pad = trim((string) ($row['parent_cod'] ?? $row['cod_padre'] ?? ''));
            $cod = trim((string) ($row['cod_mod'] ?? ''));
            if ($cod === '' || $pad !== $parent) {
                continue;
            }
            $out[] = $cod;
            $queue[] = $cod;
        }
    }

    return $out;
}

/**
 * Mueve un nodo a otra rama (cambia parent_cod) y reordena hermanos origen/destino.
 */
function rp_menu_node_reparent(mysqli $conn, string $codMod, ?string $newParentCod): array
{
    if ($codMod === '' || $codMod === '__menu_root__') {
        return ['success' => false, 'message' => 'El nodo raíz no se puede mover.'];
    }

    $idProg = rp_id_programa($conn);
    $idProgStr = (string) $idProg;
    $rows = sip_acl_load_modulos($conn, $idProg);
    if ($rows === []) {
        return ['success' => false, 'message' => 'No hay módulos en el catálogo.'];
    }

    $currentParent = null;
    $nodeExists = false;
    foreach ($rows as $row) {
        if ((string) ($row['cod_mod'] ?? '') !== $codMod) {
            continue;
        }
        $nodeExists = true;
        $padRaw = $row['parent_cod'] ?? $row['cod_padre'] ?? null;
        $currentParent = ($padRaw === null || $padRaw === '') ? null : (string) $padRaw;
        break;
    }
    if (!$nodeExists) {
        return ['success' => false, 'message' => 'Nodo no encontrado.'];
    }

    $newParentNorm = ($newParentCod === null || $newParentCod === '') ? null : $newParentCod;
    if ($newParentNorm === $codMod) {
        return ['success' => false, 'message' => 'Un nodo no puede ser padre de sí mismo.'];
    }

    if ($newParentNorm !== null) {
        $parentFound = false;
        foreach ($rows as $row) {
            if ((string) ($row['cod_mod'] ?? '') === $newParentNorm) {
                $parentFound = true;
                break;
            }
        }
        if (!$parentFound) {
            return ['success' => false, 'message' => 'La sección destino no existe.'];
        }
        $descendants = rp_menu_collect_descendant_cods($rows, $codMod);
        if (in_array($newParentNorm, $descendants, true)) {
            return ['success' => false, 'message' => 'No puede mover un nodo dentro de sus propios hijos.'];
        }
    }

    if ($newParentNorm === $currentParent) {
        return ['success' => true, 'message' => 'Sin cambio de rama.'];
    }

    $newOrden = rp_menu_next_orden($conn, $idProg, $newParentNorm);
    if ($newParentNorm === null) {
        $st = $conn->prepare(
            'UPDATE amd_dashboard_modulos SET parent_cod = NULL, orden = ? WHERE id_programa = ? AND cod_mod = ?'
        );
        if (!$st) {
            return ['success' => false, 'message' => 'Error al cambiar de rama.'];
        }
        $st->bind_param('iss', $newOrden, $idProgStr, $codMod);
    } else {
        $st = $conn->prepare(
            'UPDATE amd_dashboard_modulos SET parent_cod = ?, orden = ? WHERE id_programa = ? AND cod_mod = ?'
        );
        if (!$st) {
            return ['success' => false, 'message' => 'Error al cambiar de rama.'];
        }
        $st->bind_param('siss', $newParentNorm, $newOrden, $idProgStr, $codMod);
    }
    $st->execute();
    $ok = $st->affected_rows >= 0;
    $st->close();
    if (!$ok) {
        return ['success' => false, 'message' => 'No se pudo actualizar el nodo.'];
    }

    rp_menu_reindex_siblings($conn, $currentParent);
    rp_menu_reindex_siblings($conn, $newParentNorm);

    return ['success' => true, 'cod_mod' => $codMod, 'parent_cod' => $newParentNorm];
}

function rp_menu_node_move(mysqli $conn, string $codMod, string $direction): array
{
    if ($codMod === '' || $codMod === '__menu_root__') {
        return ['success' => false, 'message' => 'El nodo raíz no se puede mover.'];
    }

    $idProgStr = (string) rp_id_programa($conn);
    $st = $conn->prepare(
        'SELECT cod_mod, parent_cod, orden FROM amd_dashboard_modulos WHERE id_programa = ? AND cod_mod = ? LIMIT 1'
    );
    if (!$st) {
        return ['success' => false, 'message' => 'Nodo no encontrado.'];
    }
    $st->bind_param('ss', $idProgStr, $codMod);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return ['success' => false, 'message' => 'Nodo no encontrado.'];
    }

    $parent = $row['parent_cod'];
    $orden = (int) $row['orden'];
    $isUp = ($direction === 'up');

    if ($parent === null || $parent === '') {
        $stSib = $conn->prepare(
            'SELECT cod_mod, orden FROM amd_dashboard_modulos WHERE id_programa = ? AND (parent_cod IS NULL OR parent_cod = \'\') ORDER BY orden ASC'
        );
        $stSib->bind_param('s', $idProgStr);
    } else {
        $stSib = $conn->prepare(
            'SELECT cod_mod, orden FROM amd_dashboard_modulos WHERE id_programa = ? AND parent_cod = ? ORDER BY orden ASC'
        );
        $stSib->bind_param('ss', $idProgStr, $parent);
    }
    $stSib->execute();
    $siblings = [];
    $res = $stSib->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $siblings[] = $r;
    }
    $stSib->close();

    $idx = -1;
    foreach ($siblings as $i => $s) {
        if ((string) $s['cod_mod'] === $codMod) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        return ['success' => false, 'message' => 'Nodo no encontrado en hermanos.'];
    }

    $swapIdx = $isUp ? $idx - 1 : $idx + 1;
    if ($swapIdx < 0 || $swapIdx >= count($siblings)) {
        return ['success' => true, 'message' => 'Sin cambio de posición.'];
    }

    $other = $siblings[$swapIdx];
    $otherCod = (string) $other['cod_mod'];
    $otherOrden = (int) $other['orden'];

    $conn->begin_transaction();
    try {
        $st1 = $conn->prepare('UPDATE amd_dashboard_modulos SET orden = ? WHERE id_programa = ? AND cod_mod = ?');
        $st2 = $conn->prepare('UPDATE amd_dashboard_modulos SET orden = ? WHERE id_programa = ? AND cod_mod = ?');
        if (!$st1 || !$st2) {
            throw new RuntimeException('Error al reordenar.');
        }
        $st1->bind_param('iss', $otherOrden, $idProgStr, $codMod);
        $st1->execute();
        $st1->close();
        $st2->bind_param('iss', $orden, $idProgStr, $otherCod);
        $st2->execute();
        $st2->close();
        $conn->commit();

        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();

        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Sincroniza el catálogo completo del menú lateral (index.php) en amd_dashboard_modulos.
 */
function rp_seed_menu_item(mysqli $conn): array
{
    return sip_menu_catalog_seed_modulos($conn, rp_id_programa($conn));
}

/**
 * Persiste la estructura completa del árbol (orden, padres, nombres) y elimina nodos marcados.
 *
 * @param list<array<string, mixed>> $nodes
 * @param list<string> $deleteCods
 * @return array{success: bool, message?: string, saved?: int}
 */
function rp_menu_tree_save(mysqli $conn, array $nodes, array $deleteCods = []): array
{
    $deleteCods = array_values(array_unique(array_filter(array_map(static function ($c) {
        return trim((string) $c);
    }, $deleteCods))));

    $conn->begin_transaction();
    try {
        foreach ($deleteCods as $cod) {
            if ($cod === '' || $cod === '__menu_root__') {
                continue;
            }
            $del = rp_menu_node_delete($conn, $cod);
            if (empty($del['success'])) {
                throw new RuntimeException((string) ($del['message'] ?? 'No se pudo eliminar el módulo.'));
            }
        }

        $saved = 0;
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            $cod = trim((string) ($n['cod_mod'] ?? ''));
            if ($cod === '' || $cod === '__menu_root__') {
                continue;
            }
            $parent = trim((string) ($n['parent_cod'] ?? ''));
            $r = rp_menu_node_save($conn, [
                'cod_mod' => $cod,
                'nom_mod' => trim((string) ($n['nom_mod'] ?? '')),
                'label_short' => trim((string) ($n['label_short'] ?? $n['nom_mod'] ?? '')),
                'icono' => trim((string) ($n['icono'] ?? 'fa-folder')),
                'url' => trim((string) ($n['url'] ?? '')),
                'tipo' => trim((string) ($n['tipo'] ?? 'item')),
                'parent_cod' => $parent,
                'orden' => (int) ($n['orden'] ?? 0),
            ]);
            if (empty($r['success'])) {
                throw new RuntimeException((string) ($r['message'] ?? 'Error al guardar el menú.'));
            }
            $saved++;
        }

        $conn->commit();

        return [
            'success' => true,
            'saved' => $saved,
            'message' => 'Estructura del menú guardada.',
        ];
    } catch (Throwable $e) {
        $conn->rollback();

        return ['success' => false, 'message' => $e->getMessage()];
    }
}
