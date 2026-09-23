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

$baseModUrl = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$modMortUrl = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
$apiUrl = $baseModUrl . '/get_analisis_despacho.php';
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
        .mdp-panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.25rem;
        }
        .mdp-panel h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.75rem;
        }
        .mdp-tabla {
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .mdp-tabla th, .mdp-tabla td {
            border: 1px solid #e5e7eb;
            padding: 0.45rem 0.75rem;
        }
        .mdp-tabla th {
            background: #f1f5f9;
            font-weight: 600;
            text-align: left;
        }
        .mdp-fila-total td {
            font-weight: 700;
            background: #fffbeb;
        }
        .selector-display input { cursor: pointer; background: #fff; }
    </style>
</head>
<body class="bg-gray-50">
<div class="w-full max-w-full py-4 px-4 sm:px-6 lg:px-8 box-border">

    <div class="card-filtros-compacta mb-6 bg-white border rounded-2xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b">
            <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                <i class="fas fa-truck-loading text-sky-600"></i>
                Análisis de mortalidad en despacho
            </h2>
            <p class="text-sm text-gray-500 mt-1">Consolidado por causas, etapas del proceso y resumen por granja (saca vs. muertos).</p>
        </div>
        <div class="px-6 pb-6 pt-4">
            <div class="flex flex-wrap items-end gap-4 mb-4">
                <div class="min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Periodo</label>
                    <select id="mdp-periodo-tipo" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                        <option value="POR_FECHA" selected>Por fecha</option>
                        <option value="ENTRE_FECHAS">Entre fechas</option>
                        <option value="POR_MES">Por mes</option>
                        <option value="ENTRE_MESES">Entre meses</option>
                        <option value="ULTIMA_SEMANA">Última semana</option>
                    </select>
                </div>
                <div id="mdp-bloque-fecha-unica" class="mdp-bloque-periodo min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha</label>
                    <input id="mdp-fecha-unica" type="date" value="<?php echo htmlspecialchars($hoy, ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <div id="mdp-bloque-rango-fechas" class="mdp-bloque-periodo hidden flex gap-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                        <input id="mdp-fecha-inicio" type="date" value="<?php echo htmlspecialchars($anio, ENT_QUOTES, 'UTF-8'); ?>-01-01" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                        <input id="mdp-fecha-fin" type="date" value="<?php echo htmlspecialchars($hoy, ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                </div>
                <div id="mdp-bloque-mes-unico" class="mdp-bloque-periodo hidden min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mes</label>
                    <input id="mdp-mes-unico" type="month" value="<?php echo htmlspecialchars($mesActual, ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                </div>
                <div id="mdp-bloque-rango-meses" class="mdp-bloque-periodo hidden flex gap-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes inicio</label>
                        <input id="mdp-mes-inicio" type="month" value="<?php echo htmlspecialchars($anio, ENT_QUOTES, 'UTF-8'); ?>-01" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Mes fin</label>
                        <input id="mdp-mes-fin" type="month" value="<?php echo htmlspecialchars($mesActual, ENT_QUOTES, 'UTF-8'); ?>" class="w-full px-2 py-2 border border-gray-300 rounded-lg text-sm">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Granja (opcional)</label>
                    <div class="selector-display flex gap-2">
                        <input id="mdp-granja-resumen" type="text" class="flex-1 px-3 py-2 text-sm rounded-lg border border-gray-300" readonly placeholder="Todas las granjas">
                        <button type="button" id="mdp-btn-limpiar-granja" class="px-3 py-2 text-sm border rounded-lg bg-gray-100 hover:bg-gray-200" title="Quitar filtro de granja">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <input id="mdp-h-granja" type="hidden" value="">
                    <input id="mdp-h-campania" type="hidden" value="">
                </div>
            </div>

            <button type="button" id="mdp-btn-consultar" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700 font-medium inline-flex items-center gap-2">
                <i class="fas fa-search"></i> Consultar
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
        <div class="mdp-panel">
            <h4 id="mdp-titulo-causas">Mortalidad por causa</h4>
            <div id="mdp-tabla-causas" class="overflow-x-auto"></div>
        </div>
        <div class="mdp-panel">
            <h4 id="mdp-titulo-etapas">Mortalidad por etapa del proceso</h4>
            <div id="mdp-tabla-etapas" class="overflow-x-auto"></div>
        </div>
    </div>

    <div class="mdp-panel">
        <h4>Resumen de mortalidad por granja</h4>
        <div id="mdp-tabla-resumen" class="overflow-x-auto"></div>
    </div>
</div>

<?php
$gmc_id_prefix = 'mdp';
$gmc_shell_panel_id = 'mdp-modal-granjas';
$gmc_show_chk_todas = false;
require __DIR__ . '/../../../core/lib/modals/modal_granjas_campanias.php';
?>

<script>
window.MORT_DESPACHO_CFG = {
    apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>,
    filtrosApiUrl: <?= json_encode($filtrosApiUrl, JSON_UNESCAPED_UNICODE) ?>,
    granjasMeta: <?php
        $gm = [];
        foreach (array_values($granjasZonas) as $gz) {
            $gm[] = [
                'granja' => $gz['granja'] ?? '',
                'nombre' => $gz['nombre_granja'] ?? $gz['nombre'] ?? '',
                'zona' => $gz['zona'] ?? '',
            ];
        }
        echo json_encode($gm, JSON_UNESCAPED_UNICODE);
    ?>
};
</script>
<script src="../js/gmc-granjas-campanias.js?v=<?php echo (int) @filemtime(__DIR__ . '/../js/gmc-granjas-campanias.js'); ?>"></script>
<script src="js/mortalidad-despacho.js?v=<?php echo (int) @filemtime(__DIR__ . '/js/mortalidad-despacho.js'); ?>"></script>
</body>
</html>
