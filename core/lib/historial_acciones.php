<?php

// Crear conexión global
function obtenerConexion()
{
    $conexion = conectar_joya_mysqli();
    if (!$conexion) {
        die("Error de conexión auditoria: " . mysqli_connect_error());
    }
    return $conexion;
}

// ------------------------
//  UTILIDADES DE SISTEMA
// ------------------------

function obtenerIP()
{
    // Prioridad: headers de proxy primero (si el servidor está detrás de un proxy)
    $ip = null;
    
    // Intentar obtener IP real del cliente (si está detrás de proxy/load balancer)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
        $ip = $_SERVER['HTTP_FORWARDED'];
    }
    
    // Si no hay IP en headers, usar REMOTE_ADDR
    if (empty($ip)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    // Limpiar y validar IP
    $ip = trim($ip);
    
    // Si es localhost IPv6, convertir a IPv4 para consistencia
    if ($ip === '::1' || $ip === '::ffff:127.0.0.1') {
        $ip = '127.0.0.1';
    }
    
    // Validar que sea una IP válida
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    
    // Si no es válida, devolver la original o 0.0.0.0
    return $ip ?: '0.0.0.0';
}

function obtenerUserAgent()
{
    return $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido';
}

function obtenerNavegadorOS()
{
    $ua = obtenerUserAgent();
    $navegador = "Desconocido";
    $os = "Desconocido";

    // Detectar sistema operativo
    if (preg_match('/windows/i', $ua))
        $os = "Windows";
    elseif (preg_match('/linux/i', $ua))
        $os = "Linux";
    elseif (preg_match('/macintosh|mac os x/i', $ua))
        $os = "Mac OS";
    elseif (preg_match('/android/i', $ua))
        $os = "Android";
    elseif (preg_match('/iphone|ipad/i', $ua))
        $os = "iOS";

    // Detectar navegador
    if (preg_match('/chrome/i', $ua))
        $navegador = "Chrome";
    elseif (preg_match('/firefox/i', $ua))
        $navegador = "Firefox";
    elseif (preg_match('/safari/i', $ua))
        $navegador = "Safari";
    elseif (preg_match('/edge/i', $ua))
        $navegador = "Edge";
    elseif (preg_match('/opera|opr/i', $ua))
        $navegador = "Opera";
    elseif (preg_match('/msie|trident/i', $ua))
        $navegador = "Internet Explorer";

    return [$navegador, $os];
}

// -------------------------
//  FUNCIÓN GENÉRICA INSERT
// -------------------------

function insertarHistorialAcciones(
    $cod_usuario,
    $nom_usuario,
    $accion,
    $tabla_afectada = null,
    $registro_id = null,
    $datos_previos = null,
    $datos_nuevos = null,
    $descripcion = null,
    $ip = null,
    $ubicacion = null,
    $dispositivo = null,
    $os = null,
    $navegador = null,
    $user_agent = null
) {
    $conexion = obtenerConexion();

    $stmt = $conexion->prepare("
        INSERT INTO san_dim_historial_acciones (
            cod_usuario, nom_usuario, accion, tabla_afectada, registro_id,
            datos_previos, datos_nuevos, descripcion, fechaHora, ip,
            ubicacion_gps, dispositivo, sistema_operativo, navegador, user_agent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssssssssssssss",
        $cod_usuario,
        $nom_usuario,
        $accion,
        $tabla_afectada,
        $registro_id,
        $datos_previos,
        $datos_nuevos,
        $descripcion,
        $ip,
        $ubicacion,
        $dispositivo,
        $os,
        $navegador,
        $user_agent
    );

    $stmt->execute();
    $stmt->close();
    mysqli_close($conexion);
}

// -------------------------------------------------------
//  FUNCIÓN ESPECIAL PARA LOGIN Y LOGOUT (GUARDA TODO)
// -------------------------------------------------------

function registrarAccionLoginLogout($accion, $cod_usuario, $nom_usuario, $ubicacion)
{

    $ip = obtenerIP();
    $user_agent = obtenerUserAgent();
    list($navegador, $os) = obtenerNavegadorOS();

    // El dispositivo se estima
    $dispositivo = preg_match('/mobile/i', $user_agent) ? 'Móvil' : 'Desktop';

    insertarHistorialAcciones(
        $cod_usuario,
        $nom_usuario,
        $accion,
        null,        // tabla_afectada
        null,        // registro_id
        null,        // datos_previos
        null,        // datos_nuevos
        "Se realizo una accion de:  $accion",
        $ip,
        $ubicacion,        // ubicacion GPS (si quieres, lo agregas luego)
        $dispositivo,
        $os,
        $navegador,
        $user_agent
    );
}

// -------------------------------------------------------
//  FUNCIÓN PARA ACCIONES CRUD (NO GUARDA NAVEGADOR/IP)
// -------------------------------------------------------

function registrarAccionCRUD(
    $accion,
    $cod_usuario,
    $nom_usuario,
    $tabla,
    $registro_id = null,
    $datos_previos = null,
    $datos_nuevos = null,
    $descripcion = null
) {
    insertarHistorialAcciones(
        $cod_usuario,
        $nom_usuario,
        $accion,
        $tabla,
        $registro_id,
        $datos_previos,
        $datos_nuevos,
        $descripcion,
        null,
        null,
        null,
        null,
        null,
        null // sin información del equipo
    );
}

function registrarAccion(
    $cod_usuario,
    $nom_usuario,
    $accion,
    $tabla_afectada = null,
    $registro_id = null,
    $datos_previos = null,
    $datos_nuevos = null,
    $descripcion = null,    
    $ubicacion = null   
)
{

    $ip = obtenerIP();
    $user_agent = obtenerUserAgent();
    list($navegador, $os) = obtenerNavegadorOS();

    // El dispositivo se estima
    $dispositivo = preg_match('/mobile/i', $user_agent) ? 'Móvil' : 'Desktop';

    insertarHistorialAcciones(
        $cod_usuario,
        $nom_usuario,
        $accion,
        $tabla_afectada,        // tabla_afectada
        $registro_id,        // registro_id
        $datos_previos,        // datos_previos
        $datos_nuevos,        // datos_nuevos
        $descripcion,
        $ip,
        $ubicacion,        // ubicacion GPS (si quieres, lo agregas luego)
        $dispositivo,
        $os,
        $navegador,
        $user_agent
    );
}
?>