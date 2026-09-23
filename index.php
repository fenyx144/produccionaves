<?php
@ini_set('max_execution_time', '120');
@set_time_limit(120);

require_once __DIR__ . '/core/auth.php';

// El shell (HTML del sidebar) no debe quedar en caché del navegador:
// los onclick inline (ix2ToggleSidebarPin, ix2LoadModule, etc.) dependen de
// core/js/sidebar.js, y un HTML viejo cacheado produce ReferenceError.
if (function_exists('sip_send_app_no_cache_headers')) {
    sip_send_app_no_cache_headers();
}

$paNavItems = [
    [
        'url'   => 'modules/mortalidad/listado/dashboard-listado.php',
        'title' => 'Listado mortalidad',
        'label' => 'Listado',
    ],
    [
        'url'   => 'modules/mortalidad/historial/dashboard-historial.php',
        'title' => 'Historial mortalidad',
        'label' => 'Historial',
    ],
    [
        'url'   => 'modules/mortalidad/auditoria/dashboard-auditoria.php',
        'title' => 'Auditoría mortalidad',
        'label' => 'Auditoría',
    ],
    [
        'url'   => 'modules/mortalidad/informe_contable/dashboard-informe-contable.php',
        'title' => 'Informe contable',
        'label' => 'Informe contable',
    ],
    [
        'url'   => 'modules/mortalidad/ventas/dashboard-ventas.php',
        'title' => 'Ventas mortalidad',
        'label' => 'Ventas',
    ],
    [
        'url'   => 'modules/mortalidad/graficas/dashboard-graficas.php',
        'title' => 'Gráficas mortalidad',
        'label' => 'Gráficas',
    ],
    [
        'url'   => 'modules/mortalidad/graficas-horizontales/dashboard-graficas-horizontales.php',
        'title' => 'Gráficas horizontales',
        'label' => 'Gráficas horizontales',
    ],
];

require __DIR__ . '/core/header.php';
require __DIR__ . '/core/footer.php';
