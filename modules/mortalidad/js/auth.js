/**
 * Tema ix2 alineado con index.php: ?ix2_theme=, localStorage ix2-theme, prefers-color-scheme.
 * Carga css/ix2-module-dark.css solo si hace falta (evita duplicar si ya viene por @import).
 */
(function () {
    function resolveDarkCssHref() {
        var path = (window.location.pathname || '').replace(/\\/g, '/');
        var idx = path.indexOf('/modules/');
        if (idx < 0) {
            return 'assets/css/ix2-module-dark.css';
        }
        var rest = path.slice(idx + '/modules/'.length);
        var parts = rest.split('/').filter(Boolean);
        if (parts.length) {
            parts.pop();
        }
        var ups = parts.length + 1;
        var prefix = '';
        for (var u = 0; u < ups; u++) {
            prefix += '../';
        }
        return prefix + 'assets/css/ix2-module-dark.css';
    }

    function hasIx2DarkSheet() {
        try {
            var sheets = document.styleSheets;
            for (var i = 0; i < sheets.length; i++) {
                var href = (sheets[i].href || '').toLowerCase();
                if (href.indexOf('ix2-module-dark.css') >= 0) {
                    return true;
                }
            }
        } catch (e) { /* CORS u otro */ }
        return !!document.querySelector('link[href*="ix2-module-dark"]');
    }

    function ensureDarkStylesheet() {
        if (document.getElementById('sip-ix2-module-dark')) {
            return;
        }
        if (hasIx2DarkSheet()) {
            return;
        }
        var link = document.createElement('link');
        link.id = 'sip-ix2-module-dark';
        link.rel = 'stylesheet';
        link.href = resolveDarkCssHref();
        (document.head || document.documentElement).appendChild(link);
    }

    function removeDarkStylesheetInjected() {
        var lk = document.getElementById('sip-ix2-module-dark');
        if (lk) {
            lk.remove();
        }
    }

    function applyTheme(mode) {
        var d = document.documentElement;
        d.setAttribute('data-theme', mode);
        d.style.colorScheme = mode === 'dark' ? 'dark' : 'light';
        if (mode === 'dark') {
            ensureDarkStylesheet();
        } else {
            removeDarkStylesheetInjected();
        }
    }

    var mode = null;
    try {
        var qs = new URLSearchParams(window.location.search || '');
        var qDark = qs.get('ix2_theme');
        if (qDark === 'dark') {
            mode = 'dark';
        } else if (qDark === 'light') {
            mode = 'light';
        }
    } catch (e0) { /* ignore */ }
    if (mode == null) {
        try {
            var m = document.cookie.match(/(?:^|; )ix2-theme=([^;]*)/);
            var ct = m ? decodeURIComponent(m[1]).toLowerCase().trim() : '';
            if (ct === 'dark' || ct === 'light') {
                mode = ct;
            }
        } catch (e1b) { /* ignore */ }
    }
    if (mode == null) {
        try {
            var stored = localStorage.getItem('ix2-theme');
            if (stored === 'dark' || stored === 'light') {
                mode = stored;
            }
        } catch (e1) { /* ignore */ }
    }
    if (mode == null) {
        if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) {
            mode = 'dark';
        } else {
            mode = 'light';
        }
    }
    applyTheme(mode);
})();

/**
 * Redirige al login cuando:
 * - Cualquier fetch recibe HTTP 401, o
 * - La respuesta es JSON con:
 *   - success:false y mensaje de sesión inválida, o
 *   - success:false y message exacto "No autorizado" / "No autorizado." (sin texto extra), o
 *   - campo error (u otros) con ese mismo texto genérico (p. ej. { error: 'No autorizado' }).
 *
 * No redirige si el mensaje es más largo (p. ej. "No autorizado. Solo ADMIN.") para no confundir
 * con falta de permisos estando logueado.
 *
 * Incluir en páginas bajo modules/ que consuman APIs con fetch.
 */
(function() {
    var path = window.location.pathname || '';
    var idx = path.indexOf('/modules/');
    var loginUrl = (idx >= 0 ? path.substring(0, idx) : '') + '/core/auth/login.php';
    if (!loginUrl.startsWith('/')) loginUrl = '/' + loginUrl;
    var origFetch = window.fetch;
    if (!origFetch) return;

    function goLogin() {
        if (window.top !== window.self) window.top.location.href = loginUrl;
        else window.location.href = loginUrl;
    }

    /** Sesion no valida. / Sesión no válida */
    function isSessionInvalidMessage(msg) {
        if (msg == null || typeof msg !== 'string') return false;
        var s = msg.toLowerCase().replace(/\s+/g, ' ').trim();
        if (/sesi[oó]n\s+no\s+v[áa]lida/.test(s)) return true;
        if (s.indexOf('sesion') >= 0 && s.indexOf('no valida') >= 0) return true;
        if (s.indexOf('sesión') >= 0 && s.indexOf('no válida') >= 0) return true;
        return false;
    }

    /** Solo el aviso genérico de sesión no activa (no frases de permisos). */
    function isBareNoAutorizado(val) {
        if (val == null || typeof val !== 'string') return false;
        var s = val.toLowerCase().replace(/\s+/g, ' ').trim();
        return s === 'no autorizado' || s === 'no autorizado.';
    }

    function shouldRedirectFromJson(j) {
        if (!j || typeof j !== 'object') return false;
        if (j.success === false) {
            if (isSessionInvalidMessage(j.message)) return true;
            if (isBareNoAutorizado(j.message)) return true;
        }
        if (isBareNoAutorizado(j.error)) return true;
        return false;
    }

    window.fetch = function(url, opts) {
        return origFetch.apply(this, arguments).then(function(r) {
            if (r.status === 401) {
                goLogin();
                return Promise.reject(new Error('Unauthorized'));
            }
            var ct = (r.headers.get('content-type') || '').toLowerCase();
            if (ct.indexOf('application/json') < 0 && ct.indexOf('text/json') < 0) {
                return r;
            }
            // Leer el body una sola vez y devolver un Response nuevo para que el consumidor
            // pueda llamar .json() o .text() sin riesgo de "body stream already read"
            return r.text().then(function(t) {
                try {
                    var j = JSON.parse(t);
                    if (shouldRedirectFromJson(j)) {
                        goLogin();
                        return Promise.reject(new Error('Session expired'));
                    }
                } catch (e) { /* cuerpo no JSON */ }
                return new Response(t, {
                    status: r.status,
                    statusText: r.statusText,
                    headers: r.headers
                });
            });
        });
    };
})();
