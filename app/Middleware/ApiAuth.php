<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Respuesta;

/** Validación del token Bearer estático (API_TOKEN). */
final class ApiAuth
{
    public static function requireToken(): void
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($auth === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (!defined('API_TOKEN') || $auth !== 'Bearer ' . API_TOKEN) {
            Respuesta::json(401, false, 'Acceso denegado. Token inválido', 'INVALID_TOKEN');
        }
    }
}
