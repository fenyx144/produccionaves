<?php

declare(strict_types=1);

/**
 * Sincronización dinámica ACL Mortalidad (Producción Aves + ítem Despacho en Sanidad).
 *
 * - amd_dashboard_modulos: upsert menú PA desde pmig_menu_definicion() (incluye Despacho).
 * - amd_dashboard_modulos Sanidad: seed canónico (sip_menu_catalog_seed_modulos).
 * - adm_rol_progr_modulo: otorga menú PA completo al rol cod_rol «SISTEMAS GESTIONAVES»
 *   (y alias por nom_rol Sistemas / Gestion Aves); en Sanidad asegura grp + item Despacho.
 *
 * Simulación: sync_acl_mortalidad_pa.php
 * Ejecutar:     sync_acl_mortalidad_pa.php?ejecutar=1
 * JSON:         sync_acl_mortalidad_pa.php?ejecutar=1&format=json
 */

$esCli = (PHP_SAPI === 'cli');
$ejecutar = $esCli
    ? in_array('--ejecutar', $argv ?? [], true)
    : !empty($_GET['ejecutar']);
$formatJson = !$esCli && isset($_GET['format']) && strtolower((string) $_GET['format']) === 'json';

require_once __DIR__ . '/../../../core/config.php';

if (!$esCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['active']) || empty($_SESSION['usuario'])) {
        if ($formatJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ../../../core/auth/login.php');
        exit;
    }
}

require_once PA_DB_PATH;
require_once __DIR__ . '/migrar_permisos_produccion_aves.php';

$errorFatal = '';
$result = ['ok' => false, 'acciones' => [], 'detalleRoles' => []];

$conn = conectar_joya_mysqli();
if (!$conn) {
    $errorFatal = 'No se pudo conectar a la base de datos.';
} else {
    try {
        $result = pmig_sincronizar_acl_mortalidad_pa($conn, $ejecutar);
        if (!$result['ok']) {
            $errorFatal = (string) ($result['error'] ?? 'Error en sincronización ACL.');
        }
    } catch (Throwable $e) {
        $errorFatal = $e->getMessage();
    }
    mysqli_close($conn);
}

if ($formatJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $errorFatal === '' && !empty($result['ok']),
        'ejecutar' => $ejecutar,
        'error' => $errorFatal !== '' ? $errorFatal : null,
        'acciones' => $result['acciones'] ?? [],
        'detalleRoles' => $result['detalleRoles'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($esCli) {
    echo $ejecutar ? "Modo ejecución\n" : "Modo simulación\n";
    foreach ($result['acciones'] ?? [] as $a) {
        echo '- ' . $a . "\n";
    }
    if ($errorFatal !== '') {
        echo "\nERROR: " . $errorFatal . "\n";
        exit(1);
    }
    exit(0);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizar ACL Mortalidad PA</title>
</head>
<body style="font-family:system-ui,sans-serif;padding:1.25rem;max-width:900px;margin:0 auto;line-height:1.5;">
    <h1>Sincronizar ACL — Mortalidad Producción Aves</h1>
    <p style="color:#64748b;">
        <?= $ejecutar ? 'Modo <strong>ejecución</strong>.' : 'Modo <strong>simulación</strong> (sin cambios en BD).' ?>
        Actualiza menú y permisos para roles
        <code><?= pmig_esc(implode('</code>, <code>', PMIG_ROLES_COD_OBJETIVO)) ?></code>.
    </p>
    <?php if ($errorFatal !== ''): ?>
        <p style="padding:.75rem 1rem;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">
            <strong>Error:</strong> <?= pmig_esc($errorFatal) ?>
        </p>
    <?php endif; ?>
    <h2>Acciones</h2>
    <ul>
        <?php foreach ($result['acciones'] ?? [] as $a): ?>
            <li><?= pmig_esc($a) ?></li>
        <?php endforeach; ?>
    </ul>
    <p style="margin-top:1.5rem;">
        <?php if ($ejecutar): ?>
            <a href="sync_acl_mortalidad_pa.php">Volver a simulación</a>
        <?php else: ?>
            <a href="sync_acl_mortalidad_pa.php?ejecutar=1" style="font-weight:600;">Ejecutar sincronización</a>
        <?php endif; ?>
        · <a href="migrar_permisos_produccion_aves.php">Migración completa Sanidad → PA</a>
    </p>
</body>
</html>
