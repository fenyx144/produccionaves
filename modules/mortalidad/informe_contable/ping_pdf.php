<?php
/**
 * Ping mínimo para verificar que la carpeta informe_contable responde.
 */
header('Content-Type: text/plain; charset=utf-8');
echo "OK informe_contable\n";
echo 'PHP ' . PHP_VERSION . "\n";
echo 'archivo pdf existe: ' . (is_file(__DIR__ . '/generar_reporte_informe_contable_pdf.php') ? 'SI' : 'NO') . "\n";
echo 'lib existe: ' . (is_file(__DIR__ . '/informe_contable_lib.php') ? 'SI' : 'NO') . "\n";
$vendor = __DIR__ . '/../../../vendor/autoload.php';
echo 'vendor: ' . (is_file($vendor) ? 'SI' : 'NO') . "\n";
$conexion = __DIR__ . '/../../../../conexion_grs/conexion.php';
echo 'conexion_grs: ' . (is_file($conexion) ? 'SI' : 'NO') . "\n";
echo 'mbstring: ' . (extension_loaded('mbstring') ? 'SI' : 'NO') . "\n";
echo 'gd: ' . (extension_loaded('gd') ? 'SI' : 'NO') . "\n";
echo 'memory_limit: ' . ini_get('memory_limit') . "\n";
echo 'max_execution_time: ' . ini_get('max_execution_time') . "\n";
if (is_file($vendor)) {
    require_once $vendor;
    echo 'Mpdf class: ' . (class_exists('\\Mpdf\\Mpdf') ? 'SI' : 'NO') . "\n";
}
echo 'hora: ' . date('c') . "\n";
