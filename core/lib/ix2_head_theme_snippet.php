<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_asset_version.php';
sip_send_app_no_cache_headers();

if (!function_exists('sip_ix2_project_css_href')) {
    function sip_ix2_project_css_href(string $cssFile): string
    {
        $sn = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
        $idx = stripos($sn, '/modules/');
        if ($idx === false) {
            return 'assets/css/' . $cssFile;
        }
        $rest = substr($sn, $idx + strlen('/modules/'));
        $parts = array_values(array_filter(explode('/', $rest), static function ($p) {
            return $p !== '';
        }));
        if ($parts !== []) {
            array_pop($parts);
        }
        $ups = count($parts) + 1;

        return str_repeat('../', max(1, $ups)) . 'assets/css/' . $cssFile;
    }
}
if (!function_exists('sip_ix2_dark_stylesheet_href')) {
    function sip_ix2_dark_stylesheet_href(): string
    {
        return sip_ix2_project_css_href('ix2-module-dark.css');
    }
}
if (!function_exists('sip_ix2_embed_flat_stylesheet_href')) {
    function sip_ix2_embed_flat_stylesheet_href(): string
    {
        return sip_ix2_project_css_href('ix2-embed-flat.css');
    }
}

$__sip_ix2_qs = isset($_GET['ix2_theme']) ? strtolower(trim((string)$_GET['ix2_theme'])) : '';
$__sip_ix2_cookie = isset($_COOKIE['ix2-theme']) ? strtolower(trim((string)$_COOKIE['ix2-theme'])) : '';
$__sip_ix2_php_mode = '';
if ($__sip_ix2_qs === 'dark' || $__sip_ix2_qs === 'light') {
    $__sip_ix2_php_mode = $__sip_ix2_qs;
} elseif ($__sip_ix2_cookie === 'dark' || $__sip_ix2_cookie === 'light') {
    $__sip_ix2_php_mode = $__sip_ix2_cookie;
}
$__sip_ix2_dark_href = htmlspecialchars(sip_asset_url(sip_ix2_dark_stylesheet_href()), ENT_QUOTES, 'UTF-8');
$__sip_ix2_embed_flat_href = htmlspecialchars(sip_asset_url(sip_ix2_embed_flat_stylesheet_href()), ENT_QUOTES, 'UTF-8');
$__sip_ix2_no_blur_href = htmlspecialchars(sip_asset_url(sip_ix2_project_css_href('sip-overlays-no-blur.css')), ENT_QUOTES, 'UTF-8');
$__sip_modals_unified_href = htmlspecialchars(sip_asset_url(sip_ix2_project_css_href('sip-modals-unified.css')), ENT_QUOTES, 'UTF-8');
$__sip_ix2_asver_js = json_encode((string) SIP_ASVER);
?>
<script>window.SIP_ASVER=<?php echo $__sip_ix2_asver_js; ?>;</script>
<link rel="stylesheet" href="<?php echo $__sip_ix2_no_blur_href; ?>" id="sip-overlays-no-blur">
<?php if ($__sip_ix2_php_mode === 'dark'): ?>
<script>
(function () {
    var d = document.documentElement;
    if (!d) return;
    d.setAttribute('data-theme', 'dark');
    d.style.colorScheme = 'dark';
})();
</script>
<link rel="stylesheet" href="<?php echo $__sip_ix2_dark_href; ?>" id="sip-ix2-module-dark">
<?php elseif ($__sip_ix2_php_mode === 'light'): ?>
<script>
(function () {
    var d = document.documentElement;
    if (!d) return;
    d.setAttribute('data-theme', 'light');
    d.style.colorScheme = 'light';
})();
</script>
<?php endif; ?>
<script>
(function () {
    function resolveDarkCssHref() {
        var path = (window.location.pathname || '').replace(/\\/g, '/');
        var idx = path.indexOf('/modules/');
        var base = 'assets/css/ix2-module-dark.css';
        if (idx < 0) base = 'assets/css/ix2-module-dark.css';
        else {
            var rest = path.slice(idx + '/modules/'.length);
            var parts = rest.split('/').filter(Boolean);
            if (parts.length) parts.pop();
            var ups = parts.length + 1;
            var prefix = '';
            for (var u = 0; u < ups; u++) prefix += '../';
            base = prefix + 'assets/css/ix2-module-dark.css';
        }
        var v = (typeof window.SIP_ASVER !== 'undefined' && window.SIP_ASVER !== '') ? String(window.SIP_ASVER) : '';
        return v ? (base + (base.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(v)) : base;
    }
    function readCookieTheme() {
        try {
            var m = document.cookie.match(/(?:^|; )ix2-theme=([^;]*)/);
            return m ? decodeURIComponent(m[1]).toLowerCase().trim() : '';
        } catch (e0) { return ''; }
    }
    var mode = null;
    try {
        var qs = new URLSearchParams(window.location.search || '');
        var qDark = (qs.get('ix2_theme') || '').toLowerCase();
        if (qDark === 'dark') mode = 'dark';
        else if (qDark === 'light') mode = 'light';
    } catch (e1) {}
    if (mode == null) {
        var ct = readCookieTheme();
        if (ct === 'dark' || ct === 'light') mode = ct;
    }
    if (mode == null) {
        try {
            var stored = localStorage.getItem('ix2-theme');
            if (stored === 'dark' || stored === 'light') mode = stored;
        } catch (e2) {}
    }
    if (mode == null) {
        mode = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    var d = document.documentElement;
    d.setAttribute('data-theme', mode);
    d.style.colorScheme = mode === 'dark' ? 'dark' : 'light';
    if (mode === 'dark') {
        if (!document.getElementById('sip-ix2-module-dark') && !document.querySelector('link[href*="ix2-module-dark"]')) {
            var link = document.createElement('link');
            link.id = 'sip-ix2-module-dark';
            link.rel = 'stylesheet';
            link.href = resolveDarkCssHref();
            (document.head || document.documentElement).appendChild(link);
        }
    }
})();
</script>
<link rel="stylesheet" href="<?php echo $__sip_modals_unified_href; ?>" id="sip-modals-unified">
<link rel="stylesheet" href="<?php echo $__sip_ix2_embed_flat_href; ?>" id="sip-ix2-embed-flat">
<script>
(function () {
    try {
        var qs = new URLSearchParams(window.location.search || '');
        if ((window.parent && window.parent !== window) || qs.get('ix2_flat') === '1') {
            document.documentElement.classList.add('ix2-embed');
        }
    } catch (eEmbed) {}
})();
</script>
<style id="sip-ix2-fouc-guard">html[data-theme="dark"]{background-color:#09090b!important;color-scheme:dark}html[data-theme="dark"] body{background-color:#09090b!important;color:#e4e4e7!important;color-scheme:dark!important}html[data-theme="dark"] body.bg-gray-50,html[data-theme="dark"] body.bg-sky-50,html[data-theme="dark"] body.bg-light{background-color:#09090b!important}html[data-theme="dark"] body .bg-white{background-color:#18181b!important;color:#e4e4e7!important}</style>
