<?php
session_start();
require_once __DIR__ . '/lib/uploads_config.php';
if (empty($_SESSION['active'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}
$ruta = isset($_GET['ruta']) ? trim($_GET['ruta']) : '';
if ($ruta === '') {
    header('HTTP/1.1 400 Bad Request');
    exit;
}
$ruta = rawurldecode($ruta);
$rutaNorm = str_replace('\\', '/', $ruta);
if (preg_match('#^uploads/#', $rutaNorm) !== 1) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}
if (preg_match('#^uploads/(evidencias|necropsias|resultados|mortalidad|seguimiento_crianza)/#', $rutaNorm) !== 1) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}
foreach (preg_split('#/#', $rutaNorm, -1, PREG_SPLIT_NO_EMPTY) as $seg) {
    if ($seg === '..') {
        header('HTTP/1.1 400 Bad Request');
        exit;
    }
}
$pathFisico = sanidad_uploads_fs_from_rel($rutaNorm);
if ($pathFisico === '' || !is_file($pathFisico)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}
$realPath = realpath($pathFisico);
$realBase = realpath(sanidad_uploads_fs_root());
if ($realPath === false || $realBase === false || strpos($realPath, $realBase) !== 0) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}
$pathFisico = $realPath;
$nombreArchivo = basename($pathFisico);
$mime = @mime_content_type($pathFisico);
if ($mime === false || $mime === '') {
    $mime = 'application/octet-stream';
}
$nombreSeguro = preg_replace('/[^\x20-\x7E]/', '_', $nombreArchivo);
if ($nombreSeguro === '') {
    $nombreSeguro = 'archivo';
}
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . str_replace('"', '\\"', $nombreSeguro) . '"');
header('Content-Length: ' . filesize($pathFisico));
header('Cache-Control: private, max-age=3600');
readfile($pathFisico);
exit;
