<?php
require_once __DIR__ . '/config.php';

?>
    <script src="<?php echo pa_e(pa_asset_url('core/js/logout.js')); ?>"></script>
    <script src="<?php echo pa_e(pa_asset_url('core/js/sidebar.js')); ?>"></script>
    <script>
        (function() {
            var IX2_SIDEBAR_MODULE_STORAGE = 'ix2-sidebar-module';
            function restoreModule() {
                var raw = null;
                try {
                    raw = localStorage.getItem(IX2_SIDEBAR_MODULE_STORAGE);
                } catch (e) {}
                var url = '', title = '';
                if (raw) {
                    try {
                        var j = JSON.parse(raw);
                        if (j && j.url) { url = j.url; title = j.title || ''; }
                    } catch (e2) {}
                }
                if (!url) {
                    var first = document.querySelector('.ix2-mod-btn[data-m-url]');
                    if (first) {
                        url = first.getAttribute('data-m-url');
                        title = first.getAttribute('data-m-title') || '';
                    }
                }
                if (url) {
                    window.ix2LoadModule(url, title);
                }
            }
            function syncAppBar() {
                var appBar = document.getElementById('ix2-app-bar');
                if (!appBar) return;
                var narrow = window.matchMedia('(max-width: 1023px)').matches;
                appBar.classList.toggle('hidden', !narrow);
            }
            window.addEventListener('resize', syncAppBar);
            syncAppBar();
            restoreModule();
        })();
    </script>
</body>
</html>
