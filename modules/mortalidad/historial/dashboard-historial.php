<?php
session_start();
if (empty($_SESSION['active'])) {
    echo '<script>
        if (window.top !== window.self) {
            window.top.location.href = "../../../core/auth/login.php";
        } else {
            window.location.href = "../../../core/auth/login.php";
        }
    </script>';
    exit();
}

require_once __DIR__ . '/mortalidad_historial_lib.php';
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';
include_once __DIR__ . '/../../../core/lib/datatables_lang_es.php';

// Solo usuarios con rol Sistemas (o KAREN) pueden eliminar registros del historial.
$conexionHist = conectar_joya_mysqli();
$puedeEliminarHistorial = false;
if ($conexionHist) {
    $puedeEliminarHistorial = mort_listado_usuario_puede_editar_eliminar($conexionHist);
    mysqli_close($conexionHist);
}

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$listarApiUrl = $baseModUrl . '/listar_historial.php';
$detalleApiUrl = $baseModUrl . '/get_detalle_historial.php';
$eliminarApiUrl = $baseModUrl . '/eliminar_historial.php';
$hoy = date('Y-m-d');
$mesActual = date('Y-m');
$anio = date('Y');
$accionesMeta = mort_hist_acciones_meta();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/../../../core/lib/ix2_head_theme_snippet.php'; ?>
    <title>Mortalidad — Historial</title>
    <link rel="stylesheet" href="../../../assets/css/output.css">
    <link rel="stylesheet" href="../../../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <?php require_once __DIR__ . '/../../../core/lib/sip_listado_stylesheet_links.php'; sip_echo_listado_stylesheet_links(); ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="../js/auth.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>window.DATATABLES_LANG_ES = <?php echo $datatablesLangEs; ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .btn-primary {
            background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
            border: none; padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            color: white; border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary:hover { filter: brightness(1.05); }
        .btn-outline {
            background: white; border: 1px solid #d1d5db; color: #374151;
            padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            border-radius: 0.75rem; cursor: pointer;
        }
        .dt-top-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.5rem 0; flex-wrap: wrap; gap: 0.75rem;
        }
        .dt-bottom-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.5rem 0; flex-wrap: wrap; gap: 0.75rem;
        }
        table.data-table td, table.data-table th { white-space: nowrap; }
        #tablaHistorial td, #tablaHistorial th { white-space: nowrap !important; }
        .tabla-listado-wrapper .table-wrapper {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }
        .tabla-listado-wrapper .table-wrapper table.data-table.config-table {
            width: max-content !important;
            min-width: 100% !important;
            max-width: none !important;
        }
        .hist-badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.2rem 0.55rem; border-radius: 9999px; font-size: 0.75rem;
            font-weight: 600; border: 1px solid transparent;
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <button type="button" id="btnToggleFiltrosHist"
            class="w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 transition">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔎</span>
                <h3 class="text-base font-semibold text-gray-800">Filtros de búsqueda</h3>
            </div>
            <svg id="iconoFiltrosHist" class="w-5 h-5 text-gray-600 transition-transform duration-300"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="contenidoFiltrosHist" class="px-6 pb-6 pt-4 hidden">
            <div class="filter-row-periodo flex flex-wrap items-end gap-4 mb-6">
                <div class="flex-shrink-0" style="min-width: 200px;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-calendar-alt mr-1 text-sky-600"></i> Fecha
                    </label>
                    <select id="periodoTipo"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm cursor-pointer">
                        <option value="TODOS">Todos</option>
                        <option value="POR_FECHA">Por fecha</option>
                        <option value="ENTRE_FECHAS">Entre fechas</option>
                        <option value="POR_MES">Por mes</option>
                        <option value="ENTRE_MESES" selected>Entre meses</option>
                        <option value="ULTIMA_SEMANA">Última semana</option>
                    </select>
                </div>
                <div id="periodoPorFecha" class="flex-shrink-0 min-w-[200px] hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-calendar-day mr-1 text-sky-600"></i> Fecha
                    </label>
                    <input id="fechaUnica" type="date" value="<?php echo $hoy; ?>"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                </div>
                <div id="periodoEntreFechas" class="flex-shrink-0 hidden flex items-end gap-2">
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                        <input id="fechaInicio" type="date" value="<?php echo $anio; ?>-01-01"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                        <input id="fechaFin" type="date" value="<?php echo $anio; ?>-12-31"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                </div>
                <div id="periodoPorMes" class="hidden flex-shrink-0 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mes</label>
                    <input id="mesUnico" type="month" value="<?php echo $mesActual; ?>"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                </div>
                <div id="periodoEntreMeses" class="flex-shrink-0 flex items-end gap-2">
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes inicio</label>
                        <input id="mesInicio" type="month" value="<?php echo $anio; ?>-01"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes fin</label>
                        <input id="mesFin" type="month" value="<?php echo $anio; ?>-12"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-exchange-alt mr-1 text-sky-600"></i> Operación
                    </label>
                    <select id="filtroAccion"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="">Todas</option>
                        <?php foreach ($accionesMeta as $cod => $meta): ?>
                            <option value="<?php echo htmlspecialchars($cod); ?>">
                                <?php echo htmlspecialchars($meta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dashboard-actions mt-6 flex flex-wrap justify-start gap-4">
                <button type="button" id="btnFiltrarHist" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <button type="button" id="btnLimpiarHist" class="px-6 py-2.5 rounded-lg border border-gray-300 text-gray-700 bg-gray-100 hover:bg-gray-200 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
            </div>
        </div>
    </div>

    <div class="tabla-listado-wrapper bg-white rounded-xl shadow-md p-5">
        <div class="table-wrapper overflow-x-auto">
            <table id="tablaHistorial" class="data-table display w-full text-sm border-collapse config-table" style="width:100%">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Usuario</th>
                        <th>Operación</th>
                        <th>Granja</th>
                        <th>Campaña</th>
                        <th>Galpón</th>
                        <th>Aves</th>
                        <th>Detalle</th>
                        <?php if ($puedeEliminarHistorial): ?><th>Opciones</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div id="modalDetalleHist" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h5 class="text-lg font-semibold text-gray-800" id="modalDetalleHistTitulo">Detalle de historial</h5>
            <button type="button" id="btnCerrarDetalleHist" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 overflow-auto flex-1 text-sm" id="modalDetalleHistBody"></div>
    </div>
</div>

<script>
window.MORT_HIST_CFG = {
    listarUrl: <?= json_encode($listarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    detalleUrl: <?= json_encode($detalleApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    eliminarUrl: <?= json_encode($eliminarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    codigoSesion: <?= json_encode(trim((string) ($_SESSION['usuario'] ?? '')), JSON_UNESCAPED_UNICODE) ?>,
    puedeEliminarHistorial: <?= $puedeEliminarHistorial ? 'true' : 'false' ?>
};
</script>
<script src="js/mortalidad-historial-listado.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-historial-listado.js'); ?>"></script>
</body>
</html>
