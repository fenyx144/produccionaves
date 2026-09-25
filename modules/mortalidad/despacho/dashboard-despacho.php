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

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$modMortUrl = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
$apiUrl = $baseModUrl . '/get_analisis_despacho.php';
$campaniasApiUrl = $modMortUrl . '/get_campanias_mortalidad.php';
$granjasMetaUrl = $baseModUrl . '/get_granjas_meta.php';
$filtrosApiUrl = $modMortUrl . '/listado/get_opciones_filtros.php';
$hoy = date('Y-m-d');
$mesActual = date('Y-m');
$anio = date('Y');
$primerDiaMes = date('Y-m-01');
$ultimoDiaMes = date('Y-m-t');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/../../../core/lib/ix2_head_theme_snippet.php'; ?>
    <title>Mortalidad — Despacho</title>
    <link rel="stylesheet" href="../../../assets/css/output.css">
    <link rel="stylesheet" href="../../../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/modal_granjas_campanias.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../assets/css/modal_granjas_campanias.css'); ?>">
    <?php require_once __DIR__ . '/../../../core/lib/sip_listado_stylesheet_links.php'; sip_echo_listado_stylesheet_links(); ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="../js/auth.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
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
            border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-outline:hover { background: #f9fafb; }
        .mdp-panel-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e3a5f;
            margin: 0 0 0.85rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .mdp-panel-title span { font-weight: 500; color: #64748b; font-size: 0.8rem; }
        .mdp-tabla-wrap .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .mdp-tabla-wrap table.config-table { width: 100%; min-width: 280px; }
        .mdp-tabla-wrap .table-wrapper {
            max-height: min(70vh, 640px);
            overflow: auto;
            border-radius: 0.65rem;
            border: 1px solid #e2e8f0;
        }
        .mdp-tabla-wrap table.mdp-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            box-shadow: 0 1px 0 rgba(255,255,255,0.15);
        }
        .mdp-tabla-wrap .col-num { width: 3rem; text-align: center; }
        .mdp-tabla-wrap .col-pct, .mdp-tabla-wrap .col-qty { text-align: right; white-space: nowrap; }
        .mdp-tabla-wrap tbody tr:nth-child(even):not(.mdp-fila-total) { background: #f8fafc; }
        .mdp-tabla-wrap tbody tr:hover:not(.mdp-fila-total) { background: #f0f9ff; }
        .mdp-fila-total td {
            font-weight: 700;
            background: linear-gradient(90deg, #dbeafe 0%, #e0f2fe 50%, #dbeafe 100%) !important;
            color: #0c4a6e;
            border-top: 2px solid #38bdf8;
        }
        .mdp-fila-sin-despacho td { color: #64748b; }
        .mdp-fila-sin-despacho .col-qty, .mdp-fila-sin-despacho .col-pct { opacity: 0.85; }
        .mdp-empty {
            text-align: center; color: #94a3b8; padding: 2rem 1rem; font-size: 0.875rem;
        }
        .mdp-resultados { position: relative; }
        .mdp-loading-overlay {
            position: absolute; inset: 0; z-index: 30;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 0.75rem; min-height: 14rem;
            background: #f8fafc;
            border-radius: 0.75rem;
            color: #475569; font-size: 0.875rem; font-weight: 600;
        }
        .mdp-loading-overlay.lay-hidden { display: none !important; }
        .mdp-resultados.mdp-resultados--busy #mdp-resultados-body {
            visibility: hidden;
            pointer-events: none;
        }
        .mdp-spinner-ring {
            width: 46px; height: 46px; border-radius: 50%;
            border: 4px solid rgba(30, 136, 229, 0.15); border-top-color: #1e88e5;
            animation: mdp-spin 0.8s linear infinite;
        }
        @keyframes mdp-spin { to { transform: rotate(360deg); } }
        .mdp-pager {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 0.75rem; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #e2e8f0;
            font-size: 0.8125rem; color: #475569;
        }
        .mdp-pager-actions { display: flex; gap: 0.5rem; align-items: center; }
        .mdp-pager-btn {
            background: #fff; border: 1px solid #cbd5e1; color: #334155; border-radius: 0.5rem;
            padding: 0.35rem 0.75rem; font-size: 0.8125rem; font-weight: 600; cursor: pointer;
        }
        .mdp-pager-btn:hover:not(:disabled) { background: #f1f5f9; }
        .mdp-pager-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .selector-display input { cursor: pointer; background: #fff; }
        body.mrt-dsp-modal-granjas-open .tabla-listado-wrapper { filter: none; }
        #mrt-dsp-modal-granjas .gmc-modal-inner { max-width: 920px; }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <button type="button" id="btnToggleFiltrosMdp"
            class="w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 transition">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔎</span>
                <h3 class="text-base font-semibold text-gray-800">Filtros de búsqueda</h3>
            </div>
            <svg id="iconoFiltrosMdp" class="w-5 h-5 text-gray-600 transition-transform duration-300 rotate-180"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="contenidoFiltrosMdp" class="px-6 pb-6 pt-4">
            <div class="filter-row-periodo flex flex-wrap items-end gap-4 mb-6">
                <div class="flex-shrink-0" style="min-width: 200px;">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-calendar-alt mr-1 text-sky-600"></i> Fecha
                    </label>
                    <select id="mdp-periodo-tipo"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm cursor-pointer">
                        <option value="POR_FECHA">Por fecha</option>
                        <option value="ENTRE_FECHAS">Entre fechas</option>
                        <option value="POR_MES" selected>Por mes</option>
                        <option value="ENTRE_MESES">Entre meses</option>
                        <option value="ULTIMA_SEMANA">Última semana</option>
                    </select>
                </div>
                <div id="mdp-bloque-fecha-unica" class="mdp-bloque-periodo hidden flex-shrink-0 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-calendar-day mr-1 text-sky-600"></i> Fecha
                    </label>
                    <input id="mdp-fecha-unica" type="date" value="<?php echo htmlspecialchars($hoy, ENT_QUOTES, 'UTF-8'); ?>"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                </div>
                <div id="mdp-bloque-rango-fechas" class="mdp-bloque-periodo hidden flex-shrink-0 flex items-end gap-2">
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                        <input id="mdp-fecha-inicio" type="date" value="<?php echo htmlspecialchars($primerDiaMes, ENT_QUOTES, 'UTF-8'); ?>"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                        <input id="mdp-fecha-fin" type="date" value="<?php echo htmlspecialchars($ultimoDiaMes, ENT_QUOTES, 'UTF-8'); ?>"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                </div>
                <div id="mdp-bloque-mes-unico" class="mdp-bloque-periodo flex-shrink-0 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mes</label>
                    <input id="mdp-mes-unico" type="month" value="<?php echo htmlspecialchars($mesActual, ENT_QUOTES, 'UTF-8'); ?>"
                        class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                </div>
                <div id="mdp-bloque-rango-meses" class="mdp-bloque-periodo hidden flex-shrink-0 flex items-end gap-2">
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes inicio</label>
                        <input id="mdp-mes-inicio" type="month" value="<?php echo htmlspecialchars($anio, ENT_QUOTES, 'UTF-8'); ?>-01"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes fin</label>
                        <input id="mdp-mes-fin" type="month" value="<?php echo htmlspecialchars($mesActual, ENT_QUOTES, 'UTF-8'); ?>"
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
                        <input id="mrt-dsp-granja-resumen" type="text"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500 hc-sel-granja"
                            readonly placeholder="Clic para seleccionar" value="">
                    </div>
                </div>
            </div>

            <div class="dashboard-actions mt-6 flex flex-wrap justify-start gap-4">
                <button type="button" id="mdp-btn-consultar" class="btn-primary">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <button type="button" id="mdp-btn-limpiar" class="btn-outline">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
            </div>
        </div>
    </div>

    <div id="mdp-resultados" class="mdp-resultados">
        <div id="mdp-loading-overlay" class="mdp-loading-overlay lay-hidden" role="status" aria-live="polite" aria-busy="false">
            <div class="mdp-spinner-ring" aria-hidden="true"></div>
            <span id="mdp-loading-text">Cargando análisis…</span>
        </div>

        <div id="mdp-resultados-body">
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <div class="tabla-listado-wrapper bg-white rounded-xl shadow-md p-5 mdp-tabla-wrap">
                <h4 class="mdp-panel-title" id="mdp-titulo-causas">Mortalidad por causa</h4>
                <div class="table-wrapper" id="mdp-tabla-causas">
                    <p class="mdp-empty">Cargando análisis del mes en curso…</p>
                </div>
            </div>
            <div class="tabla-listado-wrapper bg-white rounded-xl shadow-md p-5 mdp-tabla-wrap">
                <h4 class="mdp-panel-title" id="mdp-titulo-etapas">Mortalidad por etapa del proceso</h4>
                <div class="table-wrapper" id="mdp-tabla-etapas">
                    <p class="mdp-empty">Pulse Buscar para cargar etapas.</p>
                </div>
            </div>
        </div>

        <div class="tabla-listado-wrapper bg-white rounded-xl shadow-md p-5 mb-4 mdp-tabla-wrap">
            <h4 class="mdp-panel-title" id="mdp-titulo-resumen">Resumen de mortalidad por granja</h4>
            <div class="table-wrapper" id="mdp-tabla-resumen">
                <p class="mdp-empty">Pulse Buscar para cargar el resumen.</p>
            </div>
            <div id="mdp-resumen-pager" class="mdp-pager lay-hidden" aria-label="Paginación resumen"></div>
        </div>
        </div>
    </div>
</div>

<?php
$gmc_id_prefix = 'mrt-dsp';
$gmc_shell_panel_id = 'mrt-dsp-modal-granjas';
$gmc_show_chk_todas = true;
$gmc_show_periodo_campanias = true;
require __DIR__ . '/../../../core/lib/modals/modal_granjas_campanias.php';
?>

<script>
window.MORT_DESPACHO_CFG = {
    apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>,
    campaniasUrl: <?= json_encode($campaniasApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    granjasMetaUrl: <?= json_encode($granjasMetaUrl, JSON_UNESCAPED_UNICODE) ?>,
    filtrosApiUrl: <?= json_encode($filtrosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    autoCargarAnalisis: true,
    resumenPageSize: 50
};
</script>
<script src="../../../assets/js/gmc-periodo-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../../../assets/js/gmc-periodo-campanias.js'); ?>"></script>
<script src="../js/gmc-granjas-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../js/gmc-granjas-campanias.js'); ?>"></script>
<script src="js/mortalidad-despacho.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-despacho.js'); ?>"></script>
</body>
</html>
