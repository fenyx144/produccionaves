<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$paNavItems = $paNavItems ?? [];

$paUsuario = (string) ($_SESSION['usuario'] ?? '');

?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo pa_e(PA_APP_NAME); ?></title>
    <script>
        (function() {
            var k = 'ix2-theme',
                d = document.documentElement;
            function persistTheme(mode) {
                try {
                    localStorage.setItem(k, mode);
                } catch (e0) {}
                try {
                    document.cookie = k + '=' + encodeURIComponent(mode) + '; path=/; max-age=31536000; SameSite=Lax';
                } catch (e1) {}
            }
            function readCookieTheme() {
                try {
                    var m = document.cookie.match(/(?:^|; )ix2-theme=([^;]*)/);
                    return m ? decodeURIComponent(m[1]) : '';
                } catch (e2) {
                    return '';
                }
            }
            var mode = null;
            try {
                var s = localStorage.getItem(k);
                if (s === 'dark' || s === 'light') mode = s;
            } catch (e3) {}
            if (mode == null) {
                var c = readCookieTheme();
                if (c === 'dark' || c === 'light') mode = c;
            }
            if (mode == null) {
                if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) mode = 'dark';
                else mode = 'light';
            }
            d.setAttribute('data-theme', mode);
            d.style.colorScheme = mode === 'dark' ? 'dark' : 'light';
            persistTheme(mode);
        })();
    </script>
    <link rel="stylesheet" href="<?php echo pa_e(pa_asset_url('assets/css/shell.css')); ?>">
    <link rel="stylesheet" href="<?php echo pa_e(pa_asset_url('assets/fontawesome/css/all.min.css')); ?>">
</head>
<body class="ix2-shell ix2-scroll-std">

    <button type="button" id="ix2-sidebar-backdrop" class="ix2-sidebar-backdrop" aria-hidden="true" tabindex="-1" aria-label="Cerrar menú lateral" onclick="ix2CloseMobileSidebar()"></button>

    <div class="ix2-main">
        <div class="ix2-tree-col">
            <div class="ix2-tree-card" id="ix2-sidebar-drawer">
                <div class="ix2-sidebar-scroll ix2-scroll-std">
                    <div class="ix2-sidebar-top">
                        <div class="ix2-sidebar-top-row">
                            <span class="ix2-sidebar-brand flex items-center gap-2 min-w-0">
                                <i class="fas fa-feather ix2-brand-icon flex-shrink-0 opacity-95" aria-hidden="true"></i>
                                <span class="ix2-brand-text whitespace-nowrap"><?php echo pa_e(PA_APP_NAME); ?></span>
                            </span>
                            <div class="ix2-sidebar-actions">
                                <div class="ix2-sidebar-theme-pin">
                                    <button type="button" id="ix2-btn-theme" class="ix2-theme-switch" onclick="ix2ToggleTheme()" aria-label="Cambiar tema claro u oscuro" title="Tema">
                                        <span class="ix2-theme-thumb">
                                            <i class="fas fa-sun ix2-theme-sun"></i>
                                            <i class="fas fa-moon ix2-theme-moon hidden"></i>
                                        </span>
                                    </button>
                                    <button type="button" id="ix2-btn-sidebar-pin" class="ix2-icon-btn ix2-sidebar-pin-btn ix2-sidebar-action-hit" onclick="ix2ToggleSidebarPin()" aria-pressed="false" aria-label="Fijar menú expandido" title="Fijar menú lateral">
                                        <i class="fas fa-lock-open ix2-sidebar-pin-icon text-sm" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <details class="ix2-user-wrap">
                                    <summary class="ix2-icon-btn ix2-sidebar-action-hit" aria-label="Usuario">
                                        <i class="fas fa-user text-sm"></i>
                                    </summary>
                                    <div class="ix2-user-menu">
                                        <div class="ix2-user-menu-header"><?php echo pa_e($paUsuario); ?></div>
                                        <a href="#" class="ix2-user-out" onclick="logout(); return false;">Salir</a>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($paNavItems)) { ?>
                    <details class="ix2-sb-acc" data-ix2-mobile-hide-back="1" open>
                        <summary>
                            <i class="fas fa-dove ix2-sb-main-ic" aria-hidden="true"></i>
                            <span class="ix2-sb-label">Mortalidad</span>
                            <i class="fas fa-chevron-right ix2-sb-chev" aria-hidden="true"></i>
                        </summary>
                        <div class="ix2-sb-body ix2-sb-body--nested">
                            <div class="ix2-link-row">
                                <?php foreach ($paNavItems as $paItem) { ?>
                                <button type="button" class="ix2-mod-btn"
                                    data-m-url="<?php echo pa_e($paItem['url']); ?>"
                                    data-m-title="<?php echo pa_e($paItem['title']); ?>"
                                    onclick="ix2LoadModule(this.getAttribute('data-m-url'),this.getAttribute('data-m-title'))"><?php echo pa_e($paItem['label']); ?></button>
                                <?php } ?>
                            </div>
                        </div>
                    </details>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="ix2-panel-col">
            <div id="ix2-app-bar" class="ix2-app-bar hidden" role="banner">
                <button type="button" id="ix2-btn-menu-open" class="ix2-icon-btn ix2-app-bar-menu-btn" onclick="ix2ToggleMobileSidebar()" aria-label="Abrir menú lateral" aria-expanded="false" aria-controls="ix2-sidebar-drawer">
                    <i class="fas fa-bars text-sm" aria-hidden="true"></i>
                </button>
                <div class="ix2-app-bar-titles">
                    <span id="ix2-app-bar-brand" class="ix2-app-bar-brand"><?php echo pa_e(PA_APP_NAME); ?></span>
                    <div id="ix2-head-context" class="ix2-panel-context"></div>
                </div>
            </div>
            <div id="ix2-frame-shell">
                <iframe id="ix2-content-frame" class="ix2-frame" title="Módulo" src="about:blank"></iframe>
            </div>
        </div>
    </div>
