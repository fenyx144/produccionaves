<?php

declare(strict_types=1);

/**
 * Campo usuario.empresa = código de san_dim_laboratorio.
 * Vacío/NULL = el usuario ve todos los laboratorios.
 */
function sip_usuario_columna_empresa_existe(mysqli $conn, bool $refresh = false): bool
{
    static $existe = null;
    if ($refresh || $existe === null) {
        $r = @$conn->query("SHOW COLUMNS FROM usuario LIKE 'empresa'");
        $existe = ($r && $r->num_rows > 0);
    }

    return (bool) $existe;
}

function sip_usuario_ensure_columna_empresa(mysqli $conn): bool
{
    if (sip_usuario_columna_empresa_existe($conn)) {
        return true;
    }
    $ok = @$conn->query('ALTER TABLE usuario ADD COLUMN empresa INT NULL DEFAULT NULL');
    sip_usuario_columna_empresa_existe($conn, true);

    return $ok ? true : sip_usuario_columna_empresa_existe($conn);
}

/**
 * @return list<array{codigo: int, nombre: string}>
 */
function sip_laboratorios_list(mysqli $conn): array
{
    $r = @$conn->query('SELECT codigo, nombre FROM san_dim_laboratorio ORDER BY nombre ASC');
    if (!$r) {
        return [];
    }
    $out = [];
    while ($row = $r->fetch_assoc()) {
        $cod = (int) ($row['codigo'] ?? 0);
        $nom = trim((string) ($row['nombre'] ?? ''));
        if ($cod > 0 && $nom !== '') {
            $out[] = ['codigo' => $cod, 'nombre' => $nom];
        }
    }

    return $out;
}

/**
 * @return array{ok: bool, codigo: int|null, message?: string}
 */
function sip_usuario_empresa_normalizar(mysqli $conn, $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '0') {
        return ['ok' => true, 'codigo' => null];
    }
    if (!preg_match('/^\d+$/', $raw)) {
        return ['ok' => false, 'codigo' => null, 'message' => 'Laboratorio no válido.'];
    }
    $cod = (int) $raw;
    if ($cod <= 0) {
        return ['ok' => true, 'codigo' => null];
    }
    $st = $conn->prepare('SELECT codigo FROM san_dim_laboratorio WHERE codigo = ? LIMIT 1');
    if (!$st) {
        return ['ok' => false, 'codigo' => null, 'message' => 'No se pudo validar el laboratorio.'];
    }
    $st->bind_param('i', $cod);
    $st->execute();
    $found = $st->get_result()->fetch_assoc() !== null;
    $st->close();
    if (!$found) {
        return ['ok' => false, 'codigo' => null, 'message' => 'El laboratorio seleccionado no existe.'];
    }

    return ['ok' => true, 'codigo' => $cod];
}

/**
 * Laboratorio asignado al usuario de sesión/login. null = sin restricción.
 *
 * @return array{codigo: int, nombre: string}|null
 */
function sip_usuario_empresa_laboratorio(mysqli $conn, string $codigoUsuario): ?array
{
    sip_usuario_ensure_columna_empresa($conn);
    if (!sip_usuario_columna_empresa_existe($conn)) {
        return null;
    }
    $codigoUsuario = trim($codigoUsuario);
    if ($codigoUsuario === '') {
        return null;
    }
    $st = $conn->prepare(
        'SELECT u.empresa, l.codigo AS lab_codigo, l.nombre AS lab_nombre
         FROM usuario u
         LEFT JOIN san_dim_laboratorio l ON l.codigo = u.empresa
         WHERE u.codigo = ?
         LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('s', $codigoUsuario);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }
    $empRaw = trim((string) ($row['empresa'] ?? ''));
    if ($empRaw === '' || $empRaw === '0') {
        return null;
    }
    $labCodigo = (int) ($row['lab_codigo'] ?? 0);
    if ($labCodigo <= 0) {
        $labCodigo = (int) $empRaw;
    }
    if ($labCodigo <= 0) {
        return null;
    }
    $labNombre = trim((string) ($row['lab_nombre'] ?? ''));

    return [
        'codigo' => $labCodigo,
        'nombre' => $labNombre,
    ];
}
