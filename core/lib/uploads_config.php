<?php
if (!defined('SANIDAD_UPLOADS_FS_ROOT')) {
    if (!defined('PA_PROJECT_ROOT')) {
        define('PA_PROJECT_ROOT', dirname(dirname(__DIR__)));
    }
    $local = PA_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'uploads_config.local.php';
    if (is_file($local)) {
        require $local;
    }
}

if (!defined('SANIDAD_UPLOADS_FS_ROOT')) {
    $fromEnv = getenv('SANIDAD_UPLOADS_FS_ROOT');
    if ($fromEnv !== false && $fromEnv !== '') {
        define('SANIDAD_UPLOADS_FS_ROOT', $fromEnv);
    }
}

if (!defined('SANIDAD_UPLOADS_FS_ROOT')) {
    define('SANIDAD_UPLOADS_FS_ROOT', '/var/www/html/storage/sanidad/uploads');
}

if (!function_exists('sanidad_uploads_fs_root')) {
    function sanidad_uploads_fs_root(): string
    {
        return rtrim(SANIDAD_UPLOADS_FS_ROOT, '/\\');
    }

    function sanidad_uploads_fs_dir(string $sub): string
    {
        static $allowed = ['evidencias' => true, 'necropsias' => true, 'resultados' => true, 'mortalidad' => true, 'seguimiento_crianza' => true];
        if (!isset($allowed[$sub])) {
            throw new InvalidArgumentException('Subcarpeta uploads no permitida: ' . $sub);
        }
        return sanidad_uploads_fs_root() . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR;
    }

    function sanidad_uploads_rel(string $sub, string $fileBasename): string
    {
        static $allowed = ['evidencias' => true, 'necropsias' => true, 'resultados' => true, 'mortalidad' => true, 'seguimiento_crianza' => true];
        if (!isset($allowed[$sub])) {
            throw new InvalidArgumentException('Subcarpeta uploads no permitida: ' . $sub);
        }
        $base = basename(str_replace('\\', '/', $fileBasename));
        return 'uploads/' . $sub . '/' . $base;
    }

    function sanidad_uploads_fs_from_rel(string $rutaRelativa): string
    {
        $rutaRelativa = str_replace('\\', '/', trim($rutaRelativa));
        if ($rutaRelativa === '' || strpos($rutaRelativa, '..') !== false) {
            return '';
        }
        if (preg_match('#^uploads/(.+)$#', $rutaRelativa, $m)) {
            $rest = $m[1];
        } else {
            return '';
        }
        $rest = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rest);
        return sanidad_uploads_fs_root() . DIRECTORY_SEPARATOR . $rest;
    }
}
