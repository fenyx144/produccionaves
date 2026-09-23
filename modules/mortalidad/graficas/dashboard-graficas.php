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

require_once __DIR__ . '/graficas_lib.php';
if (file_exists(__DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php')) {
    require_once __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';
}
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conexion = conectar_joya_mysqli();
$granjasZonas = [];
if ($conexion) {
    try {
        if (function_exists('hc_granjas_listar_para_selector')) {
            $granjasZonas = hc_granjas_listar_para_selector($conexion);
        }
    } catch (\Throwable $e) {
        $granjasZonas = [];
    }
    mysqli_close($conexion);
}

$granjasPorZona = [];
foreach ($granjasZonas as $g) {
    $zona = !empty($g['zona']) ? $g['zona'] : 'Sin zona';
    $granjasPorZona[$zona][] = $g;
}
ksort($granjasPorZona);

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$datosApiUrl = $baseModUrl . '/get_datos_graficas.php';
$campaniasApiUrl = dirname($baseModUrl) . '/get_campanias_mortalidad.php';
$hoy = date('Y-m-d');
$mesActual = date('Y-m');
$anio = date('Y');
$filtrosDefecto = mort_graficas_filtros_defecto();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/../../../core/lib/ix2_head_theme_snippet.php'; ?>
    <title>Mortalidad — Gráficas</title>
    <link rel="stylesheet" href="../../../assets/css/output.css">
    <link rel="stylesheet" href="../../../assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/modal_granjas_campanias.css?v=<?php echo (int) @filemtime(__DIR__ . '/../../../assets/css/modal_granjas_campanias.css'); ?>">
    <?php require_once __DIR__ . '/../../../core/lib/sip_listado_stylesheet_links.php'; sip_echo_listado_stylesheet_links(); ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="../js/auth.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background: linear-gradient(180deg, #f1f5f9 0%, #eef2f7 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        /* ── Botones ─────────────────────────────────────────────── */
        .btn-primary {
            background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
            border: none; padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            color: white; border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
            box-shadow: 0 4px 10px rgba(30, 136, 229, 0.28);
        }
        .btn-primary:hover { filter: brightness(1.06); }
        .btn-outline {
            background: white; border: 1px solid #d1d5db; color: #374151;
            padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-outline:hover { background: #f9fafb; border-color: #9ca3af; }

        /* ── Tarjetas de gráfica (una por lote) ──────────────────── */
        .grafica-card {
            background: #fff;
            border: 1px solid #eef1f6;
            border-radius: 1.25rem;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            transition: box-shadow .2s ease;
        }
        .grafica-card:hover { box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10); }
        .grafica-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
            padding: 1.1rem 1.4rem 0.35rem;
        }
        .grafica-titulo { font-size: 1rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: .55rem; }
        .grafica-titulo i { color: #1e88e5; font-size: .95rem; }
        .grafica-sub { font-size: .75rem; color: #94a3b8; padding: 0 1.4rem .25rem; }
        .grafica-body { padding: 1rem 1.4rem 1.4rem; }
        .grafica-wrap { position: relative; height: 340px; }

        /* Estados vacíos / carga */
        .grafica-empty {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: .6rem;
            color: #94a3b8; text-align: center; padding: 1.5rem;
        }
        .grafica-empty i { font-size: 2.4rem; opacity: .55; }
        .grafica-loading {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,.65); backdrop-filter: blur(2px); z-index: 5;
        }
        .spinner-ring {
            width: 46px; height: 46px; border-radius: 50%;
            border: 4px solid rgba(30,136,229,.15); border-top-color: #1e88e5;
            animation: grf-spin .8s linear infinite;
        }
        @keyframes grf-spin { to { transform: rotate(360deg); } }

        select.granja-select option:disabled { font-weight: 700; color: #1e88e5; background: #eff6ff; }
        .selector-display input { cursor: pointer; background: #fff; }

        /* Selector de sexo en la cabecera de cada gráfica */
        .grafica-sexo {
            display: flex; align-items: center; gap: .5rem;
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: .6rem; padding: .3rem .55rem .3rem .7rem;
        }
        .grafica-sexo label {
            font-size: .68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; color: #94a3b8; margin: 0;
        }
        .grafica-sexo select {
            border: none; background: transparent; font-size: .82rem;
            font-weight: 600; color: #0f172a; cursor: pointer;
            outline: none; padding: 0 .1rem;
        }
        .grafica-sexo select:hover { color: #1e88e5; }
        .grafica-sexo:focus-within {
            border-color: #1e88e5; box-shadow: 0 0 0 3px rgba(30,136,229,.12);
        }

        /* ── Modal: galpones colapsables (cabecera "Todos" + chevron) ── */
        .mrt-grf-modal-granjas .hc-galpon-head--collapsed {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.7rem;
            padding: 0.4rem 0.6rem;
            transition: background .15s ease, border-color .15s ease;
        }
        .mrt-grf-modal-granjas .hc-galpon-head--collapsed:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .mrt-grf-modal-granjas .hc-galpon-col--collapsible.hc-galpon-col--expanded .hc-galpon-head--collapsed {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .mrt-grf-modal-granjas .hc-exp-galp-btn {
            color: #64748b;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 0.4rem;
            width: 1.4rem;
            height: 1.4rem;
            transition: color .15s ease, background .15s ease, border-color .15s ease;
        }
        .mrt-grf-modal-granjas .hc-exp-galp-btn:hover {
            color: #1d4ed8;
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .mrt-grf-modal-granjas .hc-galpon-col--collapsible .hc-galpon-wrap {
            margin-top: 0.4rem;
            padding: 0.5rem 0.6rem;
            border-top: 1px dashed #cbd5e1;
            background: #fbfdff;
            border-radius: 0 0 0.7rem 0.7rem;
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <!-- ══════════ FILTROS ══════════ -->
    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <button type="button" id="btnToggleFiltrosGrf"
            class="w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 transition">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔎</span>
                <h3 class="text-base font-semibold text-gray-800">Filtros de búsqueda</h3>
            </div>
            <svg id="iconoFiltrosGrf" class="w-5 h-5 text-gray-600 transition-transform duration-300"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="contenidoFiltrosGrf" class="px-6 pb-6 pt-4">
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
                        <option value="ENTRE_MESES" <?php echo ($filtrosDefecto['periodoTipo'] ?? '') === 'ENTRE_MESES' ? 'selected' : ''; ?>>Entre meses</option>
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
                        <input id="fechaFin" type="date" value="<?php echo $hoy; ?>"
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
                        <input id="mesInicio" type="month" value="<?php echo $filtrosDefecto['mesInicio']; ?>"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                    <div class="min-w-[180px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes fin</label>
                        <input id="mesFin" type="month" value="<?php echo $filtrosDefecto['mesFin']; ?>"
                            class="w-full px-2 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-sky-500 text-sm">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-warehouse mr-1 text-sky-600"></i> Granja, campaña y galpón
                    </label>
                    <div class="selector-display">
                        <input id="mrt-grf-granja-resumen" type="text"
                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500 hc-sel-granja"
                            readonly placeholder="Clic para seleccionar (puedes elegir varias)" value="">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-exchange-alt mr-1 text-sky-600"></i> Operación
                    </label>
                    <select id="filtroOperacion"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="todos" selected>Todos</option>
                        <option value="mortalidad">Mortalidad</option>
                        <option value="venta">Venta</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-venus-mars mr-1 text-sky-600"></i> Sexo
                    </label>
                    <select id="filtroSexo"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500">
                        <option value="todos" selected>Todos</option>
                        <option value="macho">Machos</option>
                        <option value="hembra">Hembras</option>
                    </select>
                </div>
            </div>
            <div class="dashboard-actions mt-6 flex flex-wrap justify-start gap-4">
                <button type="button" id="btnFiltrarGrf" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-search"></i> Graficar
                </button>
                <button type="button" id="btnLimpiarGrf" class="px-6 py-2.5 rounded-lg border border-gray-300 text-gray-700 bg-gray-100 hover:bg-gray-200 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════ GRÁFICAS POR LOTE ══════════ -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6" id="lotesGraficas">
        <div class="xl:col-span-2" id="sinLotesMsg">
            <div class="grafica-card">
                <div class="grafica-empty" style="position: static; padding: 3rem 1.5rem;">
                    <i class="fas fa-chart-line"></i>
                    <span>Selecciona al menos una granja, campaña y galpón, luego presiona <b>Graficar</b>.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$gmc_id_prefix = 'mrt-grf';
$gmc_shell_panel_id = 'mrt-grf-modal-granjas';
$gmc_show_chk_todas = true;
$gmc_show_periodo_campanias = true;
require __DIR__ . '/../../../core/lib/modals/modal_granjas_campanias.php';
?>

<script>
window.MORT_GRAFICAS_CFG = {
    datosUrl: <?= json_encode($datosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    campaniasUrl: <?= json_encode($campaniasApiUrl, JSON_UNESCAPED_UNICODE) ?>,
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
<script src="../../../assets/js/gmc-periodo-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../../../assets/js/gmc-periodo-campanias.js'); ?>"></script>
<script src="../js/gmc-granjas-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../js/gmc-granjas-campanias.js'); ?>"></script>
<script src="js/mortalidad-graficas.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-graficas.js'); ?>"></script>
</body>
</html>
