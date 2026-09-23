 /**
 * Filtro de periodo interno al modal Granjas y campañas (solo lista campañas).
 * Por defecto: último año, panel colapsado.
 */
(function (global) {
    'use strict';

    var wired = {};

    function el(id) {
        return document.getElementById(id);
    }

    function localYmd(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function defaultDesde() {
        var d = new Date();
        d.setFullYear(d.getFullYear() - 1);
        return localYmd(d);
    }

    function defaultHasta() {
        return localYmd(new Date());
    }

    function fmtEs(iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso || '';
        var p = iso.split('-');
        return parseInt(p[2], 10) + '/' + parseInt(p[1], 10) + '/' + p[0];
    }

    function readState(prefix) {
        var desde = String((el(prefix + '-periodo-camp-desde') || {}).value || '').trim();
        var hasta = String((el(prefix + '-periodo-camp-hasta') || {}).value || '').trim();
        if (!desde || !hasta) {
            return null;
        }
        return {
            periodoTipo: 'ENTRE_FECHAS',
            fechaUnica: '',
            fechaInicio: desde,
            fechaFin: hasta,
            mesUnico: '',
            mesInicio: '',
            mesFin: ''
        };
    }

    function updateResumen(prefix) {
        var res = el(prefix + '-periodo-camp-resumen');
        if (!res) return;
        var st = readState(prefix);
        if (!st) {
            res.textContent = 'Complete las fechas';
            return;
        }
        res.textContent = fmtEs(st.fechaInicio) + ' – ' + fmtEs(st.fechaFin);
    }

    function GmcPeriodoCampanias() {}

    GmcPeriodoCampanias.isValid = function (prefix) {
        var st = readState(prefix);
        if (!st) return false;
        return !!(st.fechaInicio && st.fechaFin);
    };

    GmcPeriodoCampanias.getParams = function (prefix) {
        var st = readState(prefix);
        if (!st) {
            return {
                periodoTipo: 'ENTRE_FECHAS',
                fechaUnica: '',
                fechaInicio: defaultDesde(),
                fechaFin: defaultHasta(),
                mesUnico: '',
                mesInicio: '',
                mesFin: ''
            };
        }
        return {
            periodoTipo: st.periodoTipo || 'ENTRE_FECHAS',
            fechaUnica: st.fechaUnica || '',
            fechaInicio: st.fechaInicio || '',
            fechaFin: st.fechaFin || '',
            mesUnico: st.mesUnico || '',
            mesInicio: st.mesInicio || '',
            mesFin: st.mesFin || ''
        };
    };

    GmcPeriodoCampanias.getFechaReferencia = function (prefix) {
        var st = readState(prefix);
        if (st && st.fechaFin) {
            return st.fechaFin;
        }
        return defaultHasta();
    };

    GmcPeriodoCampanias.appendToSearchParams = function (p, prefix) {
        var params = GmcPeriodoCampanias.getParams(prefix);
        Object.keys(params).forEach(function (k) {
            p.set(k, params[k] || '');
        });
    };

    GmcPeriodoCampanias.init = function (prefix, opts) {
        opts = opts || {};
        if (wired[prefix]) {
            return;
        }
        wired[prefix] = true;

        var toggle = el(prefix + '-periodo-camp-toggle');
        var body = el(prefix + '-periodo-camp-body');
        var desde = el(prefix + '-periodo-camp-desde');
        var hasta = el(prefix + '-periodo-camp-hasta');

        if (!toggle || !body) {
            return;
        }

        function setExpanded(open) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            body.classList.toggle('lay-hidden', !open);
            toggle.classList.toggle('gmc-periodo-camp-toggle--open', open);
        }

        setExpanded(false);
        updateResumen(prefix);

        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') !== 'true';
            setExpanded(open);
        });

        function onChange() {
            updateResumen(prefix);
            if (typeof opts.onChange === 'function') {
                opts.onChange();
            }
        }

        if (desde) {
            if (!desde.value) desde.value = defaultDesde();
            desde.addEventListener('change', onChange);
        }
        if (hasta) {
            if (!hasta.value) hasta.value = defaultHasta();
            hasta.addEventListener('change', onChange);
        }
    };

    global.GmcPeriodoCampanias = GmcPeriodoCampanias;
}(typeof window !== 'undefined' ? window : this));
