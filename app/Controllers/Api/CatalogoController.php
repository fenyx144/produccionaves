<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Db;
use App\Http\Request;
use App\Http\Respuesta;
use App\Models\CatalogoModel;

/** Catálogos de solo lectura para la app de mortalidad. */
final class CatalogoController
{
    public function downloadCatalogos(): void
    {
        $conexion = Db::joya();
        $tablasRaw = Request::input('tablas');
        $tablas = $tablasRaw !== '' ? array_map('trim', explode(',', $tablasRaw)) : [];
        $data = CatalogoModel::tablasDescarga($conexion, $tablas);
        $conexion->close();
        Respuesta::json(200, true, 'Datos descargados correctamente', null, $data);
    }

    public function motivos(): void
    {
        $conexion = Db::joya();
        mysqli_set_charset($conexion, 'latin1');
        $data = CatalogoModel::motivos($conexion);
        $conexion->close();
        Respuesta::json(200, true, $data['total'] > 0 ? 'Motivos cargados' : 'Sin motivos', null, $data);
    }

    public function cencosGalpones(): void
    {
        set_time_limit(120);
        $conexion = Db::joya();
        mysqli_set_charset($conexion, 'latin1');
        mysqli_query($conexion, "SET time_zone = 'America/Lima'");

        try {
            $data = CatalogoModel::cencosGalpones($conexion);
        } catch (\RuntimeException $e) {
            $conexion->close();
            Respuesta::json(500, false, $e->getMessage());
        }
        $conexion->close();
        Respuesta::json(200, true, $data['total'] > 0 ? 'Granjas y galpones cargados' : 'No se encontraron granjas', null, $data);
    }

    public function visibilidad(): void
    {
        require_once \APP_PROJECT_ROOT . '/modules/mortalidad/admin/mortalidad_admin_lib.php';

        $conexion = Db::joya();
        $codigo = mort_admin_normalizar_codigo(trim((string) Request::val('codigo', Request::val('codigo_usuario', ''))));
        $dni = trim((string) Request::val('dni', Request::val('usuario', '')));

        $vis = mort_admin_visibilidad_para_app($conexion, $codigo, $dni);
        $conexion->close();

        Respuesta::json(200, true, 'OK', null, [
            'visibilidad' => $vis,
            'codigo_usuario' => (string) ($vis['codigo_usuario'] ?? ''),
        ]);
    }

    public function cantidadPollos(): void
    {
        $conexion = Db::joya();
        mysqli_set_charset($conexion, 'latin1');
        mysqli_query($conexion, "SET time_zone = 'America/Lima'");
        @mysqli_query($conexion, "SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

        $tcencos = Request::input('tcencos');
        if ($tcencos === '') {
            $conexion->close();
            Respuesta::json(400, false, 'El parámetro tcencos es requerido');
        }
        $fechaRaw = Request::input('fecha');
        $galpon = Request::input('galpon');
        $fecha = $fechaRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRaw)
            ? $fechaRaw
            : date('Y-m-d');

        try {
            $data = CatalogoModel::cantidadPollos($conexion, $tcencos, $fecha, $galpon !== '' ? $galpon : null);
        } catch (\Throwable $e) {
            $conexion->close();
            Respuesta::json(500, false, 'Error SQL: ' . $e->getMessage());
        }
        $conexion->close();
        Respuesta::json(200, true, $data['total'] > 0 ? 'Datos cargados' : 'Sin datos para la consulta', null, $data);
    }

    public function cantidadPollosBatch(): void
    {
        $conexion = Db::joya();
        mysqli_set_charset($conexion, 'latin1');
        @mysqli_query($conexion, "SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        mysqli_query($conexion, "SET time_zone = 'America/Lima'");

        $tcencosRaw = Request::input('tcencos');
        if ($tcencosRaw === '') {
            $conexion->close();
            Respuesta::json(400, false, 'El parámetro tcencos (JSON array) es requerido');
        }
        $tcencosList = json_decode($tcencosRaw, true);
        if (!is_array($tcencosList)) {
            $tcencosList = [$tcencosRaw];
        }
        $tcencosList = array_values(array_unique(array_filter(array_map('trim', $tcencosList))));
        if (empty($tcencosList)) {
            $conexion->close();
            Respuesta::json(400, false, 'Lista de tcencos vacía');
        }

        $fechaCentralRaw = Request::input('fecha_central');
        $fechaCentral = $fechaCentralRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCentralRaw)
            ? $fechaCentralRaw
            : date('Y-m-d');

        $data = CatalogoModel::cantidadPollosBatch($conexion, $tcencosList, $fechaCentral);
        $conexion->close();
        Respuesta::json(200, true, 'Cantidades de pollos precargadas', null, $data);
    }

    public function liquidacionBloques(): void
    {
        $conexion = Db::joya();
        mysqli_set_charset($conexion, 'utf8mb4');
        mysqli_query($conexion, "SET time_zone = 'America/Lima'");

        $filtros = [
            'usuario' => Request::input('usuario'),
            'fecha_desde' => Request::input('fecha_desde'),
            'fecha_hasta' => Request::input('fecha_hasta'),
            'granja_codigo' => Request::input('granja_codigo'),
            'campania_codigo' => Request::input('campania_codigo'),
            'galpon_codigo' => Request::input('galpon_codigo'),
        ];

        try {
            $data = CatalogoModel::liquidacionBloques($conexion, $filtros);
        } catch (\RuntimeException $e) {
            $conexion->close();
            Respuesta::json(500, false, $e->getMessage());
        }
        $conexion->close();
        Respuesta::json(200, true, 'Liquidaciones cargadas', null, $data);
    }
}
