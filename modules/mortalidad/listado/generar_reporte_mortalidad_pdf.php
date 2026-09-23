<?php

declare(strict_types=1);

session_start();
if (empty($_SESSION['active'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('No autorizado');
}

require_once __DIR__ . '/mortalidad_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    exit('Error de conexión');
}

$codigoUsuario = trim((string) ($_SESSION['usuario'] ?? ''));
$filtros = mort_listado_parse_filtros($_GET);

$tiposPerm = mort_listado_tipos_reporte_keys();

$resumen = mort_listado_fetch_resumen($conn, $tiposPerm, $filtros);
$lista = mort_listado_fetch_registros($conn, $tiposPerm, $filtros, null, 0);
mysqli_close($conn);

date_default_timezone_set('America/Lima');
$fechaReporte = date('d/m/Y H:i');
$filtrosTxt = mort_listado_texto_filtros_aplicados($filtros);
$usuarioReporte = htmlspecialchars($codigoUsuario);

$css = 'body{font-family:"Segoe UI",Arial,sans-serif;font-size:10pt;color:#1e293b;margin:0;padding:15px;}
.header-info{font-size:9pt;color:#475569;text-align:right;margin-bottom:8px;}
.titulo{font-size:14pt;margin:0 0 6px;color:#1e3a5f;}
.subtitulo{font-size:9pt;color:#64748b;margin:0 0 12px;}
.resumen-box{display:flex;gap:12px;margin:12px 0 16px;flex-wrap:wrap;}
.resumen-item{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 14px;min-width:110px;}
.resumen-item strong{display:block;font-size:14pt;color:#2563eb;}
.resumen-item span{font-size:8pt;color:#6b7280;}
table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-top:8px;}
th,td{padding:5px 6px;border:1px solid #cbd5e1;text-align:left;}
th{background-color:#2563eb;color:#fff;font-weight:bold;}
td.num{text-align:right;}
tbody tr:nth-child(even){background-color:#f8fafc;}
.footer{font-size:8pt;color:#94a3b8;margin-top:14px;text-align:center;}';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
$html .= '<div class="header-info">Generado: ' . htmlspecialchars($fechaReporte) . ' · Usuario: ' . $usuarioReporte . '</div>';
$html .= '<h1 class="titulo">Reporte — Mortalidad</h1>';
$html .= '<p class="subtitulo">Filtros: ' . htmlspecialchars($filtrosTxt) . '</p>';

$html .= '<div class="resumen-box">';
$html .= '<div class="resumen-item"><strong>' . number_format($resumen['registros']) . '</strong><span>Registros</span></div>';
$html .= '<div class="resumen-item"><strong>' . number_format($resumen['totalAves']) . '</strong><span>Total aves</span></div>';
$html .= '<div class="resumen-item"><strong>' . number_format($resumen['machos']) . '</strong><span>Machos</span></div>';
$html .= '<div class="resumen-item"><strong>' . number_format($resumen['hembras']) . '</strong><span>Hembras</span></div>';
$html .= '</div>';

$html .= '<table><thead><tr>';
$html .= '<th>#</th><th>Documento</th><th>Fecha ref.</th><th>Tipo</th><th>Subtipo</th><th>Granja / campaña</th><th>Galpón</th>';
$html .= '<th class="num">Machos</th><th class="num">Hembras</th><th class="num">Total</th><th>Usuario</th>';
$html .= '</tr></thead><tbody>';

if ($lista === []) {
    $html .= '<tr><td colspan="11" style="text-align:center;color:#64748b;">Sin resultados para los filtros seleccionados.</td></tr>';
} else {
    $n = 1;
    foreach ($lista as $row) {
        $granjaTxt = trim((string) ($row['granjaNombre'] ?? ''));
        $cencoRow = (string) ($row['cenco'] ?? '');
        if ($granjaTxt !== '') {
            $granjaTxt .= ' (' . $cencoRow . ')';
        } else {
            $granjaTxt = $cencoRow;
        }
        $subtipoLabel = mort_listado_label_subtipo(
            (string) ($row['tipoMortalidad'] ?? ''),
            $row['subtipoTransporte'] ?? null,
            $row['subtipoProduccion'] ?? null
        );
        $html .= '<tr>';
        $html .= '<td>' . $n++ . '</td>';
        $html .= '<td>' . htmlspecialchars((string) ($row['documento'] ?? '')) . '</td>';
        $html .= '<td>' . htmlspecialchars(mort_listado_formato_fecha_pdf((string) ($row['fechaRegistro'] ?? ''))) . '</td>';
        $html .= '<td>' . htmlspecialchars((string) ($row['tipoLabel'] ?? '')) . '</td>';
        $html .= '<td>' . htmlspecialchars($subtipoLabel) . '</td>';
        $html .= '<td>' . htmlspecialchars($granjaTxt) . '</td>';
        $html .= '<td>' . htmlspecialchars((string) ($row['galpon'] ?? '')) . '</td>';
        $html .= '<td class="num">' . number_format((int) ($row['machos'] ?? 0)) . '</td>';
        $html .= '<td class="num">' . number_format((int) ($row['hembras'] ?? 0)) . '</td>';
        $html .= '<td class="num"><strong>' . number_format((int) ($row['totalAves'] ?? 0)) . '</strong></td>';
        $html .= '<td>' . htmlspecialchars(mort_listado_format_usuario_display(
            (string) ($row['nombreUsuario'] ?? ''),
            (string) ($row['usuarioRegistro'] ?? '')
        )) . '</td>';
        $html .= '</tr>';
    }
}

$html .= '</tbody></table>';
$html .= '<p class="footer">Granja Rinconada Del Sur S.A. — Sistema Integrado de Producción</p>';
$html .= '</body></html>';

$tempDir = __DIR__ . '/../../../pdf_tmp';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0775, true);
}
if (!is_dir($tempDir) || !is_writable($tempDir)) {
    $tempDir = sys_get_temp_dir();
}

try {
    if (ob_get_level()) {
        ob_clean();
    }
    require_once __DIR__ . '/../../../vendor/autoload.php';
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 12,
        'margin_bottom' => 12,
        'tempDir' => $tempDir,
    ]);
    $mpdf->WriteHTML($html);
    $mpdf->Output('reporte_mortalidad_' . date('Ymd_His') . '.pdf', 'I');
    exit;
} catch (Exception $e) {
    if (ob_get_level()) {
        @ob_end_clean();
    }
    exit('Error generando PDF: ' . $e->getMessage());
}
