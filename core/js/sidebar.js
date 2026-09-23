/**
 * Shell de Producción Aves: tema claro/oscuro, pin del sidebar, carga de módulos en iframe.
 * Conserva el comportamiento y persistencia (localStorage + cookie ix2-theme) .
 */
(function () {
    'use strict';

    var IX2_SIDEBAR_PIN_STORAGE = 'ix2-sidebar-pin';
    var IX2_SIDEBAR_MODULE_STORAGE = 'ix2-sidebar-module';

    function ix2GetFrame() {
        return document.getElementById('ix2-content-frame');
    }

    function ix2GetShell() {
        return document.getElementById('ix2-frame-shell');
    }

    function ix2IsNarrowViewport() {
        return window.matchMedia('(max-width: 1023px)').matches;
    }

    /* ── Tema claro/oscuro ── */

    window.ix2ToggleTheme = function () {
        var d = document.documentElement;
        var next = d.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        d.setAttribute('data-theme', next);
        try {
            localStorage.setItem('ix2-theme', next);
        } catch (e1) {}
        try {
            document.cookie = 'ix2-theme=' + encodeURIComponent(next) + '; path=/; max-age=31536000; SameSite=Lax';
        } catch (e2) {}
        d.style.colorScheme = next === 'dark' ? 'dark' : 'light';
        ix2SyncThemeIcons();
        ix2ScheduleIframeTheme();
    };

    window.ix2SyncThemeIcons = function () {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        document.querySelectorAll('.ix2-theme-moon').forEach(function (el) {
            el.classList.toggle('hidden', !dark);
        });
        document.querySelectorAll('.ix2-theme-sun').forEach(function (el) {
            el.classList.toggle('hidden', dark);
        });
    };

    /* ── Pin del sidebar ── */

    window.ix2ApplySidebarPinFromStorage = function () {
        var col = document.querySelector('.ix2-tree-col');
        if (!col) return;
        var pinned = false;
        try {
            pinned = localStorage.getItem(IX2_SIDEBAR_PIN_STORAGE) === '1';
        } catch (ePinRead) {}
        col.classList.toggle('ix2-sidebar-pin-open', pinned);
        var btn = document.getElementById('ix2-btn-sidebar-pin');
        if (btn) {
            btn.setAttribute('aria-pressed', pinned ? 'true' : 'false');
            btn.setAttribute('aria-label', pinned ? 'Menú fijado: clic para volver al colapso al pasar el cursor' : 'Fijar menú expandido');
            btn.title = pinned ? 'Liberar menú lateral' : 'Fijar menú lateral';
            var ic = btn.querySelector('.ix2-sidebar-pin-icon');
            if (ic) {
                ic.classList.remove('fa-lock', 'fa-lock-open');
                ic.classList.add(pinned ? 'fa-lock' : 'fa-lock-open');
            }
        }
    };

    window.ix2ToggleSidebarPin = function () {
        var col = document.querySelector('.ix2-tree-col');
        if (!col) return;
        var pinned = !col.classList.contains('ix2-sidebar-pin-open');
        try {
            localStorage.setItem(IX2_SIDEBAR_PIN_STORAGE, pinned ? '1' : '0');
        } catch (ePinWrite) {}
        window.ix2ApplySidebarPinFromStorage();
    };

    /* ── Carga de módulos en iframe ── */

    function ix2AppendThemeToUrl(url) {
        if (document.documentElement.getAttribute('data-theme') !== 'dark') return url;
        var s = String(url);
        if (/[?&]ix2_theme=/.test(s)) return s;
        return s + (s.indexOf('?') >= 0 ? '&' : '?') + 'ix2_theme=dark';
    }

    function ix2ComposeIframeUrl(url) {
        var composed = ix2AppendThemeToUrl(String(url));
        if (/[?&]ix2_flat=/.test(composed)) return composed;
        return composed + (composed.indexOf('?') >= 0 ? '&' : '?') + 'ix2_flat=1';
    }

    function ix2CanonicalModuleUrl(u) {
        if (u == null) return '';
        var s = String(u).trim().replace(/\\/g, '/');
        var q = s.indexOf('?');
        if (q >= 0) s = s.slice(0, q);
        return s.replace(/^\.\/+/, '');
    }

    function ix2ClearModButtonsActive() {
        document.querySelectorAll('.ix2-mod-btn.ix2-mod-active').forEach(function (b) {
            b.classList.remove('ix2-mod-active');
        });
    }

    function ix2SetActiveModButtons(url) {
        ix2ClearModButtonsActive();
        var target = ix2CanonicalModuleUrl(url);
        if (!target) return;
        document.querySelectorAll('.ix2-mod-btn[data-m-url]').forEach(function (b) {
            var du = ix2CanonicalModuleUrl(b.getAttribute('data-m-url'));
            if (du && du === target) b.classList.add('ix2-mod-active');
        });
    }

    function ix2ApplyIframeHostTheme() {
        var fr = ix2GetFrame();
        if (!fr) return;
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        try {
            fr.style.colorScheme = dark ? 'dark' : 'light';
        } catch (e) {}
        try {
            var doc = fr.contentDocument;
            if (!doc || doc.URL === 'about:blank' || !doc.documentElement) return;
            if (!dark) {
                doc.documentElement.setAttribute('data-theme', 'light');
                try {
                    doc.documentElement.style.colorScheme = 'light';
                } catch (eLight) {}
                var rm = doc.getElementById('ix2-host-theme');
                if (rm) rm.remove();
                try {
                    var lk = doc.getElementById('sip-ix2-module-dark');
                    if (lk) lk.remove();
                } catch (eLk) {}
                return;
            }
            doc.documentElement.setAttribute('data-theme', 'dark');
            function injectFullCss(cssText) {
                var el = doc.getElementById('ix2-host-theme');
                if (!el) {
                    el = doc.createElement('style');
                    el.id = 'ix2-host-theme';
                    (doc.head || doc.documentElement).appendChild(el);
                }
                el.textContent = cssText;
            }
            if (window._ix2DarkCssCached) {
                injectFullCss(window._ix2DarkCssCached);
                return;
            }
            fetch('assets/css/ix2-module-dark.css', { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                    window._ix2DarkCssCached = t;
                    try {
                        var d2 = fr.contentDocument;
                        if (d2 && d2.documentElement.getAttribute('data-theme') === 'dark') {
                            var el2 = d2.getElementById('ix2-host-theme');
                            if (!el2) {
                                el2 = d2.createElement('style');
                                el2.id = 'ix2-host-theme';
                                (d2.head || d2.documentElement).appendChild(el2);
                            }
                            el2.textContent = t;
                        }
                    } catch (e4) {}
                })
                .catch(function () {
                    var cs = getComputedStyle(document.documentElement);
                    var bg = (cs.getPropertyValue('--ix2-bg') || '#0a0c10').trim();
                    var fg = (cs.getPropertyValue('--ix2-text') || '#e8eaef').trim();
                    var surf = (cs.getPropertyValue('--ix2-surface') || '#12161d').trim();
                    injectFullCss(
                        ':root{color-scheme:dark}html,body{min-height:100%!important;background:' + bg + '!important;color:' + fg + '!important}' +
                        '[class*="bg-white"],.bg-gray-50,.bg-slate-50,.bg-zinc-50{background-color:' + surf + '!important}'
                    );
                });
        } catch (e3) {}
    }

    function ix2ScheduleIframeTheme() {
        ix2ApplyIframeHostTheme();
        [40, 150, 400, 1000].forEach(function (ms) {
            setTimeout(ix2ApplyIframeHostTheme, ms);
        });
    }

    window.ix2LoadModule = function (url, title) {
        if (!url) return;
        url = String(url).trim();
        if (!url) return;
        var shell = ix2GetShell();
        var fr = ix2GetFrame();
        if (shell) shell.classList.add('ix2-on');
        if (fr) {
            fr.onload = function () {
                ix2ScheduleIframeTheme();
            };
            fr.src = ix2ComposeIframeUrl(url);
            fr.dataset.loaded = '1';
        }
        ix2SetActiveModButtons(url);
        try {
            localStorage.setItem(IX2_SIDEBAR_MODULE_STORAGE, JSON.stringify({ url: url, title: title || '' }));
        } catch (eSaveMod) {}
        if (ix2IsNarrowViewport()) ix2CloseMobileSidebar();
    };

    window.ix2LoadModuleSinContexto = function (url, title) {
        window.ix2LoadModule(url, title);
    };

    /* ── Sidebar móvil ── */

    window.ix2CloseMobileSidebar = function () {
        document.body.classList.remove('ix2-sidebar-open');
        var bd = document.getElementById('ix2-sidebar-backdrop');
        if (bd) bd.setAttribute('aria-hidden', 'true');
        var openBtn = document.getElementById('ix2-btn-menu-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
    };

    window.ix2OpenMobileSidebar = function () {
        if (!ix2IsNarrowViewport()) return;
        document.body.classList.add('ix2-sidebar-open');
        var bd = document.getElementById('ix2-sidebar-backdrop');
        if (bd) bd.setAttribute('aria-hidden', 'false');
        var openBtn = document.getElementById('ix2-btn-menu-open');
        if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    };

    window.ix2ToggleMobileSidebar = function () {
        if (!ix2IsNarrowViewport()) return;
        if (document.body.classList.contains('ix2-sidebar-open')) {
            ix2CloseMobileSidebar();
        } else {
            ix2OpenMobileSidebar();
        }
    };

    /* ── Arranque ── */

    window.ix2ApplySidebarPinFromStorage();
    window.ix2SyncThemeIcons();

    window.addEventListener('resize', function () {
        if (!ix2IsNarrowViewport()) ix2CloseMobileSidebar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('ix2-sidebar-open')) {
            ix2CloseMobileSidebar();
        }
    });
})();
