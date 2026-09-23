<?php

/**
 * Informe contable PDF.
 * Diagnóstico: &debug=1 (SQL)  |  &debug=mpdf (carga mPDF + PDF mínimo)
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('max_execution_time', '300');
@set_time_limit(300);
@ini_set('memory_limit', '1024M');

function inf_ctb_pdf_fail(string $msg): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Inf-Ctb-Pdf: error');
    }
    echo $msg;
    exit;
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $tipo = (int) ($err['type'] ?? 0);
    if (!in_array($tipo, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Inf-Ctb-Pdf: fatal');
    }
    echo 'Error fatal PDF: ' . ($err['message'] ?? 'desconocido')
        . "\nArchivo: " . ($err['file'] ?? '')
        . "\nLinea: " . ($err['line'] ?? '')
        . "\nMemoria pico: " . round(memory_get_peak_usage(true) / 1048576, 1) . " MB";
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['active'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: text/plain; charset=utf-8');
    exit('No autorizado');
}

$debug = isset($_GET['debug']) ? trim((string) $_GET['debug']) : '';
$isDebug = ($debug !== '' && $debug !== '0');
if ($isDebug) {
    header('Content-Type: text/plain; charset=utf-8');
}

$dbg = static function (string $msg) use ($isDebug): void {
    if (!$isDebug) {
        return;
    }
    echo '[' . date('H:i:s') . "] {$msg}\n";
    @ob_flush();
    @flush();
};

/**
 * @return string
 */
function inf_ctb_pdf_find_vendor(): string
{
    foreach ([
        __DIR__ . '/../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
    ] as $vp) {
        if (is_file($vp)) {
            return $vp;
        }
    }
    return '';
}

/**
 * @return string
 */
function inf_ctb_pdf_temp_dir(): string
{
    $tempDir = __DIR__ . '/../../../pdf_tmp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }
    if (!is_dir($tempDir) || !is_writable($tempDir)) {
        $tempDir = sys_get_temp_dir();
    }
    return $tempDir;
}

try {
    $dbg('inicio PHP ' . PHP_VERSION . ' mem=' . round(memory_get_usage(true) / 1048576, 1) . 'MB');

    // --- debug=mpdf: solo probar mPDF sin consulta grande ---
    if ($debug === 'mpdf') {
        $dbg('ext mbstring: ' . (extension_loaded('mbstring') ? 'SI' : 'NO'));
        $dbg('ext gd: ' . (extension_loaded('gd') ? 'SI' : 'NO'));
        $vendor = inf_ctb_pdf_find_vendor();
        if ($vendor === '') {
            inf_ctb_pdf_fail('Falta vendor/autoload.php');
        }
        require_once $vendor;
        $dbg('autoload ok: ' . $vendor);
        if (!class_exists('\\Mpdf\\Mpdf')) {
            inf_ctb_pdf_fail('Clase Mpdf\\Mpdf no encontrada');
        }
        $dbg('clase Mpdf ok');
        $tempDir = inf_ctb_pdf_temp_dir();
        $dbg('tempDir: ' . $tempDir . ' writable=' . (is_writable($tempDir) ? 'SI' : 'NO'));
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'simpleTables' => true,
        ]);
        $mpdf->WriteHTML('<h1>PDF test OK</h1><p>mPDF funciona en este servidor.</p>');
        $dbg('WriteHTML ok, generando Output...');
        // En debug no enviamos PDF binario; solo confirmamos.
        $bin = $mpdf->Output('', 'S');
        $dbg('Output S ok, bytes=' . strlen($bin) . ' mem_pico=' . round(memory_get_peak_usage(true) / 1048576, 1) . 'MB');
        echo "DIAGNOSTICO mPDF OK\n";
        exit;
    }

    require_once __DIR__ . '/informe_contable_lib.php';
    $dbg('lib ok');

    $conexionFile = null;
    foreach ([
        __DIR__ . '/../../../../conexion_grs/conexion.php',
        __DIR__ . '/../../../conexion_grs/conexion.php',
    ] as $cp) {
        if (is_file($cp)) {
            $conexionFile = $cp;
            break;
        }
    }
    if ($conexionFile === null) {
        inf_ctb_pdf_fail('Falta conexion_grs/conexion.php');
    }
    include_once $conexionFile;
    $dbg('conexion ok');

    $conn = conectar_joya_mysqli();
    if (!$conn) {
        inf_ctb_pdf_fail('No se pudo conectar a la BD');
    }

    $filtros = inf_ctb_parse_filtros($_GET);
    $where = inf_ctb_where_filtros($conn, $filtros);
    $dbg('where ok');

    // Límite: mPDF en PHP 7.2; con escritura por chunks aguanta más que el HTML monolítico.
    // Opcional: &maxFilas=3000 (máx. 8000). Por defecto 5000.
    $maxFilasPdf = 5000;
    if (isset($_GET['maxFilas'])) {
        $maxFilasPdf = (int) $_GET['maxFilas'];
        if ($maxFilasPdf < 100) {
            $maxFilasPdf = 100;
        }
        if ($maxFilasPdf > 8000) {
            $maxFilasPdf = 8000;
        }
    }
    $sql = "
        SELECT
            u.nombre AS usuario,
            b.tdate AS fecha,
            b.ttime AS hora,
            a.tfectra AS fechaCont,
            a.tcencos AS cencos,
            c.nombre AS nomCencos,
            TRIM(a.tcodint) AS galpon,
            a.tedad AS edad,
            a.tcodigo AS codigo,
            i.descri AS nomProd,
            a.tcodtra AS codTra,
            l.descri AS transaccion,
            IF(a.ttipo='015','Macho',IF(a.ttipo='016','Hembra','-')) AS sexo,
            CAST(a.flujo AS CHAR) AS flujo,
            CAST(a.tcategoria AS CHAR) AS tcategoria,
            a.tnumfac AS numFac,
            a.idmovi,
            IF(LEFT(a.tcodtra,1)='E',a.tcantid,-1*a.tcantid) AS cantidad,
            IF(a.tcodtra='S808',a.tcod_mortgrs,'') AS codMort,
            IF(a.tcodtra='S808',m.tnom_mort,'') AS nomMort,
            IF(a.tcodtra='S808',a.tcantid,'') AS cantidadMort
        FROM movi_zonas AS a
        LEFT JOIN cabe_zonas AS b ON a.treg = b.treg AND a.mark = b.mark
        LEFT JOIN ccos AS c ON a.tcencos = c.codigo
        LEFT JOIN coal AS l ON a.tcodtra = l.codtra
        LEFT JOIN regmotivo_mortalidadgrs AS m ON a.tcod_mortgrs = m.tcod_mort
        LEFT JOIN usuario AS u ON b.tuser = u.codigo
        LEFT JOIN mitm AS i ON a.tcodigo = i.codigo
        {$where}
        LIMIT " . (int) ($maxFilasPdf + 1) . "
    ";

    $dbg('SQL start');
    $t0 = microtime(true);
    $qData = mysqli_query($conn, $sql);
    if (!$qData) {
        $err = mysqli_error($conn);
        mysqli_close($conn);
        inf_ctb_pdf_fail('Error SQL: ' . $err);
    }
    $dbg(sprintf('SQL ok %.2fs', microtime(true) - $t0));

    $rows = [];
    while ($row = mysqli_fetch_assoc($qData)) {
        $catVal = trim((string) ($row['tcategoria'] ?? ''));
        $fluVal = trim((string) ($row['flujo'] ?? ''));
        if ($catVal === 'P' || $catVal === 'Produccion') {
            $row['tcategoria'] = 'Produccion';
            if ($fluVal === 'P' || $fluVal === '' || $fluVal === 'Crianza') {
                $row['flujo'] = 'Crianza';
            }
        } elseif ($catVal === 'PlantaIncubacion') {
            $row['tcategoria'] = 'Planta Incubacion';
        }
        if ($fluVal === 'PlantaIncubacion') {
            $row['flujo'] = 'Planta Incubacion';
        }
        $rows[] = $row;
    }
    mysqli_close($conn);

    $hayMas = count($rows) > $maxFilasPdf;
    if ($hayMas) {
        $rows = array_slice($rows, 0, $maxFilasPdf);
    }
    $dbg('filas=' . count($rows) . ' mem=' . round(memory_get_usage(true) / 1048576, 1) . 'MB');

    if ($debug === '1') {
        echo "DIAGNOSTICO SQL OK\n";
        echo 'Filas: ' . count($rows) . ($hayMas ? " (limitado a {$maxFilasPdf})\n" : "\n");
        echo "Ahora prueba: misma URL pero debug=mpdf\n";
        echo "Luego exporta sin debug.\n";
        exit;
    }

    $vendor = inf_ctb_pdf_find_vendor();
    if ($vendor === '') {
        inf_ctb_pdf_fail('Falta vendor/autoload.php');
    }
    require_once $vendor;
    if (!class_exists('\\Mpdf\\Mpdf')) {
        inf_ctb_pdf_fail('Clase Mpdf\\Mpdf no encontrada');
    }
    if (!extension_loaded('mbstring')) {
        inf_ctb_pdf_fail('Falta extensión PHP mbstring (requerida por mPDF)');
    }

    $tempDir = inf_ctb_pdf_temp_dir();
    $dbg('mPDF init temp=' . $tempDir);

    $escFlags = ENT_QUOTES;
    if (defined('ENT_SUBSTITUTE')) {
        $escFlags |= ENT_SUBSTITUTE;
    }
    $esc = static function ($v) use ($escFlags): string {
        return htmlspecialchars((string) ($v ?? ''), $escFlags, 'UTF-8');
    };

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    date_default_timezone_set('America/Lima');
    $fechaReporte = date('d/m/Y H:i');

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'margin_left' => 6,
        'margin_right' => 6,
        'margin_top' => 8,
        'margin_bottom' => 12,
        'tempDir' => $tempDir,
        'simpleTables' => true,
        'packTableData' => true,
        'useSubstitutions' => false,
    ]);
    $mpdf->shrink_tables_to_fit = 0;
    $mpdf->SetFooter('{PAGENO}/{nbpg}');

    $css = 'body{font-family:Arial,sans-serif;font-size:7pt;color:#111;}
    .titulo{font-size:11pt;font-weight:bold;margin:0 0 4px 0;}
    .meta{font-size:7pt;color:#475569;margin:0 0 6px 0;}
    .aviso{color:#b45309;margin:0 0 6px 0;}
    table.data{width:100%;border-collapse:collapse;}
    table.data th,table.data td{border:1px solid #94a3b8;padding:1px 2px;vertical-align:top;}
    table.data th{background:#2563eb;color:#fff;font-weight:bold;}
    .num{text-align:right;}';

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    $head = '<div class="titulo">INFORME CONTABLE - MORTALIDAD</div>';
    $head .= '<div class="meta">Generado: ' . $esc($fechaReporte) . ' · Filas: ' . count($rows) . '</div>';
    if ($hayMas) {
        $head .= '<div class="aviso">PDF limitado a ' . $maxFilasPdf . ' filas (hay más). Para el mes completo use Excel, o filtre por granja/galpón.</div>';
    }
    $mpdf->WriteHTML($head, \Mpdf\HTMLParserMode::HTML_BODY);

    // Abrir tabla
    $thead = '<table class="data"><thead><tr>'
        . '<th>N°</th><th>Usuario</th><th>Fecha</th><th>Hora</th><th>Fec.cont</th><th>Cencos</th><th>Nom.cencos</th><th>Galpón</th><th>Edad</th><th>Código</th><th>Nom.prod</th><th>Cod.tra</th><th>Transacción</th><th>Sexo</th><th>Cat.</th><th>Flujo</th><th>Fac</th><th>Id</th><th class="num">Cant</th><th>Cod.mort</th><th>Causa</th><th class="num">C.mort</th>'
        . '</tr></thead><tbody>';
    $mpdf->WriteHTML($thead, \Mpdf\HTMLParserMode::HTML_BODY);

    if ($rows === []) {
        $mpdf->WriteHTML('<tr><td colspan="22" style="text-align:center;">Sin registros.</td></tr>', \Mpdf\HTMLParserMode::HTML_BODY);
    } else {
        $buf = '';
        $n = 0;
        $chunk = 60; // chunks chicos = menos memoria
        $totalRows = count($rows);
        for ($i = 0; $i < $totalRows; $i++) {
            $r = $rows[$i];
            $rows[$i] = null; // liberar memoria ya procesada
            $fc = trim((string) ($r['fechaCont'] ?? ''));
            if ($fc !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $fc)) {
                $parts = explode(' ', $fc);
                $fc = implode('/', array_reverse(explode('-', $parts[0])));
            }
            $cantMort = (float) ($r['cantidadMort'] ?? 0);
            $buf .= '<tr>'
                . '<td>' . (++$n) . '</td>'
                . '<td>' . $esc($r['usuario'] ?? '') . '</td>'
                . '<td>' . $esc($r['fecha'] ?? '') . '</td>'
                . '<td>' . $esc($r['hora'] ?? '') . '</td>'
                . '<td>' . $esc($fc) . '</td>'
                . '<td>' . $esc($r['cencos'] ?? '') . '</td>'
                . '<td>' . $esc($r['nomCencos'] ?? '') . '</td>'
                . '<td>' . $esc($r['galpon'] ?? '') . '</td>'
                . '<td>' . $esc($r['edad'] ?? '') . '</td>'
                . '<td>' . $esc($r['codigo'] ?? '') . '</td>'
                . '<td>' . $esc($r['nomProd'] ?? '') . '</td>'
                . '<td>' . $esc($r['codTra'] ?? '') . '</td>'
                . '<td>' . $esc($r['transaccion'] ?? '') . '</td>'
                . '<td>' . $esc($r['sexo'] ?? '') . '</td>'
                . '<td>' . $esc($r['tcategoria'] ?? '') . '</td>'
                . '<td>' . $esc($r['flujo'] ?? '') . '</td>'
                . '<td>' . $esc($r['numFac'] ?? '') . '</td>'
                . '<td>' . $esc($r['idmovi'] ?? '') . '</td>'
                . '<td class="num">' . number_format((float) ($r['cantidad'] ?? 0), 2, ',', '.') . '</td>'
                . '<td>' . $esc($r['codMort'] ?? '') . '</td>'
                . '<td>' . $esc($r['nomMort'] ?? '') . '</td>'
                . '<td class="num">' . ($cantMort > 0 ? number_format($cantMort, 2, ',', '.') : '') . '</td>'
                . '</tr>';
            unset($r);

            if ($n % $chunk === 0) {
                $mpdf->WriteHTML($buf, \Mpdf\HTMLParserMode::HTML_BODY);
                $buf = '';
                if ($isDebug && $n % 400 === 0) {
                    $dbg("escrito {$n} filas mem=" . round(memory_get_usage(true) / 1048576, 1) . 'MB');
                }
            }
        }
        $rows = [];
        if ($buf !== '') {
            $mpdf->WriteHTML($buf, \Mpdf\HTMLParserMode::HTML_BODY);
        }
    }

    $mpdf->WriteHTML('</tbody></table>', \Mpdf\HTMLParserMode::HTML_BODY);
    $dbg('Output PDF...');
    $mpdf->Output('informe_contable_mortalidad_' . date('Ymd_His') . '.pdf', 'I');
    exit;
} catch (Throwable $e) {
    inf_ctb_pdf_fail(
        'Error generando PDF: ' . $e->getMessage()
        . "\n" . $e->getFile() . ':' . $e->getLine()
        . "\nMemoria pico: " . round(memory_get_peak_usage(true) / 1048576, 1) . ' MB'
    );
}
