<?php

declare(strict_types=1);

use App\Controllers\Api\AuthController;
use App\Controllers\Api\AuditoriaController;
use App\Controllers\Api\CatalogoController;
use App\Controllers\Api\MortalidadController;

/** Rutas de la API móvil (app gc_mortalidad). */
return [
    'POST' => [
        '/login.php' => [AuthController::class, 'login'],
        '/registrardispositivo.php' => [AuthController::class, 'registrarDispositivo'],
        '/mortalidad/get_visibilidad_mortalidad.php' => [CatalogoController::class, 'visibilidad'],
        '/mortalidad/guardar_mortalidad_movil.php' => [MortalidadController::class, 'guardar'],
        '/mortalidad/reparar_registro_movil.php' => [MortalidadController::class, 'repararRegistro'],
        '/mortalidad/guardar_auditoria_movil.php' => [AuditoriaController::class, 'guardar'],
        '/mortalidad/reparar_auditoria_movil.php' => [AuditoriaController::class, 'reparar'],
        '/mortalidad/listar_mortalidad_movil.php' => [MortalidadController::class, 'listar'],
        '/mortalidad/verificar_registro_movil.php' => [MortalidadController::class, 'verificarRegistro'],
        '/mortalidad/listar_auditoria_pendiente_movil.php' => [AuditoriaController::class, 'pendientes'],
        '/mortalidad/verificar_auditoria_movil.php' => [AuditoriaController::class, 'verificar'],
    ],
    'GET' => [
        '/download.php' => [CatalogoController::class, 'downloadCatalogos'],
        '/mortalidad/get_motivos_mortalidad.php' => [CatalogoController::class, 'motivos'],
        '/mortalidad/get_cencos_galpones.php' => [CatalogoController::class, 'cencosGalpones'],
        '/mortalidad/get_visibilidad_mortalidad.php' => [CatalogoController::class, 'visibilidad'],
        '/mortalidad/get_cantidad_pollos.php' => [CatalogoController::class, 'cantidadPollos'],
        '/mortalidad/get_cantidad_pollos_batch.php' => [CatalogoController::class, 'cantidadPollosBatch'],
        '/mortalidad/listar_liquidacion_bloques.php' => [CatalogoController::class, 'liquidacionBloques'],
        '/mortalidad/listar_mortalidad_movil.php' => [MortalidadController::class, 'listar'],
        '/mortalidad/verificar_registro_movil.php' => [MortalidadController::class, 'verificarRegistro'],
        '/mortalidad/ver_evidencia_movil.php' => [MortalidadController::class, 'verEvidencia'],
        '/mortalidad/listar_auditoria_pendiente_movil.php' => [AuditoriaController::class, 'pendientes'],
        '/mortalidad/listar_mis_auditorias_movil.php' => [AuditoriaController::class, 'misAuditorias'],
        '/mortalidad/verificar_auditoria_movil.php' => [AuditoriaController::class, 'verificar'],
    ],
];
