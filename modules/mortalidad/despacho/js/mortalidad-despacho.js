(function () {
    'use strict';

    const cfg = window.MORT_DESPACHO_CFG || {};
    let cargando = false;
    let mdpGmc = null;

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
            return 'Día ' + formatearFechaDMY(rango.desde);
        }
        return 'Del ' + formatearFechaDMY(rango.desde) + ' al ' + formatearFechaDMY(rango.hasta);
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
            granja: ($('#mdp-h-granja').val() || '').trim(),
            campania: ($('#mdp-h-campania').val() || '').trim()
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

    function renderTablaSimple(containerId, tituloId, titulo, filas, cols, totalRow) {
        $(tituloId).text(titulo);
        let html = '<table class="mdp-tabla w-full"><thead><tr>';
        cols.forEach(function (c) {
            html += '<th>' + escapeHtml(c.label) + '</th>';
        });
        html += '</tr></thead><tbody>';
        filas.forEach(function (f) {
            html += '<tr>';
            cols.forEach(function (c) {
                html += '<td>' + escapeHtml(c.render(f)) + '</td>';
            });
            html += '</tr>';
        });
        if (totalRow) {
            html += '<tr class="mdp-fila-total">';
            cols.forEach(function (c, idx) {
                html += '<td>' + escapeHtml(totalRow(c, idx)) + '</td>';
            });
            html += '</tr>';
        }
        html += '</tbody></table>';
        $(containerId).html(html);
    }

    function pintarCausas(data, titulo) {
        const filas = (data && data.filas) || [];
        const total = (data && data.total) || 0;
        renderTablaSimple(
            '#mdp-tabla-causas',
            '#mdp-titulo-causas',
            titulo,
            filas,
            [
                { label: 'Causas', render: function (f) { return f.causa; } },
                { label: 'Cant. mort.', render: function (f) { return formatearNumero(f.cantidad); } },
                {
                    label: '%',
                    render: function (f) {
                        return formatearNumero(f.porcentaje) + '%';
                    }
                }
            ],
            function (c, idx) {
                if (idx === 0) return 'Total';
                if (idx === 1) return formatearNumero(total);
                return '100%';
            }
        );
    }

    function pintarEtapas(data, titulo) {
        const filas = (data && data.filas) || [];
        const total = (data && data.total) || 0;
        renderTablaSimple(
            '#mdp-tabla-etapas',
            '#mdp-titulo-etapas',
            titulo,
            filas,
            [
                { label: 'Etapa', render: function (f) { return f.etapa; } },
                { label: 'Cant. mort.', render: function (f) { return formatearNumero(f.cantidad); } },
                {
                    label: '%',
                    render: function (f) {
                        return formatearNumero(f.porcentaje) + '%';
                    }
                }
            ],
            function (c, idx) {
                if (idx === 0) return 'Total';
                if (idx === 1) return formatearNumero(total);
                return '100%';
            }
        );
    }

    function pintarResumen(filas) {
        let html = '<table class="mdp-tabla w-full"><thead><tr>';
        html += '<th>Fecha</th><th>N°</th><th>Cencos</th><th>Granja</th>';
        html += '<th class="text-right">Cantidad</th><th class="text-right">Muertos</th>';
        html += '<th class="text-right">% Mort. Despacho</th></tr></thead><tbody>';
        if (!filas || filas.length === 0) {
            html += '<tr><td colspan="7" class="text-center text-gray-500 py-6">Sin registros de despacho en el periodo.</td></tr>';
        } else {
            filas.forEach(function (r) {
                html += '<tr>';
                html += '<td>' + escapeHtml(formatearFechaYMDslash(r.fecha)) + '</td>';
                html += '<td>' + escapeHtml(r.numero) + '</td>';
                html += '<td>' + escapeHtml(r.cencos) + '</td>';
                html += '<td>' + escapeHtml(r.granja) + '</td>';
                html += '<td class="text-right">' + escapeHtml(formatearNumero(r.cantidad, 2)) + '</td>';
                html += '<td class="text-right">' + escapeHtml(r.muertos > 0 ? formatearNumero(r.muertos) : '-') + '</td>';
                html += '<td class="text-right">' + escapeHtml(formatearNumero(r.porcentaje, 2)) + '%</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table>';
        $('#mdp-tabla-resumen').html(html);
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
                const titulo = tituloPeriodo(j.rango);
                pintarCausas(j.causas, 'Mortalidad por causa — ' + titulo);
                pintarEtapas(j.etapas, 'Mortalidad por etapa del proceso — ' + titulo);
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
        const g = ($('#mdp-h-granja').val() || '').trim();
        const c = ($('#mdp-h-campania').val() || '').trim();
        const inp = document.getElementById('mdp-granja-resumen');
        if (!inp) return;
        if (g && c) {
            let nom = '';
            const meta = (cfg.granjasMeta || []).find(function (m) { return m.granja === g; });
            if (meta) nom = meta.nombre || meta.nombre_granja || '';
            inp.value = g + (nom ? ' ' + nom : '') + ' - ' + c;
            inp.title = 'Granja ' + g + ', campaña ' + c;
        } else if (g) {
            inp.value = g;
            inp.title = 'Granja ' + g + ' (todas las campañas del filtro)';
        } else {
            inp.value = '';
            inp.placeholder = 'Todas las granjas';
            inp.title = 'Consolidado de todas las granjas';
        }
    }

    function getCampaniasUrl() {
        var f = leerFiltrosFormulario();
        var p = new URLSearchParams({
            periodoTipo: f.periodoTipo || 'POR_FECHA',
            fechaUnica: f.fechaUnica || '',
            fechaInicio: f.fechaInicio || '',
            fechaFin: f.fechaFin || '',
            mesUnico: f.mesUnico || '',
            mesInicio: f.mesInicio || '',
            mesFin: f.mesFin || ''
        });
        var base = String(cfg.apiUrl || '');
        var parentDir = base.substring(0, base.lastIndexOf('/'));
        parentDir = parentDir.substring(0, parentDir.lastIndexOf('/'));
        return parentDir + '/get_campanias_mortalidad.php?' + p.toString();
    }

    function initMdpGmc() {
        if (!window.GmcGranjasCampanias || mdpGmc) {
            return;
        }
        mdpGmc = window.GmcGranjasCampanias.create({
            prefix: 'mdp',
            shellPanelId: 'mdp-modal-granjas',
            mode: 'single',
            bodyOpenClass: 'mdp-modal-granjas-open',
            openTriggerId: 'mdp-granja-resumen',
            getCodigoSeis: function () {
                var g = ($('#mdp-h-granja').val() || '').trim();
                var c = ($('#mdp-h-campania').val() || '').trim();
                return g && c ? g + c : '';
            },
            setCodigoSeis: function (cod) {
                if (cod && cod.length >= 6) {
                    $('#mdp-h-granja').val(cod.slice(0, 3));
                    $('#mdp-h-campania').val(cod.slice(-3));
                } else {
                    $('#mdp-h-granja, #mdp-h-campania').val('');
                }
                syncGranjaDisplay();
            },
            cencosFetch: function () {
                var url = getCampaniasUrl();
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
        initMdpGmc();

        $('#mdp-periodo-tipo').on('change', syncVisibilidadPeriodo);
        $('#mdp-btn-consultar').on('click', cargarAnalisis);
        $('#mdp-btn-limpiar-granja').on('click', function () {
            $('#mdp-h-granja, #mdp-h-campania').val('');
            syncGranjaDisplay();
        });

        cargarAnalisis();
    });
})();
