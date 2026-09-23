<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Db;
use App\Http\Request;
use App\Http\Respuesta;
use App\Models\MortalidadModel;
use App\Services\MortalidadGuardadoService;

/** Registros de mortalidad móvil: listado, guardado, reparación y evidencia. */
final class MortalidadController
{
    /** Prefix de URLs de evidencia relativo a este front controller. */
    private static function evidenciaPrefix(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $scheme . '://' . $host . $dir . '/mortalidad/ver_evidencia_movil.php?ruta=';
    }

    public function listar(): void
    {
        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $filtros = [
            'fecha' => Request::input('fecha'),
            'granja' => Request::input('granja'),
            'campania' => Request::input('campania'),
            'galpon' => Request::input('galpon'),
            'tipo_mortalidad' => Request::input('tipo_mortalidad'),
        ];

        try {
            $data = MortalidadModel::listarRegistros($conn, $filtros, self::evidenciaPrefix());
        } catch (\RuntimeException $e) {
            $conn->close();
            Respuesta::json(500, false, $e->getMessage());
        }
        $conn->close();
        Respuesta::json(200, true, 'Registros cargados', null, $data);
    }

    public function guardar(): void
    {
        @ini_set('upload_max_filesize', '50M');
        @ini_set('post_max_size', '50M');
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '256M');

        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $dataJson = Request::post('data', '');
        if ($dataJson === '') {
            $conn->close();
            Respuesta::json(400, false, 'Falta el campo data');
        }

        $data = json_decode($dataJson, true);
        if (!is_array($data)) {
            $conn->close();
            Respuesta::json(400, false, 'JSON inválido en data');
        }

        $records = $data['mortalidad'] ?? [];
        if (!is_array($records) || count($records) === 0) {
            $conn->close();
            Respuesta::json(400, false, 'No hay registros de mortalidad en el payload');
        }

        $carpetaFs = sanidad_uploads_fs_dir('mortalidad');

        try {
            $result = MortalidadGuardadoService::guardarLote($conn, $records, $carpetaFs);
        } catch (\Throwable $e) {
            $conn->close();
            Respuesta::json(500, false, 'Error interno: ' . $e->getMessage());
        }
        $conn->close();

        $guardados = $result['guardados'];
        $duplicados = $result['duplicados'];
        $errores = $result['errores'];
        $errorCodeCierre = $result['errorCodeCierre'];

        if (count($guardados) === 0 && count($errores) > 0) {
            Respuesta::json(500, false, 'No se pudo guardar ningún registro', $errorCodeCierre ?? 'SAVE_ERROR', [
                'errores' => $errores,
                'duplicados' => $duplicados,
            ]);
        }

        Respuesta::json(200, true, 'Registros procesados', $errorCodeCierre, [
            'guardados' => count($guardados),
            'duplicados' => $duplicados,
            'registros' => $guardados,
            'errores' => $errores,
        ]);
    }

    public function repararRegistro(): void
    {
        @ini_set('upload_max_filesize', '50M');
        @ini_set('post_max_size', '50M');
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '256M');

        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $dataJson = Request::post('data', '');
        if ($dataJson === '') {
            $conn->close();
            Respuesta::json(400, false, 'Falta el campo data');
        }

        $data = json_decode($dataJson, true);
        if (!is_array($data)) {
            $conn->close();
            Respuesta::json(400, false, 'JSON inválido en data');
        }

        $records = $data['mortalidad'] ?? [];
        if (!is_array($records) || count($records) === 0) {
            $conn->close();
            Respuesta::json(400, false, 'No hay registros de mortalidad en el payload');
        }

        $carpetaFs = sanidad_uploads_fs_dir('mortalidad');

        try {
            $result = MortalidadGuardadoService::repararLote($conn, $records, $carpetaFs);
        } catch (\Throwable $e) {
            $conn->close();
            Respuesta::json(500, false, 'Error interno: ' . $e->getMessage());
        }
        $conn->close();

        $reparados = $result['reparados'];
        $omitidos = $result['omitidos'];
        $errores = $result['errores'];

        if (count($reparados) === 0 && count($errores) > 0) {
            Respuesta::json(500, false, 'No se pudo reparar ningún registro', 'REPAIR_ERROR', [
                'errores' => $errores,
                'omitidos' => $omitidos,
            ]);
        }

        $totalReparados = count($reparados);
        $totalAcciones = 0;
        foreach ($reparados as $r) {
            $totalAcciones += (int) ($r['lineas_agregadas'] ?? 0) + (int) ($r['imagenes_agregadas'] ?? 0);
        }

        Respuesta::json(200, true, "Reparación completada: {$totalReparados} registro(s), {$totalAcciones} acción(es)", null, [
            'reparados' => $reparados,
            'total_reparados' => $totalReparados,
            'total_acciones' => $totalAcciones,
            'omitidos' => $omitidos,
            'errores' => $errores,
        ]);
    }

    public function verificarRegistro(): void
    {
        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $uuid = Request::input('uuid');
        if ($uuid === '') {
            $conn->close();
            Respuesta::json(400, false, 'Parámetro uuid es requerido');
        }

        $esperados = Request::input('detalles_enviados');
        $data = MortalidadModel::verificarRegistro($conn, $uuid, $esperados);
        $conn->close();

        $cabExiste = !empty($data['cabecera']['existe']);
        if (!$cabExiste) {
            Respuesta::json(200, true, 'Registro no encontrado en BD', null, $data);
        }
        $completo = !empty($data['completo']);
        Respuesta::json(200, true, $completo ? 'Registro completo' : 'Registro incompleto', null, $data);
    }

    /** Servir archivo de evidencia (mismo contrato que ver_evidencia_movil.php). */
    public function verEvidencia(): void
    {
        $ruta = isset($_GET['ruta']) ? trim((string) $_GET['ruta']) : '';
        if ($ruta === '') {
            http_response_code(400);
            exit;
        }

        $ruta = rawurldecode($ruta);
        $rutaNorm = str_replace('\\', '/', $ruta);
        if (preg_match('#^uploads/#', $rutaNorm) !== 1) {
            http_response_code(400);
            exit;
        }
        if (preg_match('#^uploads/(evidencias|necropsias|resultados|mortalidad)/#', $rutaNorm) !== 1) {
            http_response_code(400);
            exit;
        }
        foreach (preg_split('#/#', $rutaNorm, -1, PREG_SPLIT_NO_EMPTY) as $seg) {
            if ($seg === '..') {
                http_response_code(400);
                exit;
            }
        }

        $pathFisico = sanidad_uploads_fs_from_rel($rutaNorm);
        if ($pathFisico === '' || !is_file($pathFisico)) {
            http_response_code(404);
            exit;
        }

        $realPath = realpath($pathFisico);
        $realBase = realpath(sanidad_uploads_fs_root());
        if ($realPath === false || $realBase === false || strpos($realPath, $realBase) !== 0) {
            http_response_code(403);
            exit;
        }

        $pathFisico = $realPath;
        $nombreArchivo = basename($pathFisico);
        $mime = @mime_content_type($pathFisico);
        if ($mime === false || $mime === '') {
            $mime = 'application/octet-stream';
        }
        $nombreSeguro = preg_replace('/[^\x20-\x7E]/', '_', $nombreArchivo);
        if ($nombreSeguro === '') {
            $nombreSeguro = 'archivo';
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $nombreSeguro) . '"');
        header('Content-Length: ' . (string) filesize($pathFisico));
        header('Cache-Control: private, max-age=3600');
        readfile($pathFisico);
        exit;
    }
}
