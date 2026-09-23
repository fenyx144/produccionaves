<?php

declare(strict_types=1);

/**
 * Normaliza el código de usuario ingresado en login / recuperación.
 */
function sip_usuario_codigo_norm(string $usuario): string
{
    return strtoupper(trim($usuario));
}

/**
 * Código exacto almacenado en BD (para FK como san_correo_sanidad).
 */
function sip_usuario_codigo_db(mysqli $conn, string $usuario): ?string
{
    $norm = sip_usuario_codigo_norm($usuario);
    if ($norm === '') {
        return null;
    }
    $st = $conn->prepare(
        "SELECT codigo FROM usuario WHERE UPPER(TRIM(codigo)) = ? AND estado = 'A' LIMIT 1"
    );
    if (!$st) {
        return null;
    }
    $st->bind_param('s', $norm);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || !isset($row['codigo'])) {
        return null;
    }
    $cod = (string) $row['codigo'];

    return $cod !== '' ? $cod : null;
}

/**
 * Verifica usuario activo + contraseña (misma regla que login y recuperación).
 *
 * @return array{codigo: string, nombre: string}|null
 */
function sip_password_authenticate(mysqli $conn, string $usuario, string $clave): ?array
{
    $norm = sip_usuario_codigo_norm($usuario);
    $clave = trim($clave);
    if ($norm === '' || $clave === '') {
        return null;
    }

    $sql = "SELECT u.codigo, u.nombre
            FROM usuario u
            CROSS JOIN (
                SELECT enom FROM conempre WHERE epre = 'RS' LIMIT 1
            ) c
            WHERE UPPER(TRIM(u.codigo)) = ?
            AND u.password = LEFT(AES_ENCRYPT(?, c.enom), 8)
            AND u.estado = 'A'
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        return null;
    }
    $st->bind_param('ss', $norm, $clave);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }

    return [
        'codigo' => (string) $row['codigo'],
        'nombre' => (string) $row['nombre'],
    ];
}

/**
 * Actualiza contraseña usando la misma clave conempre (LIMIT 1) que el login.
 */
function sip_password_set(mysqli $conn, string $usuario, string $claveNueva): bool
{
    $norm = sip_usuario_codigo_norm($usuario);
    $claveNueva = trim($claveNueva);
    if ($norm === '' || $claveNueva === '') {
        return false;
    }

    $stEmp = $conn->prepare("SELECT enom FROM conempre WHERE epre = 'RS' LIMIT 1");
    if (!$stEmp) {
        return false;
    }
    $stEmp->execute();
    $rowEmp = $stEmp->get_result()->fetch_assoc();
    $stEmp->close();
    if (!$rowEmp || trim((string) ($rowEmp['enom'] ?? '')) === '') {
        return false;
    }

    $sql = "UPDATE usuario
            SET password = (
                SELECT LEFT(AES_ENCRYPT(?, c.enom), 8)
                FROM conempre c
                WHERE c.epre = 'RS'
                LIMIT 1
            )
            WHERE UPPER(TRIM(codigo)) = ?
            AND estado = 'A'";
    $st = $conn->prepare($sql);
    if (!$st) {
        return false;
    }
    $st->bind_param('ss', $claveNueva, $norm);
    $ok = $st->execute();
    $st->close();

    return $ok;
}
