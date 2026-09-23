<?php

declare(strict_types=1);

namespace App\Http;

/** Conexión a la BD de Joya vía conexion_grs compartida. */
final class Db
{
    public static function joya(): \mysqli
    {
        $link = mysqli_init();
        if ($link) {
            mysqli_options($link, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            @$link->real_connect(DB_HOST_JOYA, DB_USER_JOYA, DB_PASSWORD_JOYA, DB_NAME_JOYA);
            if (!$link->connect_errno) {
                $link->set_charset('utf8');
                return $link;
            }
            $link->close();
        }
        Respuesta::json(500, false, 'Error de conexión a la base de datos', 'DB_CONNECTION_ERROR');
    }
}
