<?php

declare(strict_types=1);

namespace App\Http;

/** Lectura de entrada HTTP (GET, POST y body JSON). */
final class Request
{
    /** Mapa JSON del body; [] si no es un array válido. */
    public static function bodyJson(): array
    {
        $json = file_get_contents('php://input');
        $obj = json_decode((string) $json, true);
        return is_array($obj) ? $obj : [];
    }

    public static function get(string $clave, string $defecto = ''): string
    {
        return trim((string) ($_GET[$clave] ?? $defecto));
    }

    public static function post(string $clave, string $defecto = ''): string
    {
        return trim((string) ($_POST[$clave] ?? $defecto));
    }

    public static function input(string $clave, string $defecto = ''): string
    {
        return trim((string) ($_GET[$clave] ?? $_POST[$clave] ?? $defecto));
    }

    /** Valor crudo de GET/POST sin normalizar. */
    public static function val(string $clave, $defecto = null)
    {
        return $_GET[$clave] ?? $_POST[$clave] ?? $defecto;
    }
}
