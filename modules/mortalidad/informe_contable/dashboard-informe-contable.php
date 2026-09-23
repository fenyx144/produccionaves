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

include_once __DIR__ . '/../../../../conexion_grs/conexion.php';
require_once __DIR__ . '/informe_contable_lib.php';
if (file_exists(__DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php')) {
    require_once __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';
}

$conexion = conectar_joya_mysqli();
if (!$conexion) {
    die('Error de conexión: ' . mysqli_connect_error());
}

$granjasZonas = [];
try {
    if (function_exists('hc_granjas_listar_para_selector')) {
        $granjasZonas = hc_granjas_listar_para_selector($conexion);
    }
} catch (\Throwable $e) {
    $granjasZonas = [];
}
$causasMort = inf_ctb_opciones_causas($conexion);
mysqli_close($conexion);

$granjasPorZona = [];
foreach ($granjasZonas as $g) {
    $zona = !empty($g['zona']) ? $g['zona'] : 'Sin zona';
    $granjasPorZona[$zona][] = $g;
}
ksort($granjasPorZona);

include_once __DIR__ . '/../../../core/lib/datatables_lang_es.php';

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$listarApiUrl = $baseModUrl . '/listar_informe_contable.php';
$hoy = date('Y-m-d');
$mesActual = date('Y-m');
$anio = date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/../../../core/lib/ix2_head_theme_snippet.php'; ?>
    <title>Mortalidad — Informe Contable</title>
    <link rel="stylesheet" href="../../../assets/css/output.css">
    <link rel="stylesheet" href="../../../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="../../../assets/css/modal_granjas_campanias.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../assets/css/modal_granjas_campanias.css'); ?>">
    <?php require_once __DIR__ . '/../../../core/lib/sip_listado_stylesheet_links.php'; sip_echo_listado_stylesheet_links(); ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="../js/auth.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>window.DATATABLES_LANG_ES = <?php echo $datatablesLangEs; ?>;</script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .btn-primary {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: none; padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            color: white; border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary:hover { filter: brightness(1.05); }
        .btn-outline {
            background: white; border: 1px solid #d1d5db; color: #374151;
            padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            border-radius: 0.75rem; cursor: pointer;
        }
        .granja-optgroup-label {
            font-weight: 600; font-size: 0.8rem; color: #d97706;
            padding: 4px 8px; background: #fffbeb; border-radius: 4px; margin: 4px 0;
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
        #tablaMortalidad td, #tablaMortalidad th,
        #tablaAuditoria td, #tablaAuditoria th,
        #tablaInforme td, #tablaInforme th {
            white-space: nowrap !important;
        }
        .tabla-listado-wrapper .table-wrapper {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
        }
        .tabla-listado-wrapper .table-wrapper table.data-table.config-table {
            width: max-content !important;
            min-width: 100% !important;
            max-width: none !important;
        }
        select.granja-select option:disabled {
            font-weight: 700; color: #d97706; background: #fffbeb;
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <button type="button" id="btnToggleFiltros"
            class="w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 transition">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔎</span>
                <h3 class="text-base font-semibold text-gray-800">Filtros de búsqueda</h3>
            </div>
            <svg id="iconoFiltros" class="w-5 h-5 text-gray-600 transition-transform duration-300"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="contenidoFiltros" class="px-6 pb-6 pt-4 hidden">
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
                        <option value="POR_MES" selected>Por mes</option>
                        <option value="ENTRE_MESES">Entre meses</option>
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
                <div id="periodoPorMes" class="flex-shrink-0 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mes</label>
                    <input id="mesUnico" type="month" value="<?php echo $mesActual; ?>"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                </div>
                <div id="periodoEntreMeses" class="hidden flex-shrink-0 flex items-end gap-2">
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

            <!-- Fila 1: Granja + Galpon -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-warehouse mr-1 text-sky-600"></i> Granja y campaña
                    </label>
                    <div class="selector-display">
                        <input id="mrt-inf-granja-resumen" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500 hc-sel-granja" readonly placeholder="Clic para seleccionar" value="">
                    </div>
                    <input id="mrt-inf-h-granja" type="hidden" value="">
                    <input id="mrt-inf-h-campania" type="hidden" value="">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-home mr-1 text-sky-600"></i> Galpón
                    </label>
                    <select id="filtroGalpon" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500" disabled>
                        <option value="">Todos</option>
                    </select>
                </div>
            </div>
            <!-- Fila 2: Sexo + Categoria + Flujo + Causa -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-venus-mars mr-1 text-sky-600"></i> Sexo
                    </label>
                    <select id="filtroSexo" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="">Todos</option>
                        <option value="Macho">Macho</option>
                        <option value="Hembra">Hembra</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-tags mr-1 text-sky-600"></i> Categoria
                    </label>
                    <select id="filtroTcategoria" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="">Todos</option>
                        <option value="PlantaIncubacion">Planta Incubacion</option>
                        <option value="Transporte">Transporte</option>
                        <option value="Produccion">Produccion</option>
                        <option value="Despacho">Despacho</option>
                        <option value="Otros">Otros</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-exchange-alt mr-1 text-sky-600"></i> Flujo
                    </label>
                    <select id="filtroFlujo" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="">Todos</option>
                        <option value="Crianza">Crianza</option>
                        <option value="Transporte">Transporte</option>
                        <option value="Laboratorio">Laboratorio</option>
                        <option value="Necropsia">Necropsia</option>
                        <option value="Cuarentena">Cuarentena</option>
                        <option value="Despacho">Despacho</option>
                        <option value="Planta Incubacion">Planta Incubacion</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-skull mr-1 text-sky-600"></i> Causa de mortalidad
                    </label>
                    <select id="filtroCausa" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="">Todas</option>
                        <?php foreach ($causasMort as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['nom_mort']); ?>">
                                <?php echo htmlspecialchars($c['nom_mort']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="dashboard-actions mt-6 flex flex-wrap justify-start gap-4">
                <button type="button" id="btnFiltrar" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <button type="button" id="btnLimpiar" class="px-6 py-2.5 rounded-lg border border-gray-300 text-gray-700 bg-gray-100 hover:bg-gray-200 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button type="button" id="btnExportarPdf" class="px-6 py-2.5 text-white font-medium rounded-lg transition inline-flex items-center gap-2"
                    style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); box-shadow: 0 4px 6px rgba(220, 38, 38, 0.3);">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </button>
                <button type="button" id="btnExportarXls" class="px-6 py-2.5 text-white font-medium rounded-lg transition inline-flex items-center gap-2"
                    style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 6px rgba(16, 185, 129, 0.3);">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </button>
            </div>
        </div>
    </div>

    <div class="tabla-listado-wrapper bg-white rounded-xl shadow-md p-5">
        <div class="table-wrapper overflow-x-auto">
            <table id="tablaInforme" class="data-table display w-full text-sm border-collapse config-table" style="width:100%">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Usuario</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Fecha cont.</th>
                        <th>Cencos</th>
                        <th>Nom. cencos</th>
                        <th>Galpón</th>
                        <th>Edad</th>
                        <th>Código</th>
                        <th>Nom. prod.</th>
                        <th>Cod. tra.</th>
                        <th>Transacción</th>
                        <th>Sexo</th>
                        <th>Categoria</th>
                        <th>Flujo</th>
                        <th>Num. fac</th>
                        <th>Idmovi</th>
                        <th>Cantidad</th>
                        <th>Cod. mort.</th>
                        <th>Causa mort.</th>
                        <th>Cant. mort.</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php
$gmc_id_prefix = 'mrt-inf';
$gmc_shell_panel_id = 'mrt-inf-modal-granjas';
$gmc_show_chk_todas = false;
require __DIR__ . '/../../../core/lib/modals/modal_granjas_campanias.php';
?>

<script>
window.INF_CTB_CFG = {
    listarUrl: <?= json_encode($listarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    pdfUrl: <?= json_encode($baseModUrl . '/generar_reporte_informe_contable_pdf.php', JSON_UNESCAPED_UNICODE) ?>,
    xlsUrl: <?= json_encode($baseModUrl . '/exportar_excel_informe_contable.php', JSON_UNESCAPED_UNICODE) ?>,
    granjasMeta: <?php
$gm = [];
foreach (array_values($granjasZonas) as $gz) {
    $gm[] = [
        'granja' => $gz['granja'] ?? '',
        'nombre' => $gz['nombre_granja'] ?? $gz['nombre'] ?? '',
        'zona' => $gz['zona'] ?? '',
        'subzona' => $gz['subzona'] ?? ''
    ];
}
echo json_encode($gm, JSON_UNESCAPED_UNICODE);
?>
};
</script>
<script src="../js/gmc-granjas-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../js/gmc-granjas-campanias.js'); ?>"></script>
<script src="js/mortalidad-informe-contable.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-informe-contable.js'); ?>"></script>

<!-- Modal de fotos para Informe Contable -->
<div id="modalFotosInfCtb" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h5 class="text-lg font-semibold text-gray-800">Fotos del registro</h5>
            <button type="button" id="btnCerrarFotosInfCtb" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 overflow-auto flex-1" id="bodyFotosInfCtb"></div>
    </div>
</div>

</body>
</html>
