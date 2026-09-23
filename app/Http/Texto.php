<?php

declare(strict_types=1);

namespace App\Http;

/** Utilidades de texto para respuestas (codificación utf8). */
final class Texto
{
    /** Convierte a UTF-8 cuando la cadena viene en ISO-8859-1. */
    public static function utf8($valor)
    {
        if (!is_string($valor)) {
            return $valor;
        }
        if (mb_check_encoding($valor, 'UTF-8')) {
            return $valor;
        }
        return mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-1');
    }
}
