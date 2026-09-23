<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Db;
use App\Http\Request;
use App\Http\Respuesta;
use App\Models\AuditoriaModel;

/** Auditoría móvil de mortalidad. */
final class AuditoriaController
{
    public function pendientes(): void
    {
        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $filtros = [
            'fecha_desde' => Request::input('fecha_desde'),
            'fecha_hasta' => Request::input('fecha_hasta'),
            'granja_codigo' => Request::input('granja_codigo'),
            'campania_codigo' => Request::input('campania_codigo'),
            'galpon_codigo' => Request::input('galpon_codigo'),
        ];

        try {
            $data = AuditoriaModel::pendientes($conn, $filtros);
        } catch (\RuntimeException $e) {
            $conn->close();
            Respuesta::json(500, false, $e->getMessage());
        }
        $conn->close();
        Respuesta::json(200, true, 'Pendientes de auditoria', null, $data);
    }

    public function misAuditorias(): void
    {
        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $filtros = [
            'usuario' => Request::input('usuario'),
            'fecha_desde' => Request::input('fecha_desde'),
            'fecha_hasta' => Request::input('fecha_hasta'),
        ];

        try {
            $data = AuditoriaModel::misAuditorias($conn, $filtros);
        } catch (\RuntimeException $e) {
            $conn->close();
            Respuesta::json(500, false, $e->getMessage());
        }
        $conn->close();
        Respuesta::json(200, true, 'Auditorías cargadas', null, $data);
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

        $usuarioRegistro = Request::input('usuario');
        if ($usuarioRegistro === '') {
            $conn->close();
            Respuesta::json(400, false, 'Falta usuarioRegistro');
        }

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

        $registros = $data['registros'] ?? $data['aprobaciones'] ?? [];
        if (!is_array($registros) || $registros === []) {
            $conn->close();
            Respuesta::json(400, false, 'No hay registros en el payload');
        }

        $observacionesGenerales = trim((string) ($data['observaciones'] ?? ''));
        $tuuid = trim((string) ($data['tuuid'] ?? $data['id'] ?? ''));
        if ($tuuid === '' || !\evf_es_uuid($tuuid)) {
            $conn->close();
            Respuesta::json(400, false, 'Falta tuuid/id válido de auditoría', 'MISSING_UUID');
        }

        $carpetaFs = sanidad_uploads_fs_dir('mortalidad');

        mysqli_begin_transaction($conn);
        try {
            $guardado = \mort_aud_guardar_sesion(
                $conn,
                $usuarioRegistro,
                $registros,
                $observacionesGenerales,
                $carpetaFs,
                $tuuid
            );
            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            $conn->close();
            Respuesta::json(500, false, 'Error al guardar auditoría: ' . $e->getMessage(), 'AUDIT_ERROR');
        }
        $conn->close();

        $msg = !empty($guardado['duplicado']) ? 'Auditoría ya existía' : 'Auditoría registrada';
        Respuesta::json(200, true, $msg, null, [
            'auditoria' => $guardado,
        ]);
    }

    public function verificar(): void
    {
        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');

        $uuid = Request::input('uuid');
        if ($uuid === '') {
            $conn->close();
            Respuesta::json(400, false, 'Parámetro uuid es requerido');
        }

        $data = AuditoriaModel::verificarAuditoria($conn, $uuid);
        $conn->close();

        if (empty($data['existe'])) {
            Respuesta::json(200, true, 'Auditoría no encontrada', null, $data);
        }
        $completo = !empty($data['completo']);
        Respuesta::json(200, true, $completo ? 'Auditoría completa' : 'Auditoría incompleta', null, $data);
    }

    public function reparar(): void
    {
        @ini_set('upload_max_filesize', '50M');
        @ini_set('post_max_size', '50M');
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '256M');

        $conn = Db::joya();
        mysqli_set_charset($conn, 'utf8mb4');
        mysqli_query($conn, "SET time_zone = 'America/Lima'");

        $usuarioRegistro = Request::input('usuario');
        if ($usuarioRegistro === '') {
            $conn->close();
            Respuesta::json(400, false, 'Falta usuarioRegistro');
        }

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

        $tuuid = trim((string) ($data['tuuid'] ?? $data['id'] ?? ''));
        if ($tuuid === '' || !\evf_es_uuid($tuuid)) {
            $conn->close();
            Respuesta::json(400, false, 'Falta tuuid/id válido de auditoría', 'MISSING_UUID');
        }

        $registros = $data['registros'] ?? $data['aprobaciones'] ?? [];
        if (!is_array($registros)) {
            $registros = [];
        }

        $existente = \mort_aud_existe_por_id($conn, $tuuid);
        if ($existente === null) {
            $conn->close();
            Respuesta::json(400, false, 'Auditoría no existe. Use guardar_auditoria_movil.php primero.', 'NOT_FOUND');
        }

        $carpetaFs = sanidad_uploads_fs_dir('mortalidad');

        mysqli_begin_transaction($conn);

        $nuevasRutas = [];
        $moviActualizados = 0;
        $evidenciaActual = '';
        $registrosJsonBd = '';

        try {
            $stmtEv = $conn->prepare('SELECT evidencia, registros FROM san_mortalidad_auditoria WHERE id = ? LIMIT 1');
            if ($stmtEv) {
                $stmtEv->bind_param('s', $tuuid);
                $stmtEv->execute();
                $resEv = $stmtEv->get_result();
                $rowEv = $resEv ? $resEv->fetch_assoc() : null;
                $stmtEv->close();
                $evidenciaActual = trim((string) ($rowEv['evidencia'] ?? ''));
                $registrosJsonBd = (string) ($rowEv['registros'] ?? '');
            }

            $rutasActuales = [];
            if ($evidenciaActual !== '') {
                foreach (preg_split('/\s*,\s*/', $evidenciaActual) ?: [] as $r) {
                    $r = trim((string) $r);
                    if ($r !== '') {
                        $rutasActuales[] = $r;
                    }
                }
            }

            $uuidsAll = [];

            foreach ($registros as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $clave = trim((string) ($item['clave'] ?? ''));
                if ($clave === '' || !in_array($clave, \mort_aud_claves_validas(), true)) {
                    continue;
                }

                $fotosNuevas = \mort_aud_procesar_fotos($clave, $tuuid, $carpetaFs);
                foreach ($fotosNuevas as $ruta) {
                    if (!in_array($ruta, $rutasActuales, true) && !in_array($ruta, $nuevasRutas, true)) {
                        $nuevasRutas[] = $ruta;
                    }
                }

                $uuidsRaw = $item['registro_uuids'] ?? [];
                if (!is_array($uuidsRaw)) {
                    continue;
                }
                $uuids = \mort_aud_filtrar_uuids_por_clave($conn, $clave, $uuidsRaw);
                foreach ($uuids as $u) {
                    $uuidsAll[$u] = true;
                }
                $moviActualizados += \mort_aud_vincular_movi($conn, $tuuid, $uuids);
            }

            if ($nuevasRutas !== []) {
                $todas = array_merge($rutasActuales, $nuevasRutas);
                $evNueva = implode(',', $todas);
                $stmtUp = $conn->prepare('UPDATE san_mortalidad_auditoria SET evidencia = ? WHERE id = ?');
                if (!$stmtUp) {
                    throw new \RuntimeException('Error prepare update evidencia: ' . $conn->error);
                }
                $stmtUp->bind_param('ss', $evNueva, $tuuid);
                if (!$stmtUp->execute()) {
                    $err = $stmtUp->error;
                    $stmtUp->close();
                    throw new \RuntimeException('Error update evidencia: ' . $err);
                }
                $stmtUp->close();
                $evidenciaActual = $evNueva;
            }

            if ($uuidsAll === [] && $registrosJsonBd !== '') {
                $decoded = json_decode($registrosJsonBd, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $grupo) {
                        if (!is_array($grupo)) {
                            continue;
                        }
                        $clave = trim((string) ($grupo['clave'] ?? ''));
                        $uuidsRaw = $grupo['registro_uuids'] ?? [];
                        if ($clave === '' || !is_array($uuidsRaw)) {
                            continue;
                        }
                        $uuids = \mort_aud_filtrar_uuids_por_clave($conn, $clave, $uuidsRaw);
                        $moviActualizados += \mort_aud_vincular_movi($conn, $tuuid, $uuids);
                    }
                }
            }

            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            $conn->close();
            Respuesta::json(500, false, 'Error al reparar auditoría: ' . $e->getMessage(), 'REPAIR_ERROR');
        }

        $conn->close();

        Respuesta::json(200, true, 'Auditoría reparada', null, [
            'auditoria' => [
                'id' => $tuuid,
                'fotos_agregadas' => count($nuevasRutas),
                'movi_actualizados' => $moviActualizados,
                'evidencia' => $evidenciaActual,
                'duplicado' => false,
            ],
        ]);
    }
}
