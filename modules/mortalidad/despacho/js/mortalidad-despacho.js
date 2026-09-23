(function () {
    'use strict';

    const cfg = window.MORT_DESPACHO_CFG || {};
    let cargando = false;
    let mrtDspGmc = null;

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatearNumero(n, decimales) {
        const v = Number(n) || 0;
        return v.toLocaleString('es-PE', {
            minimumFractionDigits: decimales ?? 0,
            maximumFractionDigits: decimales ?? 0
        });
    }

    function formatearFechaDMY(ymd) {
        if (!ymd) return '';
        const p = String(ymd).slice(0, 10).split('-');
        if (p.length !== 3) return ymd;
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function formatearFechaYMDslash(ymd) {
        if (!ymd) return '';
        const p = String(ymd).slice(0, 10).split('-');
        if (p.length !== 3) return ymd;
        return p[0] + '/' + p[1] + '/' + p[2];
    }

    function tituloPeriodo(rango) {
        if (!rango || !rango.desde) return '';
        if (rango.desde === rango.hasta) {
            return formatearFechaDMY(rango.desde);
        }
        return formatearFechaDMY(rango.desde) + ' – ' + formatearFechaDMY(rango.hasta);
    }

    function leerFiltrosFormulario() {
        return {
            periodoTipo: ($('#mdp-periodo-tipo').val() || 'POR_FECHA').trim(),
            fechaUnica: ($('#mdp-fecha-unica').val() || '').trim(),
            fechaInicio: ($('#mdp-fecha-inicio').val() || '').trim(),
            fechaFin: ($('#mdp-fecha-fin').val() || '').trim(),
            mesUnico: ($('#mdp-mes-unico').val() || '').trim(),
            mesInicio: ($('#mdp-mes-inicio').val() || '').trim(),
            mesFin: ($('#mdp-mes-fin').val() || '').trim(),
            granja: ($('#mrt-dsp-h-granja').val() || '').trim(),
            campania: ($('#mrt-dsp-h-campania').val() || '').trim()
        };
    }

    function syncVisibilidadPeriodo() {
        const t = ($('#mdp-periodo-tipo').val() || '').trim();
        $('.mdp-bloque-periodo').addClass('hidden');
        if (t === 'POR_FECHA') {
            $('#mdp-bloque-fecha-unica').removeClass('hidden');
        } else if (t === 'ENTRE_FECHAS') {
            $('#mdp-bloque-rango-fechas').removeClass('hidden');
        } else if (t === 'POR_MES') {
            $('#mdp-bloque-mes-unico').removeClass('hidden');
        } else if (t === 'ENTRE_MESES') {
            $('#mdp-bloque-rango-meses').removeClass('hidden');
        }
    }

    function renderTablaSimple(containerId, cols, filas, totalRow) {
        if (!filas || filas.length === 0) {
            $(containerId).html('<p class="mdp-empty">Sin datos para el periodo y filtros seleccionados.</p>');
            return;
        }
        let html = '<table class="data-table config-table w-full text-sm border-collapse"><thead><tr>';
        cols.forEach(function (c) {
            const cls = c.thClass ? ' class="' + c.thClass + '"' : '';
            html += '<th' + cls + '>' + escapeHtml(c.label) + '</th>';
        });
        html += '</tr></thead><tbody>';
        filas.forEach(function (f, idx) {
            html += '<tr>';
            cols.forEach(function (c) {
                const tdCls = c.tdClass ? ' class="' + c.tdClass + '"' : '';
                const val = c.render(f, idx);
                html += '<td' + tdCls + '>' + escapeHtml(val) + '</td>';
            });
            html += '</tr>';
        });
        if (totalRow) {
            html += '<tr class="mdp-fila-total">';
            cols.forEach(function (c, idx) {
                const tdCls = c.tdClass ? ' class="' + c.tdClass + '"' : '';
                html += '<td' + tdCls + '>' + escapeHtml(totalRow(c, idx)) + '</td>';
            });
            html += '</tr>';
        }
        html += '</tbody></table>';
        $(containerId).html(html);
    }

    function setTituloPanel(tituloId, base, rangoTexto) {
        const sub = rangoTexto ? ' <span>· ' + escapeHtml(rangoTexto) + '</span>' : '';
        $(tituloId).html(escapeHtml(base) + sub);
    }

    function pintarCausas(data, rangoTexto) {
        const filas = (data && data.filas) || [];
        const total = (data && data.total) || 0;
        setTituloPanel('#mdp-titulo-causas', 'Mortalidad por causa', rangoTexto);
        renderTablaSimple(
            '#mdp-tabla-causas',
            [
                { label: 'N°', thClass: 'col-num', tdClass: 'col-num', render: function (_f, idx) { return String(idx + 1); } },
                { label: 'Causas', render: function (f) { return f.causa; } },
                { label: 'Cant. mort.', thClass: 'col-qty', tdClass: 'col-qty', render: function (f) { return formatearNumero(f.cantidad); } },
                {
                    label: '%',
                    thClass: 'col-pct',
                    tdClass: 'col-pct',
                    render: function (f) {
                        return formatearNumero(f.porcentaje) + '%';
                    }
                }
            ],
            filas,
            function (_c, idx) {
                if (idx === 0) return '';
                if (idx === 1) return 'Total';
                if (idx === 2) return formatearNumero(total);
                return '100%';
            }
        );
    }

    function pintarEtapas(data, rangoTexto) {
        const filas = (data && data.filas) || [];
        const total = (data && data.total) || 0;
        setTituloPanel('#mdp-titulo-etapas', 'Mortalidad por etapa del proceso', rangoTexto);
        renderTablaSimple(
            '#mdp-tabla-etapas',
            [
                { label: 'N°', thClass: 'col-num', tdClass: 'col-num', render: function (_f, idx) { return String(idx + 1); } },
                { label: 'Etapa', render: function (f) { return f.etapa; } },
                { label: 'Cant. mort.', thClass: 'col-qty', tdClass: 'col-qty', render: function (f) { return formatearNumero(f.cantidad); } },
                {
                    label: '%',
                    thClass: 'col-pct',
                    tdClass: 'col-pct',
                    render: function (f) {
                        return formatearNumero(f.porcentaje) + '%';
                    }
                }
            ],
            filas,
            function (_c, idx) {
                if (idx === 0) return '';
                if (idx === 1) return 'Total';
                if (idx === 2) return formatearNumero(total);
                return '100%';
            }
        );
    }

    function pintarResumen(filas) {
        if (!filas || filas.length === 0) {
            $('#mdp-tabla-resumen').html('<p class="mdp-empty">Sin registros de despacho en el periodo.</p>');
            return;
        }
        const rows = filas.map(function (r, idx) {
            return {
                numero: idx + 1,
                fecha: formatearFechaYMDslash(r.fecha),
                cencos: r.cencos,
                granja: r.granja,
                cantidad: formatearNumero(r.cantidad, 2),
                muertos: r.muertos > 0 ? formatearNumero(r.muertos) : '-',
                porcentaje: formatearNumero(r.porcentaje, 2) + '%'
            };
        });
        renderTablaSimple(
            '#mdp-tabla-resumen',
            [
                { label: 'N°', thClass: 'col-num', tdClass: 'col-num', render: function (f) { return String(f.numero); } },
                { label: 'Fecha', render: function (f) { return f.fecha; } },
                { label: 'Cencos', render: function (f) { return f.cencos; } },
                { label: 'Granja', render: function (f) { return f.granja; } },
                { label: 'Cantidad', thClass: 'col-qty', tdClass: 'col-qty', render: function (f) { return f.cantidad; } },
                { label: 'Muertos', thClass: 'col-qty', tdClass: 'col-qty', render: function (f) { return f.muertos; } },
                {
                    label: '% Mort. Despacho',
                    thClass: 'col-pct',
                    tdClass: 'col-pct',
                    render: function (f) { return f.porcentaje; }
                }
            ],
            rows,
            null
        );
    }

    function cargarAnalisis() {
        if (cargando) return;
        cargando = true;
        $('#mdp-btn-consultar').prop('disabled', true);
        const params = leerFiltrosFormulario();
        $.getJSON(cfg.apiUrl, params)
            .done(function (j) {
                if (!j || !j.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) || 'No se pudo cargar el análisis.' });
                    return;
                }
                const rangoTexto = tituloPeriodo(j.rango);
                pintarCausas(j.causas, rangoTexto);
                pintarEtapas(j.etapas, rangoTexto);
                pintarResumen(j.resumenGranjas || []);
            })
            .fail(function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Fallo la consulta al servidor.' });
            })
            .always(function () {
                cargando = false;
                $('#mdp-btn-consultar').prop('disabled', false);
            });
    }

    function syncGranjaDisplay() {
        const g = ($('#mrt-dsp-h-granja').val() || '').trim();
        const c = ($('#mrt-dsp-h-campania').val() || '').trim();
        const inp = document.getElementById('mrt-dsp-granja-resumen');
        if (!inp) return;
        if (g && c) {
            let nom = '';
            const meta = (cfg.granjasMeta || []).find(function (m) { return m.granja === g; });
            if (meta) nom = meta.nombre || meta.nombre_granja || '';
            inp.value = g + (nom ? ' ' + nom : '') + ' - ' + c;
            inp.title = 'Granja ' + g + (nom ? ' ' + nom : '') + ', campaña ' + c + ' — Clic para cambiar';
        } else {
            inp.value = '';
            inp.placeholder = 'Clic para seleccionar';
            inp.title = 'Sin filtro: se incluyen todas las granjas';
        }
    }

    function limpiarFiltros() {
        const hoy = new Date();
        const y = hoy.getFullYear();
        const m = String(hoy.getMonth() + 1).padStart(2, '0');
        const d = String(hoy.getDate()).padStart(2, '0');
        const hoyStr = y + '-' + m + '-' + d;
        $('#mdp-periodo-tipo').val('POR_FECHA');
        $('#mdp-fecha-unica').val(hoyStr);
        $('#mdp-fecha-inicio').val(hoyStr);
        $('#mdp-fecha-fin').val(hoyStr);
        $('#mdp-mes-unico').val(y + '-' + m);
        $('#mdp-mes-inicio').val(y + '-01');
        $('#mdp-mes-fin').val(y + '-' + m);
        $('#mrt-dsp-h-granja, #mrt-dsp-h-campania').val('');
        syncVisibilidadPeriodo();
        syncGranjaDisplay();
    }

    function getCampaniasUrl() {
        const f = leerFiltrosFormulario();
        const p = new URLSearchParams({
            periodoTipo: f.periodoTipo || 'POR_FECHA',
            fechaUnica: f.fechaUnica || '',
            fechaInicio: f.fechaInicio || '',
            fechaFin: f.fechaFin || '',
            mesUnico: f.mesUnico || '',
            mesInicio: f.mesInicio || '',
            mesFin: f.mesFin || ''
        });
        const base = String(cfg.apiUrl || '');
        let parentDir = base.substring(0, base.lastIndexOf('/'));
        parentDir = parentDir.substring(0, parentDir.lastIndexOf('/'));
        return parentDir + '/get_campanias_mortalidad.php?' + p.toString();
    }

    function initMrtDspGmc() {
        if (!window.GmcGranjasCampanias || mrtDspGmc) {
            return;
        }
        mrtDspGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-dsp',
            shellPanelId: 'mrt-dsp-modal-granjas',
            mode: 'single',
            bodyOpenClass: 'mrt-dsp-modal-granjas-open',
            openTriggerId: 'mrt-dsp-granja-resumen',
            getCodigoSeis: function () {
                const g = ($('#mrt-dsp-h-granja').val() || '').trim();
                const c = ($('#mrt-dsp-h-campania').val() || '').trim();
                return g && c ? g + c : '';
            },
            setCodigoSeis: function (cod) {
                if (cod && cod.length >= 6) {
                    $('#mrt-dsp-h-granja').val(cod.slice(0, 3));
                    $('#mrt-dsp-h-campania').val(cod.slice(-3));
                } else {
                    $('#mrt-dsp-h-granja, #mrt-dsp-h-campania').val('');
                }
                syncGranjaDisplay();
            },
            cencosFetch: function () {
                const url = getCampaniasUrl();
                if (!url) {
                    return Promise.resolve({ granjas: cfg.granjasMeta || [], campanias_por_granja: {}, aviso: '' });
                }
                return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
            }
        });
    }

    $(function () {
        syncVisibilidadPeriodo();
        syncGranjaDisplay();
        initMrtDspGmc();

        $('#btnToggleFiltrosMdp').on('click', function () {
            $('#contenidoFiltrosMdp').slideToggle(200);
            $('#iconoFiltrosMdp').toggleClass('rotate-180');
        });

        $('#mdp-periodo-tipo').on('change', syncVisibilidadPeriodo);
        $('#mdp-btn-consultar').on('click', cargarAnalisis);
        $('#mdp-btn-limpiar').on('click', function () {
            limpiarFiltros();
        });

        cargarAnalisis();
    });
})();
