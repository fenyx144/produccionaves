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
require_once __DIR__ . '/mortalidad_listado_lib.php';
require_once __DIR__ . '/../auditoria/mortalidad_auditoria_listado_lib.php';
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
$tiposMortalidad = mort_admin_tipos_mortalidad();
$arbolGrupos = mort_aud_listado_arbol_grupos();
$puedeEditarEliminar = mort_listado_usuario_puede_editar_eliminar($conexion);
$tieneRolSistemas = mort_listado_usuario_tiene_rol_sistemas($conexion);
mysqli_close($conexion);

include_once __DIR__ . '/../../../core/lib/datatables_lang_es.php';

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$listarApiUrl = $baseModUrl . '/listar_registros.php';
$detalleApiUrl = $baseModUrl . '/get_detalle_registro.php';
$eliminarDetalleApiUrl = $baseModUrl . '/eliminar_detalle_registro.php';
$eliminarApiUrl = $baseModUrl . '/eliminar_registro.php';
$actualizarApiUrl = $baseModUrl . '/actualizar_registro.php';
$motivosApiUrl = $baseModUrl . '/get_motivos_listado.php';
$filtrosApiUrl = $baseModUrl . '/get_opciones_filtros.php';
$pdfApiUrl = $baseModUrl . '/generar_reporte_mortalidad_pdf.php';
$cencosApiUrl = $baseModUrl . '/get_cencos_galpones.php';
$hoy = date('Y-m-d');
$mesActual = date('Y-m');
$anio = date('Y');

// Agrupar granjas por zona
$granjasPorZona = [];
foreach ($granjasZonas as $g) {
    $zona = !empty($g['zona']) ? $g['zona'] : 'Sin zona';
    $granjasPorZona[$zona][] = $g;
}
ksort($granjasPorZona);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/../../../core/lib/ix2_head_theme_snippet.php'; ?>
    <title>Mortalidad — Listado</title>
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
        .btn-export-pdf {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none; padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            color: white; border-radius: 0.75rem; cursor: pointer;
            display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-export-pdf:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary {
            background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
            border: none; padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            color: white; border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary:hover { filter: brightness(1.05); }
        .btn-primary:disabled { opacity: 0.55; cursor: not-allowed; filter: none; }
        .btn-outline {
            background: white; border: 1px solid #d1d5db; color: #374151;
            padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            border-radius: 0.75rem; cursor: pointer;
        }
        .btn-outline:hover { background: #f9fafb; }
        .edit-evidencia-item { position: relative; display: inline-block; }
        .edit-evidencia-item .btn-quitar-foto {
            position: absolute; top: -6px; right: -6px; width: 22px; height: 22px;
            border-radius: 9999px; background: #dc2626; color: #fff; border: none;
            font-size: 12px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
        .mort-evidencia-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .mort-evidencia-grid img {
            width: 96px; height: 96px; object-fit: cover; border-radius: 8px;
            border: 1px solid #e5e7eb; cursor: pointer;
        }
        .mort-detalle-pos { border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px; margin-bottom: 10px; }
        .mort-sexo-card {
            background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 10px 12px; min-height: 100%;
        }
        .mort-sexo-card.mort-sexo-vacio { background: #fff; border-style: dashed; }
        .mort-sexo-card-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #e5e7eb;
        }
        .btn-quitar-linea-sexo-edit {
            display: inline-flex; align-items: center; gap: 0.35rem;
            background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
            border-radius: 0.5rem; padding: 0.3rem 0.55rem; font-size: 0.7rem; font-weight: 600;
            cursor: pointer; line-height: 1.2; white-space: nowrap;
        }
        .btn-quitar-linea-sexo-edit:hover { background: #fee2e2; color: #b91c1c; border-color: #f87171; }
        .granja-optgroup-label {
            font-weight: 600; font-size: 0.8rem; color: #059669;
            padding: 4px 8px; background: #ecfdf5; border-radius: 4px; margin: 4px 0;
        }
        .btn-grupos-filtro {
            width: 100%; text-align: left; padding: 0.5rem 0.75rem; font-size: 0.875rem;
            border: 1px solid #d1d5db; border-radius: 0.5rem; background: #fff; color: #374151;
            cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
            min-height: 38px;
        }
        .btn-grupos-filtro:hover { border-color: #42a5f5; background: #f0f9ff; }
        .aud-grupo-tipo { border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; }
        .aud-grupo-tipo h4 { font-weight: 700; color: #1e3a5f; margin: 0 0 10px 0; font-size: 0.95rem; }
        .aud-grupo-item { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
        .aud-grupo-item input[type="checkbox"] { width: 1rem; height: 1rem; accent-color: #42a5f5; }
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
            font-weight: 700; color: #059669; background: #ecfdf5;
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <button type="button" id="btnToggleFiltrosMort"
            class="w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 transition">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔎</span>
                <h3 class="text-base font-semibold text-gray-800">Filtros de búsqueda</h3>
            </div>
            <svg id="iconoFiltrosMort" class="w-5 h-5 text-gray-600 transition-transform duration-300"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="contenidoFiltrosMort" class="px-6 pb-6 pt-4 hidden">
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

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-warehouse mr-1 text-sky-600"></i> Granja y campaña
                    </label>
                    <div class="selector-display">
                        <input id="mrt-list-granja-resumen" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500 hc-sel-granja" readonly placeholder="Clic para seleccionar" value="">
                    </div>
                    <input id="mrt-list-h-granja" type="hidden" value="">
                    <input id="mrt-list-h-campania" type="hidden" value="">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-home mr-1 text-sky-600"></i> Galpón
                    </label>
                    <select id="filtroGalpon" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500" disabled>
                        <option value="">Todos</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-layer-group mr-1 text-sky-600"></i> Tipo de registro
                    </label>
                    <button type="button" id="btnAbrirGruposFiltro" class="btn-grupos-filtro">
                        <span id="lblGruposFiltro">Todos</span>
                    </button>
                </div>
            </div>
            <div class="dashboard-actions mt-6 flex flex-wrap justify-start gap-4">
                <button type="button" id="btnFiltrarMort" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <button type="button" id="btnLimpiarMort" class="px-6 py-2.5 rounded-lg border border-gray-300 text-gray-700 bg-gray-100 hover:bg-gray-200 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
                <button type="button" id="btnExportarPdfMort" class="px-6 py-2.5 text-white font-medium rounded-lg transition inline-flex items-center gap-2"
                    style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); box-shadow: 0 4px 6px rgba(220, 38, 38, 0.3);">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="resumen-card"><div class="etiqueta">Registros</div><div class="valor" id="resumenRegistros">0</div></div>
        <div class="resumen-card"><div class="etiqueta">Total aves</div><div class="valor" id="resumenTotalAves">0</div></div>
        <div class="resumen-card"><div class="etiqueta">Machos</div><div class="valor" id="resumenMachos">0</div></div>
        <div class="resumen-card"><div class="etiqueta">Hembras</div><div class="valor" id="resumenHembras">0</div></div>
    </div>

    <div class="tabla-listado-wrapper bg-white rounded-xl shadow-md p-5">
        <div class="table-wrapper overflow-x-auto">
            <table id="tablaMortalidad" class="data-table display w-full text-sm border-collapse config-table" style="width:100%">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Num. fac</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Tipo reg.</th>
                        <th>Subtipo</th>
                        <th>Granja / campaña</th>
                        <th>Galpón</th>
                        <th>Edad</th>
                        <th>Machos</th>
                        <th>Hembras</th>
                        <th>Total</th>
                        <th>Usuario</th>
                        <th>Detalle</th>
                        <th>Accion</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div id="modalDetalleMort" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h5 class="text-lg font-semibold text-gray-800" id="modalDetalleMortTitulo">Detalle</h5>
            <button type="button" id="btnCerrarDetalleMort" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 overflow-auto flex-1 text-sm" id="modalDetalleMortBody"></div>
    </div>
</div>

<div id="modalEditarMort" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h5 class="text-lg font-semibold text-gray-800" id="modalEditarMortTitulo">Editar registro</h5>
            <button type="button" id="btnCerrarEditarMort" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 overflow-auto flex-1 text-sm" id="modalEditarMortBody"></div>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-end gap-2">
            <button type="button" id="btnCancelarEditarMort" class="btn-outline">Cancelar</button>
            <button type="button" id="btnGuardarEditarMort" class="btn-primary"><i class="fas fa-save"></i> Guardar</button>
        </div>
    </div>
</div>

<div id="modalGruposFiltro" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-[60] p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
            <h5 class="text-lg font-semibold text-gray-800">Filtrar por tipo de registro</h5>
            <button type="button" id="btnCerrarGruposFiltro" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-5 py-4 overflow-auto flex-1">
            <p class="text-sm text-gray-500 mb-4">Seleccione uno o más tipos de registro. Si no marca ninguno, se muestran todos.</p>
            <?php foreach ($arbolGrupos as $bloque): ?>
                <div class="aud-grupo-tipo" data-tipo="<?php echo htmlspecialchars((string) $bloque['tipo']); ?>">
                    <h4><i class="fas fa-dove mr-1 text-sky-600"></i><?php echo htmlspecialchars((string) $bloque['label']); ?></h4>
                    <?php foreach ($bloque['items'] as $item): ?>
                        <label class="aud-grupo-item cursor-pointer">
                            <input type="checkbox" class="chk-grupo-filtro" value="<?php echo htmlspecialchars((string) $item['key']); ?>">
                            <span><?php echo htmlspecialchars((string) $item['label']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="px-5 py-3 border-t flex justify-between gap-2">
            <button type="button" id="btnLimpiarGruposModal" class="btn-outline text-sm py-2 px-3">Quitar seleccion</button>
            <button type="button" id="btnAplicarGruposModal" class="btn-primary text-sm py-2 px-4">Aplicar</button>
        </div>
    </div>
</div>

<?php
$gmc_id_prefix = 'mrt-list';
$gmc_shell_panel_id = 'mrt-list-modal-granjas';
$gmc_show_chk_todas = false;
require __DIR__ . '/../../../core/lib/modals/modal_granjas_campanias.php';
?>

<script>
window.MORT_LISTADO_CFG = {
    listarUrl: <?= json_encode($listarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    detalleUrl: <?= json_encode($detalleApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    eliminarUrl: <?= json_encode($eliminarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    eliminarDetalleUrl: <?= json_encode($eliminarDetalleApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    actualizarUrl: <?= json_encode($actualizarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    motivosUrl: <?= json_encode($motivosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    filtrosUrl: <?= json_encode($filtrosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    pdfUrl: <?= json_encode($pdfApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    cencosUrl: <?= json_encode($cencosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    sinPermisos: false,
    puedeEditarEliminar: <?= $puedeEditarEliminar ? 'true' : 'false' ?>,
    tieneRolSistemas: <?= $tieneRolSistemas ? 'true' : 'false' ?>,
    usuarioRegistro: <?= json_encode($_SESSION['usuario'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
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
?>,
    arbolGrupos: <?php echo json_encode($arbolGrupos, JSON_UNESCAPED_UNICODE); ?>
};
</script>
<script src="../js/gmc-granjas-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../js/gmc-granjas-campanias.js'); ?>"></script>
<script src="js/mortalidad-listado-dashboard.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-listado-dashboard.js'); ?>"></script>
</body>
</html>
