<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Db;
use App\Http\ErrorApi;
use App\Http\Request;
use App\Http\Respuesta;
use App\Http\Texto;
use App\Middleware\ApiAuth;
use App\Models\UsuarioModel;

/** Autenticación y registro de dispositivos móviles. */
final class AuthController
{
    public function login(): void
    {
        ApiAuth::requireToken();
        $conexion = Db::joya();

        try {
            $json = Request::bodyJson();
            if ($json === []) {
                Respuesta::json(400, false, 'Formato JSON inválido o vacío', 'INVALID_JSON');
            }
            $usuario = trim($json['usuario'] ?? '');
            $password = trim($json['password'] ?? '');
            $android = trim($json['android'] ?? '');
            $version = trim($json['version'] ?? '');
            $app = trim($json['app'] ?? '');

            if ($usuario === '' || $password === '' || $android === '' || $version === '') {
                Respuesta::json(400, false, 'Faltan datos requeridos', 'MISSING_DATA');
            }

            $newPassword = base64_decode($password);
            $fecha = date('Y-m-d');
            $hora = date('H:i:s');
            $fechaHoraActual = date('Y-m-d H:i:s');

            $rowLogin = UsuarioModel::login($conexion, $usuario, $newPassword);
            if (!$rowLogin) {
                $rowExist = UsuarioModel::buscarPorCodigo($conexion, $usuario);
                if (!$rowExist) {
                    Respuesta::json(404, false, 'Usuario no encontrado', 'USER_NOT_FOUND');
                }
                if ($rowExist['estado'] !== 'A') {
                    Respuesta::json(403, false, 'Tu usuario está inactivo. Contacta al administrador.', 'USER_INACTIVE', [
                        'usuario' => $usuario,
                        'nombre' => Texto::utf8($rowExist['nombre']),
                        'estado' => 'inactivo',
                    ]);
                }
                Respuesta::json(401, false, 'Contraseña incorrecta', 'WRONG_PASSWORD');
            }

            $enom = $rowLogin['enom'] ?? '';
            $datoUsuario = [[
                'codigo' => Texto::utf8($rowLogin['codigo']),
                'nombre' => Texto::utf8($rowLogin['nombre']),
                'rol_sanidad' => $rowLogin['rol_sanidad'],
                'libtri' => $rowLogin['libtri'],
            ]];

            $dispositivoData = UsuarioModel::dispositivo($conexion, $android, $app);
            if ($dispositivoData === null) {
                Respuesta::json(403, false, 'Dispositivo no registrado. Por favor registra tu dispositivo primero.', 'DEVICE_NOT_REGISTERED');
            }
            if ($dispositivoData['testado'] !== 'A') {
                Respuesta::json(403, false, 'Tu dispositivo está inactivo. Contacta al administrador.', 'DEVICE_INACTIVE', [
                    'android' => $android,
                    'estado' => 'inactivo',
                    'fabricante' => Texto::utf8($dispositivoData['tfabricante']),
                    'modelo' => Texto::utf8($dispositivoData['tmodelo']),
                ]);
            }

            $permisos = new \stdClass();
            $visibilidad = new \stdClass();
            if ($app === 'PROD_MORTALIDAD') {
                $rPerm = @$conexion->query(
                    "SELECT permiso, valor FROM san_mortalidad_acceso_app WHERE usuario = '"
                    . $conexion->real_escape_string($usuario) . "'"
                );
                if ($rPerm) {
                    $permisos = [];
                    while ($row = $rPerm->fetch_assoc()) {
                        $permisos[$row['permiso']] = (int) $row['valor'];
                    }
                    if (empty($permisos)) {
                        $permisos = new \stdClass();
                    }
                }

                require_once \APP_PROJECT_ROOT . '/modules/mortalidad/admin/mortalidad_admin_lib.php';
                $codigoSanidad = trim((string) ($rowLogin['codigo'] ?? $usuario));
                $visibilidad = mort_admin_visibilidad_para_app($conexion, $codigoSanidad, $usuario);
            }

            $offlineHash = hash('sha256', $newPassword . 'M0rt4l1d4d_S4lt_2026');

            $dispositivo = [
                array_map([Texto::class, 'utf8'], [
                    'tidandroid' => $dispositivoData['tidandroid'],
                ])
            ];

            Respuesta::continuar([
                'usuario' => $datoUsuario,
                'dispositivo' => $dispositivo,
                'offline_hash' => $offlineHash,
                'enom' => $enom,
                'permisos' => $permisos,
                'visibilidad' => $visibilidad,
            ]);

            UsuarioModel::registrarUso($conexion, $version, $usuario, $fecha, $hora, $fechaHoraActual, $android, $app);
        } catch (ErrorApi $e) {
            Respuesta::json($e->status, false, $e->getMessage(), $e->errorCode, $e->data);
        } catch (\Throwable $e) {
            Respuesta::json(500, false, 'Error inesperado', 'EXCEPTION', [
                'error' => $e->getMessage(),
            ]);
        }
        $conexion->close();
    }

    public function registrarDispositivo(): void
    {
        ApiAuth::requireToken();
        $conexion = Db::joya();

        try {
            $json = Request::bodyJson();
            if ($json === []) {
                Respuesta::json(400, false, 'Formato JSON inválido o vacío', 'INVALID_JSON');
            }
            $android = trim($json['android'] ?? '');
            $fabricante = trim($json['fabricante'] ?? '');
            $familia = trim($json['familia'] ?? '');
            $modelo = trim($json['modelo'] ?? '');
            $descripcion = trim($json['descripcion'] ?? '');
            $app = trim($json['app'] ?? '');

            if ($android === '' || $fabricante === '' || $familia === '' || $modelo === '' || $descripcion === '') {
                Respuesta::json(400, false, 'Faltan datos requeridos', 'MISSING_DATA');
            }

            $fecha = date('Y-m-d');
            $hora = date('H:i:s');

            $existente = UsuarioModel::dispositivo($conexion, $android, $app);
            if ($existente !== null) {
                if ($existente['testado'] === 'A') {
                    Respuesta::json(200, true, 'Este dispositivo ya está registrado y activo', 'DEVICE_ALREADY_ACTIVE', [
                        'android' => $android,
                        'estado' => 'activo',
                        'fabricante' => Texto::utf8($existente['tfabricante']),
                        'modelo' => Texto::utf8($existente['tmodelo']),
                    ]);
                }
                Respuesta::json(200, false, 'Este dispositivo está registrado pero inactivo. Contacta al administrador.', 'DEVICE_INACTIVE', [
                    'android' => $android,
                    'estado' => 'inactivo',
                    'fabricante' => Texto::utf8($existente['tfabricante']),
                    'modelo' => Texto::utf8($existente['tmodelo']),
                ]);
            }

            $ok = UsuarioModel::dispositivoInsertar($conexion, $fecha, $hora, $android, $fabricante, $familia, $modelo, $descripcion, $app);
            if ($ok) {
                Respuesta::json(201, true, 'Dispositivo registrado correctamente', null, [
                    'android' => $android,
                    'fabricante' => $fabricante,
                    'familia' => $familia,
                    'modelo' => $modelo,
                    'estado' => 'activo',
                ]);
            }
            Respuesta::json(500, false, 'Error al registrar dispositivo: ' . $conexion->error, 'DB_INSERT_ERROR');
        } catch (ErrorApi $e) {
            Respuesta::json($e->status, false, $e->getMessage(), $e->errorCode, $e->data);
        } catch (\Throwable $e) {
            Respuesta::json(500, false, 'Error inesperado en el servidor', 'EXCEPTION', [
                'error' => $e->getMessage(),
            ]);
        }
        $conexion->close();
    }
}
