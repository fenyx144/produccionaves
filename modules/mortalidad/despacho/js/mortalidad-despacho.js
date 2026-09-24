(function () {
    'use strict';

    const cfg = window.MORT_DESPACHO_CFG || {};
    let cargando = false;
    let mrtDspGmc = null;
    let selCodes = [];
    let campByGranja = {};

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

    function setMultiState(st) {
        selCodes = (st && st.selCodes) ? st.selCodes.slice() : [];
        campByGranja = (st && st.campByGranja) ? JSON.parse(JSON.stringify(st.campByGranja)) : {};
    }

    function leerParametrosPeriodoDashboard() {
        return {
            periodoTipo: ($('#mdp-periodo-tipo').val() || 'POR_FECHA').trim(),
            fechaUnica: ($('#mdp-fecha-unica').val() || '').trim(),
            fechaInicio: ($('#mdp-fecha-inicio').val() || '').trim(),
            fechaFin: ($('#mdp-fecha-fin').val() || '').trim(),
            mesUnico: ($('#mdp-mes-unico').val() || '').trim(),
            mesInicio: ($('#mdp-mes-inicio').val() || '').trim(),
            mesFin: ($('#mdp-mes-fin').val() || '').trim()
        };
    }

    function ultimoDiaMes(ym) {
        if (!ym || !/^\d{4}-\d{2}$/.test(ym)) return '';
        const p = ym.split('-');
        const y = parseInt(p[0], 10);
        const m = parseInt(p[1], 10);
        const d = new Date(y, m, 0);
        return y + '-' + String(m).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function rangoYmdDesdePeriodoDashboard() {
        const f = leerParametrosPeriodoDashboard();
        const t = f.periodoTipo;
        if (t === 'POR_FECHA' && f.fechaUnica) {
            return { desde: f.fechaUnica, hasta: f.fechaUnica };
        }
        if (t === 'ENTRE_FECHAS' && f.fechaInicio && f.fechaFin) {
            return { desde: f.fechaInicio, hasta: f.fechaFin };
        }
        if (t === 'POR_MES' && f.mesUnico) {
            return { desde: f.mesUnico + '-01', hasta: ultimoDiaMes(f.mesUnico) };
        }
        if (t === 'ENTRE_MESES' && f.mesInicio && f.mesFin) {
            return { desde: f.mesInicio + '-01', hasta: ultimoDiaMes(f.mesFin) };
        }
        if (t === 'ULTIMA_SEMANA') {
            const hoy = new Date();
            const fin = hoy.getFullYear() + '-' +
                String(hoy.getMonth() + 1).padStart(2, '0') + '-' +
                String(hoy.getDate()).padStart(2, '0');
            const d0 = new Date(hoy.getTime());
            d0.setDate(d0.getDate() - 6);
            const desde = d0.getFullYear() + '-' +
                String(d0.getMonth() + 1).padStart(2, '0') + '-' +
                String(d0.getDate()).padStart(2, '0');
            return { desde: desde, hasta: fin };
        }
        return { desde: '', hasta: '' };
    }

    /** Alinea el periodo del modal de campañas con los filtros del dashboard (consultas acotadas). */
    function syncModalPeriodoDesdeDashboard() {
        const r = rangoYmdDesdePeriodoDashboard();
        if (!r.desde || !r.hasta) {
            return;
        }
        const elDesde = document.getElementById('mrt-dsp-periodo-camp-desde');
        const elHasta = document.getElementById('mrt-dsp-periodo-camp-hasta');
        if (elDesde) {
            elDesde.value = r.desde;
        }
        if (elHasta) {
            elHasta.value = r.hasta;
        }
    }

    function appendPeriodoDashboard(p) {
        const f = leerParametrosPeriodoDashboard();
        p.set('periodoTipo', f.periodoTipo || 'POR_FECHA');
        p.set('fechaUnica', f.fechaUnica || '');
        p.set('fechaInicio', f.fechaInicio || '');
        p.set('fechaFin', f.fechaFin || '');
        p.set('mesUnico', f.mesUnico || '');
        p.set('mesInicio', f.mesInicio || '');
        p.set('mesFin', f.mesFin || '');
    }

    function leerFiltrosFormulario() {
        const base = leerParametrosPeriodoDashboard();
        base.cencos = selCodes.join(',');
        return base;
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
        let html = '<table class="data-table config-table mdp-table w-full text-sm border-collapse"><thead><tr>';
        cols.forEach(function (c) {
            const cls = c.thClass ? ' class="' + c.thClass + '"' : '';
            html += '<th' + cls + '>' + escapeHtml(c.label) + '</th>';
        });
        html += '</tr></thead><tbody>';
        filas.forEach(function (f, idx) {
            const rowCls = f._rowClass ? ' class="' + f._rowClass + '"' : '';
            html += '<tr' + rowCls + '>';
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
            $('#mdp-tabla-resumen').html('<p class="mdp-empty">Sin registros en el periodo.</p>');
            return;
        }
        const rows = filas.map(function (r, idx) {
            const cantNum = Number(r.cantidad) || 0;
            const muertosNum = Number(r.muertos) || 0;
            return {
                numero: idx + 1,
                fecha: formatearFechaYMDslash(r.fecha),
                cencos: r.cencos,
                granja: r.granja,
                cantidad: formatearNumero(cantNum, 2),
                muertos: muertosNum > 0 ? formatearNumero(muertosNum) : '-',
                porcentaje: formatearNumero(r.porcentaje, 2) + '%',
                _rowClass: cantNum <= 0 ? 'mdp-fila-sin-despacho' : ''
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
        const inp = document.getElementById('mrt-dsp-granja-resumen');
        if (!inp) return;

        if (!selCodes.length) {
            inp.value = '';
            inp.placeholder = 'Clic para seleccionar';
            inp.title = 'Sin filtro: se incluyen todas las granjas';
            return;
        }

        if (selCodes.length === 1) {
            const cod = selCodes[0];
            const g = cod.slice(0, 3);
            const c = cod.slice(-3);
            let nom = '';
            const meta = (cfg.granjasMeta || []).find(function (m) { return m.granja === g; });
            if (meta) nom = meta.nombre || '';
            inp.value = g + (nom ? ' ' + nom : '') + ' - ' + c;
            inp.title = '1 campaña seleccionada — Clic para cambiar';
            return;
        }

        const granjas = Object.keys(campByGranja).length;
        inp.value = granjas + ' granja(s), ' + selCodes.length + ' campaña(s)';
        inp.title = selCodes.join(', ') + ' — Clic para cambiar';
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
        selCodes = [];
        campByGranja = {};
        syncVisibilidadPeriodo();
        syncModalPeriodoDesdeDashboard();
        syncGranjaDisplay();
    }

    function getCampaniasUrl(codes) {
        syncModalPeriodoDesdeDashboard();
        const list = (codes && codes.length)
            ? codes
            : (cfg.granjasMeta || []).map(function (g) { return g.granja || ''; }).filter(Boolean);
        if (!list.length) {
            return '';
        }
        const p = new URLSearchParams({ granjas: list.join(',') });
        appendPeriodoDashboard(p);
        const base = String(cfg.campaniasUrl || '');
        if (!base) {
            return '';
        }
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + p.toString();
    }

    function initMrtDspGmc() {
        if (!window.GmcGranjasCampanias || mrtDspGmc) {
            return;
        }
        mrtDspGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-dsp',
            shellPanelId: 'mrt-dsp-modal-granjas',
            mode: 'multi',
            requireGalpon: false,
            bodyOpenClass: 'mrt-dsp-modal-granjas-open',
            openTriggerId: 'mrt-dsp-granja-resumen',
            getMultiState: function () {
                return {
                    selCodes: selCodes.slice(),
                    campByGranja: JSON.parse(JSON.stringify(campByGranja)),
                    galponByGranja: {}
                };
            },
            setMultiState: function (st) {
                setMultiState(st);
            },
            granjasFetch: function () {
                return Promise.resolve(cfg.granjasMeta || []);
            },
            campaniasFetch: function (codes) {
                const url = getCampaniasUrl(codes);
                if (!url) {
                    return Promise.resolve({});
                }
                return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        return (j && j.by_granja) ? j.by_granja : {};
                    })
                    .catch(function () {
                        return {};
                    });
            },
            onApply: function (st) {
                setMultiState(st);
                syncGranjaDisplay();
            }
        });
    }

    $(function () {
        syncVisibilidadPeriodo();
        syncModalPeriodoDesdeDashboard();
        syncGranjaDisplay();
        initMrtDspGmc();

        $('#btnToggleFiltrosMdp').on('click', function () {
            $('#contenidoFiltrosMdp').slideToggle(200);
            $('#iconoFiltrosMdp').toggleClass('rotate-180');
        });

        $('#mdp-periodo-tipo').on('change', function () {
            syncVisibilidadPeriodo();
            syncModalPeriodoDesdeDashboard();
        });
        $('#mdp-fecha-unica, #mdp-fecha-inicio, #mdp-fecha-fin, #mdp-mes-unico, #mdp-mes-inicio, #mdp-mes-fin')
            .on('change', syncModalPeriodoDesdeDashboard);
        $('#mdp-btn-consultar').on('click', cargarAnalisis);
        $('#mdp-btn-limpiar').on('click', function () {
            limpiarFiltros();
        });

        cargarAnalisis();
    });
})();
