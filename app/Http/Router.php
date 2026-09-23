<?php

declare(strict_types=1);

namespace App\Http;

/** Enrutador: mapa método + path hacia Controlador@método. */
final class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> */
    private $rutas = ['GET' => [], 'POST' => []];

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $path, array $handler): void
    {
        $this->rutas['GET'][$path] = $handler;
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->rutas['POST'][$path] = $handler;
    }

    public function despachar(string $metodo, string $path): void
    {
        $handler = $this->rutas[strtoupper($metodo)][$path] ?? null;
        if ($handler === null) {
            Respuesta::json(404, false, 'Ruta no encontrada', 'NOT_FOUND');
        }
        [$clase, $accion] = $handler;
        $controlador = new $clase();
        $controlador->$accion();
    }
}
