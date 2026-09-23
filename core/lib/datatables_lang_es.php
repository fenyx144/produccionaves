<?php

if (!isset($datatablesLangEs)) {
    $__dt_lang_path = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . 'es-ES.json';
    $datatablesLangEs = '{}';
    if (is_file($__dt_lang_path)) {
        $raw = @file_get_contents($__dt_lang_path);
        if ($raw !== false && $raw !== '') {
            $dec = @json_decode($raw);
            if ($dec !== null) {
                $datatablesLangEs = json_encode($dec, JSON_UNESCAPED_UNICODE);
            }
        }
    }
    unset($__dt_lang_path);
}
