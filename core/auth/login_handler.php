<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once PA_DB_PATH;
require_once PA_CORE_LIB . '/historial_acciones.php';
require_once PA_CORE_LIB . '/usuario_auth_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$usuarioInput = trim($_POST['usuario'] ?? '');
$clave = trim($_POST['clave'] ?? '');
$gps = $_POST['gps'] ?? null;

if ($usuarioInput === '' || $clave === '') {
    echo json_encode(['success' => false, 'message' => 'Ingrese su usuario y contraseña']);
    exit;
}

$conexion = conectar_joya_mysqli();
if (!$conexion) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

if (sip_usuario_codigo_db($conexion, $usuarioInput) === null) {
    mysqli_close($conexion);
    echo json_encode(['success' => false, 'message' => 'El usuario no está registrado']);
    exit;
}

$loginData = sip_password_authenticate($conexion, $usuarioInput, $clave);
mysqli_close($conexion);

if ($loginData) {
    $_SESSION['active'] = true;
    $_SESSION['usuario'] = $loginData['codigo'];
    $_SESSION['nombre'] = $loginData['nombre'];
    registrarAccionLoginLogout('LOGIN', $loginData['codigo'], $loginData['nombre'], $gps);
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Contraseña incorrecta']);
}
