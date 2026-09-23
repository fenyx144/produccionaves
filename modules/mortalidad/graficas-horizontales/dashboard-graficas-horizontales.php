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

require_once __DIR__ . '/graficas_horizontales_lib.php';
if (file_exists(__DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php')) {
    require_once __DIR__ . '/../../../core/lib/hc/hc_granjas_repository.php';
}
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conexion = conectar_joya_mysqli();
$granjasZonas = [];
$granjasConCab = [];
if ($conexion) {
    try {
        if (function_exists('hc_granjas_listar_para_selector')) {
            $granjasZonas = hc_granjas_listar_para_selector($conexion);
        }
    } catch (\Throwable $e) {
        $granjasZonas = [];
    }
    try {
        $qCab = mysqli_query($conexion, "SELECT DISTINCT LPAD(LEFT(TRIM(granja), 3), 3, '0') AS g3
            FROM san_fact_historia_clinica_cab
            WHERE granja IS NOT NULL AND TRIM(granja) <> ''
              AND fecha_inicio IS NOT NULL AND TRIM(fecha_inicio) <> '' AND fecha_inicio <> '0000-00-00'");
        if ($qCab) {
            while ($row = mysqli_fetch_assoc($qCab)) {
                $g3 = trim((string) ($row['g3'] ?? ''));
                if ($g3 !== '') {
                    $granjasConCab[$g3] = true;
                }
            }
        }
    } catch (\Throwable $e) {
        $granjasConCab = [];
    }
    mysqli_close($conexion);
}

// Solo granjas con historia clínica en san_fact_* (las que pueblan el eje X).
$conCab = $granjasConCab;
$granjasZonas = array_values(array_filter($granjasZonas, static function (array $g) use ($conCab): bool {
    if (empty($conCab)) {
        return true;
    }
    $g3 = substr(str_pad(trim((string) ($g['granja'] ?? '')), 3, '0', STR_PAD_LEFT), 0, 3);

    return $g3 === '' || isset($conCab[$g3]);
}));

$granjasPorZona = [];
foreach ($granjasZonas as $g) {
    $zona = !empty($g['zona']) ? $g['zona'] : 'Sin zona';
    $granjasPorZona[$zona][] = $g;
}
ksort($granjasPorZona);

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$datosApiUrl = $baseModUrl . '/get_graficas_horizontales_data.php';
$tiposApiUrl = $baseModUrl . '/get_graficas_horizontales_tipos.php';
$diasApiUrl = $baseModUrl . '/get_graficas_horizontales_dias_pesaje.php';
$granjasApiUrl = $baseModUrl . '/get_graficas_horizontales_granjas.php';
$filtrosDefecto = graficas_horizontales_filtros_defecto();
$vJsShared = (string) (@filemtime(__DIR__ . '/../../../assets/js/sip-grafica-shared.js') ?: time());
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/../../../core/lib/ix2_head_theme_snippet.php'; ?>
    <title>Mortalidad — Gráficas horizontales</title>
    <link rel="stylesheet" href="../../../assets/css/output.css">
    <link rel="stylesheet" href="../../../assets/fontawesome/css/all.min.css">
    <?php require_once __DIR__ . '/../../../core/lib/sip_listado_stylesheet_links.php'; sip_echo_listado_stylesheet_links(); ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'] }
                }
            }
        };
    </script>
    <style>
        body {
            background: linear-gradient(180deg, #f1f5f9 0%, #eef2f7 100%);
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        .dropdown-enter {
            animation: ghzFadeInDown 0.15s ease-out forwards;
        }
        @keyframes ghzFadeInDown {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Filtros ─────────────────────────────────────────────── */
        .hz-card {
            background: #fff;
            border: 1px solid #eef1f6;
            border-radius: 1.25rem;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        }
        .hz-card-head {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: 1rem 1.4rem;
            background: #f8fafc;
            border: none;
            border-radius: 1.25rem 1.25rem 0 0;
            cursor: pointer;
            transition: background .15s ease;
        }
        .hz-card-head:hover { background: #f1f5f9; }
        .hz-card-head-titulo {
            display: flex;
            align-items: center;
            gap: .55rem;
            font-size: .95rem;
            font-weight: 600;
            color: #334155;
            margin: 0;
        }
        .hz-card-head .hz-chevron {
            transition: transform .25s ease;
            color: #64748b;
        }
        .hz-card-head.hz-abierto .hz-chevron { transform: rotate(180deg); }
        .hz-card-body { padding: 1.2rem 1.4rem 1.5rem; }

        .hz-filtros-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .hz-fila-principal,
        .hz-fila-secundaria {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: .75rem 1rem;
        }
        .hz-fila-principal .hz-filtro-granjas { flex: 1 1 260px; min-width: 220px; max-width: 360px; }
        .hz-fila-principal .hz-filtro-fecha { flex: 0 1 142px; min-width: 130px; }
        .hz-fila-principal .hz-filtro-tipos { flex: 0 1 11rem; min-width: 9.5rem; max-width: 11rem; }
        .hz-fila-principal .hz-filtro-semana { flex: 0 1 88px; min-width: 72px; }
        .hz-fila-principal .hz-filtro-dias { flex: 0 1 180px; min-width: 160px; }
        .hz-fila-secundaria .hz-filtro-sexo { flex: 0 1 200px; min-width: 170px; }
        .hz-fila-secundaria .hz-filtro-modo { flex: 0 1 220px; min-width: 180px; }
        .hz-fila-secundaria .hz-filtro-tamano { flex: 0 0 auto; }
        .hz-fila-secundaria .hz-acciones { flex: 0 0 auto; margin-left: auto; }
        .custom-multiselect-dropdown.hidden { display: none !important; }
        .hz-canvas-wrap.hz-con-pct {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        .hz-chart-stack {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            height: 100%;
        }
        .hz-chart-row {
            display: flex;
            align-items: stretch;
            min-height: 0;
            gap: .35rem;
        }
        .hz-chart-row-pct {
            flex: 0 0 200px;
            min-height: 185px;
            max-height: 210px;
        }
        .hz-chart-row-main {
            flex: 1 1 auto;
            min-height: 420px;
        }
        .hz-chart-row + .hz-chart-row {
            border-top: 1px solid #e2e8f0;
            padding-top: .35rem;
        }
        .hz-y-label {
            flex: 0 0 1.35rem;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: .62rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #64748b;
            user-select: none;
            line-height: 1.1;
        }
        .hz-chart-canvas-wrap {
            flex: 1;
            min-width: 0;
            min-height: 0;
            position: relative;
        }
        .hz-chart-canvas-wrap canvas {
            width: 100%;
            height: 100%;
            display: block;
        }
        @media (max-width: 639px) {
            .hz-fila-secundaria .hz-acciones { margin-left: 0; width: 100%; justify-content: flex-end; }
        }
        .hz-filtro { display: flex; flex-direction: column; gap: .35rem; min-width: 0; }
        .hz-filtro > label.hz-lbl,
        .hz-filtro > .hz-lbl {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .045em;
            color: #64748b;
        }
        .hz-input,
        .hz-select {
            width: 100%;
            padding: .5rem .65rem;
            font-size: .85rem;
            border: 1px solid #d7dde6;
            border-radius: .65rem;
            background: #fff;
            color: #0f172a;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .hz-input:focus,
        .hz-select:focus {
            border-color: #1e88e5;
            box-shadow: 0 0 0 3px rgba(30, 136, 229, .14);
        }
        .hz-select { cursor: pointer; }

        /* ── Botones/dropdowns estilo interconsultas (granjas, tipos, días) ── */
        .hz-ms { position: relative; }
        .hz-ms-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            width: 100%;
            min-height: 38px;
            padding: .5rem .875rem;
            font-size: .875rem;
            font-weight: 500;
            color: #1f2937;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: .5rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
            cursor: pointer;
            text-align: left;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .hz-ms-btn:hover { border-color: #94a3b8; }
        .hz-ms-btn.hz-open,
        .hz-ms-btn:focus-visible { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, .2); outline: none; }
        .hz-ms-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0; color: #1f2937; }
        .hz-ms-chev { width: .95rem; flex-shrink: 0; color: #64748b; transition: transform .2s ease; }
        .hz-ms-btn.hz-open .hz-ms-chev { transform: rotate(180deg); }
        .hz-trigger-granjas { text-align: left; }
        .hz-ms-dropdown {
            position: absolute;
            left: 0;
            top: calc(100% + .3rem);
            z-index: 60;
            min-width: 100%;
            width: max-content;
            max-width: 24rem;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: .8rem;
            box-shadow: 0 16px 40px rgba(15, 23, 42, .16);
            padding: .55rem;
            display: none;
        }
        .hz-ms-dropdown.hz-open { display: block; }
        .hz-ms-search-wrap { position: relative; margin-bottom: .45rem; }
        .hz-ms-search-icon { position: absolute; left: .6rem; top: 50%; transform: translateY(-50%); font-size: .7rem; color: #94a3b8; }
        .hz-ms-search {
            width: 100%;
            padding: .4rem .6rem .4rem 1.65rem;
            font-size: .8rem;
            border: 1px solid #e2e8f0;
            border-radius: .5rem;
            outline: none;
            background: #f8fafc;
            color: #0f172a;
        }
        .hz-ms-search:focus { border-color: #1e88e5; background: #fff; }
        .hz-ms-options { max-height: 15rem; overflow-y: auto; display: flex; flex-direction: column; gap: .1rem; }
        .hz-ms-opt {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .38rem .55rem;
            font-size: .8rem;
            font-weight: 500;
            color: #334155;
            border-radius: .5rem;
            cursor: pointer;
            user-select: none;
        }
        .hz-ms-opt:hover { background: #f1f5f9; }
        .hz-ms-opt input { display: none; }
        .hz-ms-opt .hz-ms-check {
            width: 1rem;
            height: 1rem;
            border-radius: .3rem;
            border: 1.5px solid #cbd5e1;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: .55rem;
            color: transparent;
            transition: all .12s ease;
        }
        .hz-ms-opt.hz-on .hz-ms-check { background: #1e88e5; border-color: #1e88e5; color: #fff; }
        .hz-ms-opt.hz-on { background: #eff6ff; color: #1d4ed8; font-weight: 600; }
        .hz-ms-vacio { padding: .6rem; text-align: center; font-size: .78rem; color: #94a3b8; }
        .hz-ms-chip {
            display: inline-flex;
            align-items: center;
            font-size: .68rem;
            font-weight: 700;
            color: #1d4ed8;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            padding: .1rem .45rem;
            white-space: nowrap;
        }

        /* Separar sexos (integrado en el filtro Sexo, estilo interconsultas) */
        .hz-sexo-row { display: flex; gap: .5rem; align-items: center; }
        .hz-sexo-row .hz-select { flex: 1; min-width: 0; }
        .hz-chip-sep {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            height: 38px;
            padding: 0 .75rem;
            font-size: .75rem;
            font-weight: 600;
            color: #334155;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: .5rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
            flex-shrink: 0;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .hz-chip-sep:hover:not(.hz-dis) { border-color: #94a3b8; }
        .hz-chip-sep input { width: 1rem; height: 1rem; accent-color: #2563eb; cursor: pointer; flex-shrink: 0; }
        .hz-chip-sep.hz-on { border-color: #3b82f6; background: #eff6ff; box-shadow: 0 0 0 2px rgba(59, 130, 246, .2); }
        .hz-chip-sep.hz-on span { color: #1d4ed8; }
        .hz-chip-sep.hz-dis { opacity: .45; cursor: not-allowed; }
        .hz-chip-sep.hz-dis input { cursor: not-allowed; }

        /* Tamaño de texto */
        .hz-tamano { display: flex; align-items: center; border: 1px solid #d7dde6; border-radius: .65rem; overflow: hidden; background: #fff; height: 2.15rem; }
        .hz-tamano button {
            border: none;
            background: transparent;
            padding: 0 .7rem;
            font-size: .8rem;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            height: 100%;
        }
        .hz-tamano button:hover { color: #1e88e5; background: #f1f5f9; }
        .hz-tamano .hz-tamano-label {
            font-size: .72rem;
            font-weight: 700;
            color: #475569;
            padding: 0 .45rem;
            border-left: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .hz-acciones { display: flex; gap: .6rem; align-items: center; }
        .hz-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .55rem 1.4rem;
            font-size: .86rem;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(135deg, #42a5f5 0%, #1e88e5 100%);
            border: none;
            border-radius: .75rem;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(30, 136, 229, .28);
            white-space: nowrap;
        }
        .hz-btn-primary:hover { filter: brightness(1.06); }
        .hz-btn-outline {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .55rem 1.1rem;
            font-size: .86rem;
            font-weight: 600;
            color: #374151;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: .75rem;
            cursor: pointer;
            white-space: nowrap;
        }
        .hz-btn-outline:hover { background: #f9fafb; border-color: #9ca3af; }

        /* ── Tarjetas de gráfica ─────────────────────────────────── */
        .hz-card-grafica {
            background: #fff;
            border: 1px solid #eef1f6;
            border-radius: 1.25rem;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            transition: box-shadow .2s ease;
        }
        .hz-card-grafica:hover { box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10); }
        .hz-card-grafica-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1rem 1.4rem .9rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .hz-card-grafica-titulo {
            display: flex;
            align-items: center;
            gap: .6rem;
            font-size: .92rem;
            font-weight: 700;
            color: #0f172a;
        }
        .hz-badge-tipo {
            font-size: .72rem;
            font-weight: 700;
            padding: .3rem .7rem;
            border-radius: .55rem;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            white-space: nowrap;
        }
        .hz-card-grafica-meta {
            display: flex;
            align-items: center;
            gap: .8rem;
            flex-wrap: wrap;
            font-size: .72rem;
            color: #94a3b8;
        }
        .hz-card-grafica-meta strong { color: #475569; font-weight: 600; }
        .hz-badge-unidad {
            font-size: .68rem;
            font-weight: 700;
            padding: .22rem .6rem;
            border-radius: .45rem;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        .hz-btn-reset-zoom {
            display: none;
            align-items: center;
            gap: .35rem;
            padding: .3rem .7rem;
            font-size: .7rem;
            font-weight: 600;
            color: #1e88e5;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: .55rem;
            cursor: pointer;
        }
        .hz-btn-reset-zoom.visible { display: inline-flex; }
        .hz-btn-reset-zoom:hover { background: #dbeafe; }

        .hz-canvas-wrap {
            min-width: 700px;
            height: 520px;
            position: relative;
        }
        .hz-canvas-scroll { overflow-x: auto; padding: 1rem 1.4rem 1.2rem; }
        .hz-scroll-x { overflow-x: auto; }
        .hz-grafico-sub { padding: .35rem 1.4rem 0; font-size: .75rem; color: #94a3b8; }

        /* Leyenda inferior centrada (fuera del área con scroll horizontal) */
        .hz-chart-leyenda {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: .45rem 1.3rem;
            padding: .15rem 1.4rem 1.05rem;
            border-top: 1px solid #f1f5f9;
            margin-top: .2rem;
        }
        .hz-ley-item {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            font-size: .74rem;
            font-weight: 600;
            color: #334155;
            white-space: nowrap;
        }
        .hz-ley-item .hz-ley-linea {
            width: 1.35rem;
            height: 0;
            border-top: 2.5px solid currentColor;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .hz-ley-item .hz-ley-linea.dashed { border-top-style: dashed; }

        .hz-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .6rem;
            padding: 3rem 1.5rem;
            color: #94a3b8;
            text-align: center;
        }
        .hz-empty i { font-size: 2.4rem; opacity: .55; }

        .hz-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1rem;
            color: #64748b;
            font-size: .85rem;
            gap: .7rem;
        }
        .hz-spinner {
            width: 30px; height: 30px;
            border-radius: 50%;
            border: 3px solid rgba(30, 136, 229, .15);
            border-top-color: #1e88e5;
            animation: hz-spin .8s linear infinite;
        }
        @keyframes hz-spin { to { transform: rotate(360deg); } }

        .hz-graficos-grid { display: grid; grid-template-columns: 1fr; gap: 1.6rem; }

        /* ── Scroll horizontal de las gráficas ──────────────────── */
        .hz-canvas-scroll { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        .hz-canvas-scroll::-webkit-scrollbar { height: 10px; }
        .hz-canvas-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .hz-canvas-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 999px; }
        .hz-ms-options::-webkit-scrollbar { width: 8px; }
        .hz-ms-options::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }

    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <!-- ══════════ FILTROS ══════════ -->
    <div class="hz-card mb-6">
        <button type="button" id="ghz-toggle-filtros" class="hz-card-head hz-abierto" aria-expanded="true">
            <span class="hz-card-head-titulo">
                <span class="text-base">🔎</span> Filtros de búsqueda
            </span>
            <svg class="hz-chevron w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div id="ghz-cuerpo-filtros" class="hz-card-body">
            <div class="hz-filtros-grid">
                <div class="hz-fila-principal">
                    <!-- 1. Ubicación -->
                    <div id="ghz-contenedor-ubicacion-unificado" class="flex flex-col relative hz-filtro-granjas">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5">Granjas, campañas y galpones</label>
                        <button id="ghz-unificado-btn" type="button" class="w-full sm:w-auto min-w-[260px] bg-white border border-slate-300 hover:border-slate-400 text-slate-800 text-sm font-medium rounded-lg px-3.5 py-2 shadow-sm flex items-center justify-between gap-3 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all">
                            <span id="ghz-unificado-label" class="truncate text-slate-400 font-normal">Cargando…</span>
                            <svg class="w-4 h-4 text-slate-500 flex-shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="ghz-unificado-dropdown" class="custom-multiselect-dropdown dropdown-enter absolute left-0 top-full z-50 mt-1.5 w-80 sm:w-96 bg-white rounded-xl shadow-xl border border-slate-200 p-3 hidden">
                            <div class="relative mb-2.5">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input type="text" id="ghz-unificado-search" placeholder="Buscar granja, campaña o galpón..." class="w-full pl-9 pr-3 py-1.5 text-xs sm:text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-slate-50 text-slate-800 placeholder-slate-400">
                            </div>
                            <div class="flex items-center justify-end px-1 pb-2 border-b border-slate-100 text-xs">
                                <button type="button" id="ghz-unificado-limpiar" class="text-slate-500 hover:text-rose-600 font-medium transition cursor-pointer flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 text-slate-400 hover:text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    <span>Limpiar</span>
                                </button>
                            </div>
                            <div id="ghz-unificado-options" class="max-h-72 overflow-y-auto space-y-1 pr-1 pt-2"></div>
                        </div>
                    </div>

                    <!-- 2–3. Periodo -->
                    <div class="hz-filtro hz-filtro-fecha">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5" for="ghz-fecha-inicio">Fecha inicio</label>
                        <input type="date" id="ghz-fecha-inicio" class="hz-input" value="<?php echo htmlspecialchars($filtrosDefecto['fechaInicio'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="hz-filtro hz-filtro-fecha">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5" for="ghz-fecha-fin">Fecha fin</label>
                        <input type="date" id="ghz-fecha-fin" class="hz-input" value="<?php echo htmlspecialchars($filtrosDefecto['fechaFin'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <!-- 4. Qué graficar -->
                    <div class="flex flex-col relative hz-filtro-tipos">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5">Tipo de gráficas</label>
                        <button id="ms-tipos-btn" type="button" class="w-full min-w-0 bg-white border border-slate-300 hover:border-slate-400 text-slate-800 text-sm font-medium rounded-lg px-2.5 py-2 shadow-sm flex items-center justify-between gap-2 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all">
                            <span id="ms-tipos-label" class="truncate">Cargando…</span>
                            <svg class="w-4 h-4 text-slate-500 flex-shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="ms-tipos-dropdown" class="custom-multiselect-dropdown dropdown-enter absolute left-0 top-full z-50 mt-1.5 w-56 bg-white rounded-xl shadow-xl border border-slate-200 p-2.5 hidden">
                            <div class="relative mb-2">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input type="text" id="ms-tipos-search" placeholder="Buscar tipo de gráfico..." class="w-full pl-9 pr-3 py-1.5 text-xs sm:text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-slate-50 text-slate-800 placeholder-slate-400">
                            </div>
                            <div id="ms-tipos-options" class="max-h-56 overflow-y-auto space-y-0.5 pr-1"></div>
                        </div>
                    </div>

                    <!-- 5–6. Detalle temporal (según tipo) -->
                    <div class="hz-filtro hz-filtro-semana" id="ghz-wrap-semana">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5" for="ghz-semana">Semana</label>
                        <input type="number" id="ghz-semana" class="hz-input" min="1" max="10" value="<?php echo (int) $filtrosDefecto['semana']; ?>">
                    </div>

                    <div id="ghz-wrap-dias" class="flex flex-col relative hz-filtro-dias" style="display:none;">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5">Días de pesaje / ganancia</label>
                        <button id="ms-dias-btn" type="button" class="w-full min-w-0 bg-white border border-slate-300 hover:border-slate-400 text-slate-800 text-sm font-medium rounded-lg px-3.5 py-2 shadow-sm flex items-center justify-between gap-3 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all">
                            <span id="ms-dias-label" class="truncate text-slate-400 font-normal">Elegir días</span>
                            <svg class="w-4 h-4 text-slate-500 flex-shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div id="ms-dias-dropdown" class="custom-multiselect-dropdown dropdown-enter absolute left-0 top-full z-50 mt-1.5 w-64 bg-white rounded-xl shadow-xl border border-slate-200 p-2.5 hidden">
                            <div class="relative mb-2">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input type="text" id="ms-dias-search" placeholder="Buscar día..." class="w-full pl-9 pr-3 py-1.5 text-xs sm:text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-slate-50 text-slate-800 placeholder-slate-400">
                            </div>
                            <div id="ms-dias-options" class="max-h-56 overflow-y-auto space-y-0.5 pr-1"></div>
                        </div>
                    </div>
                </div>

                <div class="hz-fila-secundaria">
                    <div class="hz-filtro hz-filtro-sexo">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5" for="ghz-sexo">Sexo</label>
                        <div class="hz-sexo-row">
                            <select id="ghz-sexo" class="hz-select">
                                <option value="macho" selected>Macho</option>
                                <option value="hembra">Hembra</option>
                                <option value="ambos">Ambos</option>
                                <option value="combinado">Combinado</option>
                            </select>
                            <label class="hz-chip-sep hz-dis" id="ghz-chip-separar" for="ghz-separar" title="Dibujar una tarjeta por sexo">
                                <input type="checkbox" id="ghz-separar">
                                <span>Separar</span>
                            </label>
                        </div>
                    </div>

                    <div class="hz-filtro hz-filtro-modo">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5" for="ghz-modo-combinado">Modo combinación</label>
                        <select id="ghz-modo-combinado" class="hz-select">
                            <option value="ninguno" selected>Sin combinar (Individual)</option>
                            <option value="tipo">Combinar tipos de gráfico</option>
                        </select>
                    </div>

                    <div class="hz-filtro hz-filtro-tamano">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1 ml-0.5">Tamaño texto</label>
                        <div class="hz-tamano">
                            <button type="button" id="ghz-tamano-dec" title="Disminuir tamaño de letra">A−</button>
                            <span class="hz-tamano-label" id="ghz-tamano-label">100%</span>
                            <button type="button" id="ghz-tamano-inc" title="Aumentar tamaño de letra">A+</button>
                        </div>
                    </div>

                    <div class="hz-acciones">
                        <button type="button" id="ghz-btn-cargar" class="hz-btn-primary">
                            <i class="fas fa-search"></i> Graficar
                        </button>
                        <button type="button" id="ghz-btn-limpiar" class="hz-btn-outline">
                            <i class="fas fa-eraser"></i> Limpiar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════ GRÁFICAS ══════════ -->
    <div id="ghz-graficos" class="hz-graficos-grid">
        <div class="hz-card-grafica" id="ghz-vacio">
            <div class="hz-empty">
                <i class="fas fa-chart-line"></i>
                <span>Selecciona los filtros y presiona <b>Graficar</b> para ver el acumulado semanal de todas las granjas.</span>
            </div>
        </div>
    </div>
</div>

<script>
window.GRAF_HZ_CFG = {
    datosUrl: <?php echo json_encode($datosApiUrl, JSON_UNESCAPED_UNICODE); ?>,
    tiposUrl: <?php echo json_encode($tiposApiUrl, JSON_UNESCAPED_UNICODE); ?>,
    diasUrl: <?php echo json_encode($diasApiUrl, JSON_UNESCAPED_UNICODE); ?>,
    granjasUrl: <?php echo json_encode($granjasApiUrl, JSON_UNESCAPED_UNICODE); ?>,
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
    tiposDefecto: ['mortalidad']
};
</script>
<script src="../../../assets/js/sip-grafica-shared.js?v=<?php echo htmlspecialchars($vJsShared, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="js/graficas-horizontales-multiselect.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/graficas-horizontales-multiselect.js'); ?>"></script>
<script src="js/graficas-horizontales-render.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/graficas-horizontales-render.js'); ?>"></script>
</body>
</html>
