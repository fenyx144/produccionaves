<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\ErrorApi;

/** Consultas de usuario y dispositivos móviles (login móvil). */
final class UsuarioModel
{
    /** Fila de usuario activo con enom; null si credenciales incorrectas. */
    public static function login(\mysqli $conn, string $usuario, string $newPassword): ?array
    {
        $sql = "SELECT u.codigo, u.nombre, u.rol_sanidad, u.libtri, u.estado, c.enom
                FROM usuario u
                JOIN conempre c ON c.epre='RS'
                WHERE u.codigo=?
                  AND u.password = LEFT(AES_ENCRYPT(?, c.enom), 8)
                  AND u.estado='A'
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new ErrorApi(500, 'Error en la consulta SQL', 'SQL_ERROR');
        }
        $stmt->bind_param('ss', $usuario, $newPassword);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Existencia y estado de un usuario por código (diagnóstico de login). */
    public static function buscarPorCodigo(\mysqli $conn, string $usuario): ?array
    {
        $sql = "SELECT u.codigo, u.estado, u.nombre
                FROM usuario u
                WHERE u.codigo=? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new ErrorApi(500, 'Error en la consulta SQL', 'SQL_ERROR');
        }
        $stmt->bind_param('s', $usuario);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Dispositivo por android id y destino (app). */
    public static function dispositivo(\mysqli $conn, string $android, string $app): ?array
    {
        $sql = "SELECT tidandroid, testado, tfabricante, tmodelo
                FROM regnewdispositivosandroid
                WHERE tidandroid=? AND tdestino=?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new ErrorApi(500, 'Error en la consulta SQL', 'SQL_ERROR');
        }
        $stmt->bind_param('ss', $android, $app);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Registra un dispositivo nuevo (estado activo). */
    public static function dispositivoInsertar(
        \mysqli $conn,
        string $fecha,
        string $hora,
        string $android,
        string $fabricante,
        string $familia,
        string $modelo,
        string $descripcion,
        string $app
    ): bool {
        $sql = "INSERT INTO regnewdispositivosandroid
                (tdate, ttime, tidandroid, tfabricante, tfamilia, tmodelo, tdescripcion, testado, tdestino)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'A', ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new ErrorApi(500, 'Error al preparar la consulta de inserción', 'SQL_ERROR');
        }
        $stmt->bind_param('ssssssss', $fecha, $hora, $android, $fabricante, $familia, $modelo, $descripcion, $app);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Actualización silenciosa de versión de dispositivo y último acceso. */
    public static function registrarUso(
        \mysqli $conn,
        string $version,
        string $usuario,
        string $fecha,
        string $hora,
        string $fechaHoraActual,
        string $android,
        string $app
    ): void {
        $sqlDev = "UPDATE regnewdispositivosandroid SET tversion=?, tuser_ing=?, tdate_ing=?, ttime_ing=?
                   WHERE tidandroid=? AND tdestino=?";
        $st = $conn->prepare($sqlDev);
        if ($st) {
            $st->bind_param('ssssss', $version, $usuario, $fecha, $hora, $android, $app);
            @$st->execute();
            $st->close();
        }
        $sqlUsr = "UPDATE usuario SET ultimo_acceso=? WHERE codigo=?";
        $st2 = $conn->prepare($sqlUsr);
        if ($st2) {
            $st2->bind_param('ss', $fechaHoraActual, $usuario);
            @$st2->execute();
            $st2->close();
        }
    }
}
