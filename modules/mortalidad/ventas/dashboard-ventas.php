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
mysqli_close($conexion);

include_once __DIR__ . '/../../../core/lib/datatables_lang_es.php';

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$modMortUrl = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
$listarApiUrl = $baseModUrl . '/listar_ventas.php';
$detalleApiUrl = $baseModUrl . '/get_detalle_venta.php';
$filtrosApiUrl = $modMortUrl . '/listado/get_opciones_filtros.php';
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
    <title>Mortalidad — Ventas</title>
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
            background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
            border: none; padding: 0.625rem 1.5rem; font-size: 0.875rem; font-weight: 600;
            color: white; border-radius: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;
        }
        .btn-primary:hover { filter: brightness(1.05); }
        .btn-primary:disabled { opacity: 0.55; cursor: not-allowed; filter: none; }
        .dt-top-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.5rem 0; flex-wrap: wrap; gap: 0.75rem;
        }
        .dt-bottom-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.85rem 1.1rem 0.5rem; flex-wrap: wrap; gap: 0.75rem;
        }
        /* ── Tabla "mrtv-tabla": estilos propios. No usa data-table/config-table
           porque dashboard-config.css pisa la cabecera agrupada con !important
           (fondo plano, border:none y word-break que parte las etiquetas). ── */
        #tablaVentas,
        table.mrtv-tabla {
            width: 100% !important;
            min-width: 1900px;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: auto;
            font-size: 0.8125rem;
        }
        table.mrtv-tabla th,
        table.mrtv-tabla td {
            white-space: nowrap !important;
            word-break: normal !important;
            overflow-wrap: normal !important;
            vertical-align: middle;
        }
        .venta-total-col { font-weight: 600; color: #be185d; }
        .mort-total-col { font-weight: 600; color: #c2410c; }

        /* Indicador de procesamiento: el MISMO estilo que usa el listado
           (dashboard-listado.php) — el dataTables_processing estándar de
           DataTables con su píldora "Procesando…", sin estilos propios.
           Solo se oculta mientras el modal de detalle está abierto, para que
           no aparezca duplicado detrás del spinner del modal. */
        .mrtv-tbl-wrap {
            min-height: 10rem;
        }
        #tablaVentas_wrapper .dataTables_processing {
            z-index: 30;
            border-radius: 9999px;
        }
        body.mrt-detalle-abierto #tablaVentas_wrapper div.dataTables_processing,
        body.mrt-detalle-abierto .dataTables_processing {
            display: none !important;
        }

        /* ── Mini-tabla del detalle (mortalidad lado a lado Macho | Hembra) ──
           table-layout:fixed + colgroup (con anchos compensados por la col N°)
           hacen que la división entre bloques caiga exactamente al centro,
           igual que las tarjetas de resumen. El wrapper redondea las esquinas
           y evita bordes dobles (las celdas solo dibujan bordes internos). */
        #modalDetalleVenta .mrtv-det-wrap {
            border: 1px solid #e2e8f0;
            border-radius: 0.8rem;
            overflow: hidden;
        }
        #modalDetalleVenta table.mrtv-det-tabla {
            border-collapse: collapse;
            font-size: 0.78rem;
            table-layout: fixed;
            width: 100%;
        }
        #modalDetalleVenta table.mrtv-det-tabla th,
        #modalDetalleVenta table.mrtv-det-tabla td {
            border-right: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.4rem 0.6rem;
            vertical-align: middle;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        /* Sin borde exterior (lo pone el wrapper): limpia el perímetro */
        #modalDetalleVenta table.mrtv-det-tabla th:last-child,
        #modalDetalleVenta table.mrtv-det-tabla td:last-child { border-right: none; }
        #modalDetalleVenta table.mrtv-det-tabla tbody tr:last-child td { border-bottom: none; }
        #modalDetalleVenta .mrtv-det-th-num {
            background: #f1f5f9;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 600;
            text-align: center;
        }
        #modalDetalleVenta .mrtv-det-th-grupo {
            font-size: 0.76rem;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        #modalDetalleVenta .mrtv-det-grupo-macho { background-color: #eff6ff; color: #1d4ed8; }
        #modalDetalleVenta .mrtv-det-grupo-hembra { background-color: #fdf2f8; color: #be185d; }
        #modalDetalleVenta .mrtv-det-th-sub {
            background: #f8fafc;
            color: #64748b;
            font-size: 0.66rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            text-align: left;
            padding: 0.35rem 0.6rem;
            white-space: nowrap;
        }
        #modalDetalleVenta .mrtv-det-th-cant { text-align: right; }
        #modalDetalleVenta .mrtv-det-td { padding: 0.38rem 0.6rem; }
        /* Línea divisoria central Macho | Hembra (delgada, en el 50% exacto) */
        #modalDetalleVenta .mrtv-det-col-sep {
            border-left: 2px solid #cbd5e1 !important;
        }
        #modalDetalleVenta .mrtv-det-celda-vacia {
            background: #fcfcfd;
        }

        /* ── Contenedor con scroll horizontal y cabecera sticky (tipo gatilladores) ── */
        .mrtv-tbl-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.9rem;
            padding: 0.95rem 1.1rem 0.8rem;
        }
        .mrtv-tbl-toolbar .dataTables_length,
        .mrtv-tbl-toolbar .dataTables_filter {
            margin: 0;
        }
        .mrtv-tbl-wrap {
            position: relative;
            margin: 0 1.1rem;
            overflow-x: auto !important;   /* siempre scroll horizontal propio */
            overflow-y: clip !important;   /* vence al visible de dashboard-config */
            border: 1px solid #e2e8f0;
            border-radius: 0.9rem;         /* redondea también la cabecera azul */
            background: #fff;
            -webkit-overflow-scrolling: touch;
        }
        /* Barra fija que clona la cabecera cuando ésta sale del viewport */
        .mrtv-sticky-bar {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 45;
            box-sizing: border-box;
            pointer-events: none;
            overflow: hidden;
            isolation: isolate;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.18);
            border-radius: 0.5rem;
            background: var(--pa-table-head-bg, #1e3a8a);
        }
        .mrtv-sticky-bar.is-active { display: block; }
        .mrtv-sticky-bar-scroll {
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
            height: 100%;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .mrtv-sticky-bar-scroll::-webkit-scrollbar { display: none; }
        .mrtv-sticky-bar table.mrtv-sticky-clone {
            margin: 0;
            table-layout: fixed;
            /* max-content + colgroup: el clon mide exactamente la suma de los
               anchos de columna copiados, sin pelear con el width:100%!important
               ni con el min-width:1900px de table.mrtv-tabla. */
            width: max-content !important;
            min-width: 0 !important;
            max-width: none;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 0;
            box-shadow: none;
            background: transparent;
            font-size: 0.8125rem;
        }
        /* Cuando la cabecera está "pegada" arriba, la original se oculta para
           no verse duplicada dentro del contenedor (el clon la sustituye). */
        table.mrtv-tabla thead.mrtv-thead-is-stuck { visibility: hidden; }

        /* ── Cabecera agrupada: MISMO color que el listado (dashboard-listado)
           usa la variable unificada --pa-table-head-bg (#1e3a8a en claro).
           Líneas divisorias verticales y horizontales delgadas (1px) entre
           todas las celdas de la cabecera. Estilos por CLASE (no por #id)
           para que apliquen también al clon sticky. */
        table.mrtv-tabla thead th {
            background: var(--pa-table-head-bg, #1e3a8a) !important;
            color: #fff !important;
            font-weight: 700;
            font-size: 0.72rem;
            line-height: 1.3;
            text-align: center;
            vertical-align: middle;
            padding: 0.6rem 0.75rem;
            border: none;
            border-right: 1px solid rgba(255,255,255,0.55);
            border-bottom: 1px solid rgba(255,255,255,0.55);
            box-shadow: none;
        }
        /* Fila 1 (grupos Macho/Hembra): letra mayor */
        table.mrtv-tabla thead tr:first-child th {
            font-size: 0.78rem;
            letter-spacing: 0.03em;
            padding: 0.68rem 0.9rem;
        }
        /* Fila 2 (subcolumnas): mismo color de cabecera, jerarquía por tamaño */
        table.mrtv-tabla thead tr:nth-child(2) th {
            background: var(--pa-table-head-bg, #1e3a8a) !important;
            font-size: 0.67rem;
            font-weight: 600;
            padding: 0.5rem 0.65rem;
        }
        /* Fila 3 (Fracción / % bajo cada relación): la más pequeña de todas */
        table.mrtv-tabla thead tr:nth-child(3) th {
            background: var(--pa-table-head-bg, #1e3a8a) !important;
            font-size: 0.6rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            padding: 0.38rem 0.55rem;
            text-align: center;
        }
        /* Separador de grupo (Macho | Hembra): línea delgada */
        table.mrtv-tabla thead th.th-grp-sep {
            border-right: 1px solid #fff !important;
        }
        /* Cuerpo: filas alternas limpias + hover azul suave, todo centrado */
        table.mrtv-tabla tbody td {
            padding: 0.5rem 0.6rem;
            border-bottom: 1px solid #eef2f7;
            text-align: center;
            color: #374151;
            background-color: #ffffff;
        }
        table.mrtv-tabla tbody tr:nth-child(even) td { background-color: #f8fafc; }
        table.mrtv-tabla tbody tr:hover td { background-color: #eff6ff !important; }
        /* Divisor vertical delgado tras Galpón (inicio del bloque de datos),
           separador Macho|Hembra (a la derecha de % Mort. Desp./Vent. de macho)
           y separador antes de Detalle (a la derecha de % Mort. Desp./Vent. de
           hembra). */
        table.mrtv-tabla tbody td:nth-child(6) {
            border-left: 1px solid #cbd5e1;
        }
        table.mrtv-tabla tbody td:nth-child(12),
        table.mrtv-tabla tbody td:nth-child(19) {
            border-right: 1px solid #cbd5e1;
        }
        /* Línea delgada que separa la pareja de relación TOTAL de la columna
           de Mort. Desp. (cols 9 y 16: % Mort.Tot/Vent.). */
        table.mrtv-tabla tbody td:nth-child(9),
        table.mrtv-tabla tbody td:nth-child(16) {
            border-right: 1px solid #dbe1ea;
        }
        /* Columna de FRACCIÓN de la relación (p. ej. 112/3726) */
        table.mrtv-tabla td.col-rel-frac {
            font-size: 0.7rem;
            color: #6b7280;
        }
        /* Columna de PORCENTAJE de la relación (p. ej. 15,6%), con dígitos de
           ancho uniforme para que la columna se vea alineada. */
        table.mrtv-tabla td.col-rel-pct {
            font-size: 0.7rem;
            color: #475569;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        table.mrtv-tabla td.col-mort-tot {
            font-weight: 700;
            color: #c2410c;
        }
        /* Mortalidad de DESPACHO (absoluta): un tono ámbar para distinguirla
           de la Mort. Tot. (naranja) dentro del mismo bloque. */
        table.mrtv-tabla td.col-mort-desp {
            font-weight: 600;
            color: #b45309;
        }
        /* Etiqueta "SIN REG.": cuando no hubo movimiento S808 del sexo en el
           día. Gris y pequeño, para no confundirse con el "0" real. Solo
           aparece en las columnas ABSOLUTAS (Mort. Tot. y Mort. Desp.); las
           relaciones (fracción/%) quedan vacías cuando no aplican. */
        table.mrtv-tabla .mrtv-sin-reg {
            display: inline-block;
            color: #94a3b8;
            font-size: 0.6rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            line-height: 1.2;
        }
        table.mrtv-tabla td.col-mort-tot .mrtv-sin-reg,
        table.mrtv-tabla td.col-mort-desp .mrtv-sin-reg {
            color: #94a3b8;
            font-weight: 600;
        }
        table.mrtv-tabla td.col-venta {
            font-weight: 600;
            color: #0f766e;
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <button type="button" id="btnToggleFiltrosVnt"
            class="w-full flex items-center justify-between px-6 py-4 bg-gray-50 hover:bg-gray-100 transition">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔎</span>
                <h3 class="text-base font-semibold text-gray-800">Filtros de búsqueda</h3>
            </div>
            <svg id="iconoFiltrosVnt" class="w-5 h-5 text-gray-600 transition-transform duration-300 rotate-180"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="contenidoFiltrosVnt" class="px-6 pb-6 pt-4">
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
                        <option value="ENTRE_MESES">Entre meses</option>
                        <option value="ULTIMA_SEMANA" selected>Última semana</option>
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
                        <input id="mrt-vnt-granja-resumen" type="text" class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 focus:ring-2 focus:ring-sky-500 hc-sel-granja" readonly placeholder="Clic para seleccionar" value="">
                    </div>
                    <input id="mrt-vnt-h-granja" type="hidden" value="">
                    <input id="mrt-vnt-h-campania" type="hidden" value="">
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
            <div class="dashboard-actions mt-6 flex flex-wrap justify-start gap-4">
                <button type="button" id="btnFiltrarVnt" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <button type="button" id="btnLimpiarVnt" class="px-6 py-2.5 rounded-lg border border-gray-300 text-gray-700 bg-gray-100 hover:bg-gray-200 font-medium inline-flex items-center gap-2">
                    <i class="fas fa-eraser"></i> Limpiar
                </button>
            </div>
        </div>
    </div>

    <div class="tabla-listado-wrapper bg-white rounded-2xl shadow-md overflow-hidden">
        <table id="tablaVentas" class="mrtv-tabla text-sm">
            <thead>
                <tr>
                    <th rowspan="3" style="text-align:left;">N°</th>
                    <th rowspan="3">Fecha</th>
                    <th rowspan="3">Granja</th>
                    <th rowspan="3">Campaña</th>
                    <th rowspan="3">Galpón</th>
                    <th colspan="7" class="th-grp-macho th-grp-sep">Macho</th>
                    <th colspan="7" class="th-grp-hembra th-grp-sep">Hembra</th>
                    <th rowspan="3">Detalle</th>
                </tr>
                <tr>
                    <th rowspan="2" class="th-sub-m" title="Venta de pollos machos">Venta</th>
                    <th rowspan="2" class="th-sub-m" title="Mortalidad total de machos del día (toda la mortalidad S808)">Mort. Tot.</th>
                    <th colspan="2" class="th-sub-m" title="Relación mortalidad total / venta de machos">Mort. Tot. / Vent.</th>
                    <th rowspan="2" class="th-sub-m" title="Mortalidad de despacho de machos del día (categoría Despacho o causa de despacho)">Mort. Desp.</th>
                    <th colspan="2" class="th-sub-m th-grp-sep" title="Relación mortalidad de despacho / venta de machos">Mort. Desp. / Vent.</th>
                    <th rowspan="2" class="th-sub-h" title="Venta de pollos hembras">Venta</th>
                    <th rowspan="2" class="th-sub-h" title="Mortalidad total de hembras del día (toda la mortalidad S808)">Mort. Tot.</th>
                    <th colspan="2" class="th-sub-h" title="Relación mortalidad total / venta de hembras">Mort. Tot. / Vent.</th>
                    <th rowspan="2" class="th-sub-h" title="Mortalidad de despacho de hembras del día (categoría Despacho o causa de despacho)">Mort. Desp.</th>
                    <th colspan="2" class="th-sub-h th-grp-sep" title="Relación mortalidad de despacho / venta de hembras">Mort. Desp. / Vent.</th>
                </tr>
                <tr>
                    <th title="Fracción sin dividir (mort / venta)">Fracción</th>
                    <th title="Porcentaje de mortalidad sobre la venta">%</th>
                    <th title="Fracción sin dividir (mort / venta)">Fracción</th>
                    <th title="Porcentaje de mortalidad sobre la venta">%</th>
                    <th title="Fracción sin dividir (mort / venta)">Fracción</th>
                    <th title="Porcentaje de mortalidad sobre la venta">%</th>
                    <th title="Fracción sin dividir (mort / venta)">Fracción</th>
                    <th title="Porcentaje de mortalidad sobre la venta">%</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div id="modalDetalleVenta" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h5 class="text-lg font-semibold text-gray-800" id="modalDetalleVentaTitulo">Detalle de venta</h5>
            <button type="button" id="btnCerrarDetalleVenta" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 overflow-auto flex-1 text-sm" id="modalDetalleVentaBody"></div>
    </div>
</div>

<?php
$gmc_id_prefix = 'mrt-vnt';
$gmc_shell_panel_id = 'mrt-vnt-modal-granjas';
$gmc_show_chk_todas = false;
require __DIR__ . '/../../../core/lib/modals/modal_granjas_campanias.php';
?>

<script>
window.MORT_VENTAS_CFG = {
    listarUrl: <?= json_encode($listarApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    filtrosUrl: <?= json_encode($filtrosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    detalleUrl: <?= json_encode($detalleApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    sinPermisos: false,
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
<script src="js/mortalidad-ventas.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-ventas.js'); ?>"></script>
</body>
</html>
