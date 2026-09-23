<?php

declare(strict_types=1);

namespace App\Http;

/** Respuestas JSON con el contrato {status, success, message, errorCode, data}. */
final class Respuesta
{
    /**
     * @param int    $status    código HTTP
     * @param bool   $success   estado de la operación
     * @param string $message   mensaje legible
     * @param string|null $errorCode código de error de negocio
     * @param mixed  $data      payload adicional
     * @param bool   $salir     termina el proceso al responder
     */
    public static function json(int $status, bool $success, string $message, ?string $errorCode = null, $data = null, bool $salir = true): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        $payload = [
            'status' => $status,
            'success' => $success,
            'message' => $message,
        ];
        if ($errorCode !== null) {
            $payload['errorCode'] = $errorCode;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json !== false ? $json : '{"status":500,"success":false,"message":"Error al generar JSON"}';
        if ($salir) {
            exit;
        }
    }

    /** Emite 200 success:true y deja que el proceso continúe (post-procesamiento). */
    public static function continuar($data): void
    {
        self::json(200, true, 'Inicio de sesión exitoso', null, $data, false);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }
}
