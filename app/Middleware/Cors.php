<?php

declare(strict_types=1);

namespace App\Middleware;

/** Cabeceras CORS y respuesta JSON; termina en OPTIONS. */
final class Cors
{
    public static function aplicar(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
    }
}
