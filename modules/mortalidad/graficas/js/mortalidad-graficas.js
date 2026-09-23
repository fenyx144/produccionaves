(function () {
    'use strict';

    /* ═══════════════════════════════════════════════════════════════
       Configuración y estado
       ═══════════════════════════════════════════════════════════════ */
    var cfg = window.MORT_GRAFICAS_CFG || {};

    var COLORES = {
        incubacion: {
            label: 'Planta de incubación',
            border: '#FFB300',
            bg: 'rgba(255, 179, 0, 0.25)',
            bgHover: 'rgba(255, 179, 0, 0.45)'
        },
        transporte: {
            label: 'Transporte',
            border: '#42A5F5',
            bg: 'rgba(66, 165, 245, 0.25)',
            bgHover: 'rgba(66, 165, 245, 0.45)'
        },
        produccion: {
            label: 'Producción',
            border: '#66BB6A',
            bg: 'rgba(102, 187, 106, 0.30)',
            bgHover: 'rgba(102, 187, 106, 0.50)'
        },
        despacho: {
            label: 'Despacho',
            border: '#FF7043',
            bg: 'rgba(255, 112, 67, 0.25)',
            bgHover: 'rgba(255, 112, 67, 0.45)'
        },
        venta: {
            label: 'Venta',
            border: '#ec4899',
            bg: 'rgba(236, 72, 153, 0.20)',
            bgHover: 'rgba(236, 72, 153, 0.38)'
        }
    };

    // Tipos de mortalidad que se dibujan como barras separadas (por tipo),
    // sin la categoría "Otros" (el usuario pidió retirarla de leyenda y barras).
    var TIPOS_MORT = ['incubacion', 'transporte', 'produccion', 'despacho'];

    var mrtGrfGmc = null;
    var charts = [];                    // Chart.js activos (uno por lote, por índice)
    var lotesActuales = [];             // lotes renderizados (granja/campaña/galpones/sexo)

    // Estado de selección multi (granja → campañas y galpones)
    var selCodes = [];                  // granjas seleccionadas (3 dígitos)
    var campByGranja = {};              // granja3 -> [campania3, ...]
    var galponByGranja = {};            // granja3 -> [galpon, ...]
    var galponesPorGranja = {};         // granja3 -> [galpon, ...] (catálogo del modal)

    /* ═══════════════════════════════════════════════════════════════
       Utilidades
       ═══════════════════════════════════════════════════════════════ */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtNum(v) {
        return Number(v || 0).toLocaleString('es-PE');
    }

    function fmtCompact(v) {
        var n = Number(v || 0);
        if (Math.abs(n) >= 1000) {
            return (n / 1000).toFixed(n >= 100000 ? 0 : 1) + 'k';
        }
        return String(n);
    }

    function normGranja3(v) {
        var s = String(v == null ? '' : v).trim();
        if (!s) return '';
        while (s.length < 3) s = '0' + s;
        return s.slice(0, 3);
    }

    function nombreGranja(g3) {
        var meta = (cfg.granjasMeta || []).find(function (m) { return normGranja3(m.granja) === g3; });
        return meta && meta.nombre ? meta.nombre : '';
    }

    /* ═══════════════════════════════════════════════════════════════
       Visibilidad del periodo (mismo patrón que las otras secciones)
       ═══════════════════════════════════════════════════════════════ */
    function aplicarVisibilidadPeriodo() {
        var t = $('#periodoTipo').val() || 'TODOS';
        $('#periodoPorFecha, #periodoEntreFechas, #periodoPorMes, #periodoEntreMeses').addClass('hidden');
        if (t === 'POR_FECHA') $('#periodoPorFecha').removeClass('hidden');
        else if (t === 'ENTRE_FECHAS') $('#periodoEntreFechas').removeClass('hidden');
        else if (t === 'POR_MES') $('#periodoPorMes').removeClass('hidden');
        else if (t === 'ENTRE_MESES') $('#periodoEntreMeses').removeClass('hidden');
    }

    /* ═══════════════════════════════════════════════════════════════
       Modal multi (granja + campaña + galpón)
       ═══════════════════════════════════════════════════════════════ */
    function resumenLineaGranja(g3) {
        var nom = nombreGranja(g3);
        var camps = (campByGranja[g3] || []).slice().sort().join(',');
        var galps = (galponByGranja[g3] || []).slice().sort(function (a, b) { return Number(a) - Number(b); }).join(',');
        var txt = g3 + (nom ? ' ' + nom : '');
        if (camps) txt += ' · C:' + camps;
        if (galps) txt += ' · G:' + galps;
        return txt;
    }

    function syncSelDisplay() {
        var inp = $('#mrt-grf-granja-resumen');
        if (!inp.length) return;
        if (!selCodes.length) {
            inp.val('');
            inp.attr('placeholder', 'Clic para seleccionar (puedes elegir varias)');
            return;
        }
        if (selCodes.length === 1) {
            inp.val(resumenLineaGranja(selCodes[0]));
        } else {
            var partes = selCodes.slice(0, 4).map(resumenLineaGranja);
            inp.val('Selección: ' + partes.join('; ') + (selCodes.length > 4 ? ' …' : ''));
        }
    }

    function getCampaniasUrl(codes) {
        var p = new URLSearchParams({ granjas: (codes || []).join(',') });
        if (window.GmcPeriodoCampanias) {
            // El periodo interno del modal (Desde/Hasta) filtra las campañas a listar.
            window.GmcPeriodoCampanias.appendToSearchParams(p, 'mrt-grf');
        } else {
            p.set('periodoTipo', 'TODOS');
        }
        return cfg.campaniasUrl + '?' + p.toString();
    }

    function initGmc() {
        if (!window.GmcGranjasCampanias) {
            console.warn('MORT-GRF: GmcGranjasCampanias no disponible');
            return;
        }
        if (mrtGrfGmc) return;
        mrtGrfGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-grf',
            shellPanelId: 'mrt-grf-modal-granjas',
            mode: 'multi',
            galponesCollapsible: true,
            bodyOpenClass: 'mrt-grf-modal-granjas-open',
            openTriggerId: 'mrt-grf-granja-resumen',
            requireGalpon: true,
            getMultiState: function () {
                return {
                    selCodes: selCodes.slice(),
                    campByGranja: JSON.parse(JSON.stringify(campByGranja)),
                    galponByGranja: JSON.parse(JSON.stringify(galponByGranja))
                };
            },
            setMultiState: function (st) {
                selCodes = (st && st.selCodes) ? st.selCodes.slice() : [];
                campByGranja = (st && st.campByGranja) ? JSON.parse(JSON.stringify(st.campByGranja)) : {};
                galponByGranja = (st && st.galponByGranja) ? JSON.parse(JSON.stringify(st.galponByGranja)) : {};
            },
            granjasFetch: function () {
                return Promise.resolve(cfg.granjasMeta || []);
            },
            campaniasFetch: function (codes) {
                var url = getCampaniasUrl(codes);
                return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        var out = (j && j.by_granja) ? j.by_granja : {};
                        if (j && j.galpones_por_granja) {
                            galponesPorGranja = j.galpones_por_granja || {};
                            out.galpones_por_granja = galponesPorGranja;
                        }
                        return out;
                    })
                    .catch(function () {
                        return { galpones_por_granja: {} };
                    });
            },
            onApply: function (st) {
                setMultiState(st);
                syncSelDisplay();
                buscarDatos();
            }
        });
    }

    function setMultiState(st) {
        selCodes = (st && st.selCodes) ? st.selCodes.slice() : [];
        campByGranja = (st && st.campByGranja) ? JSON.parse(JSON.stringify(st.campByGranja)) : {};
        galponByGranja = (st && st.galponByGranja) ? JSON.parse(JSON.stringify(st.galponByGranja)) : {};
    }

    /* ═══════════════════════════════════════════════════════════════
       Filtros y fetch
       ═══════════════════════════════════════════════════════════════ */
    function leerFiltros() {
        return {
            periodoTipo: ($('#periodoTipo').val() || 'TODOS').trim(),
            fechaUnica: ($('#fechaUnica').val() || '').trim(),
            fechaInicio: ($('#fechaInicio').val() || '').trim(),
            fechaFin: ($('#fechaFin').val() || '').trim(),
            mesUnico: ($('#mesUnico').val() || '').trim(),
            mesInicio: ($('#mesInicio').val() || '').trim(),
            mesFin: ($('#mesFin').val() || '').trim(),
            operacion: ($('#filtroOperacion').val() || 'todos').trim(),
            sexo: ($('#filtroSexo').val() || 'todos').trim()
        };
    }

    /** Expande la selección multi en una lista de lotes (granja+campaña+galpón). */
    function construirLotes() {
        var f = leerFiltros();
        // El select global de sexo es el valor por defecto de todas las gráficas;
        // luego cada tarjeta puede cambiarse individualmente.
        var sexoGlobal = f.sexo || 'todos';
        var lotes = [];
        Object.keys(campByGranja).sort().forEach(function (g) {
            var camps = (campByGranja[g] || []).slice().sort();
            var galps = (galponByGranja[g] || []).slice().sort(function (a, b) { return Number(a) - Number(b); });

            camps.forEach(function (c) {
                if (galps.length) {
                    // Una gráfica por galpón seleccionado (aunque sean todos).
                    galps.forEach(function (gp) {
                        lotes.push({ granja: g, campania: c, galpones: [gp], sexo: sexoGlobal });
                    });
                } else {
                    // Sin galpones seleccionados: lote combinado (fallback).
                    lotes.push({ granja: g, campania: c, galpones: [], sexo: sexoGlobal });
                }
            });
        });
        return lotes;
    }

    function buscarDatos() {
        var lotes = construirLotes();
        if (!lotes.length) {
            Swal.fire('Aviso', 'Selecciona al menos una granja, campaña y galpón.', 'info');
            return;
        }

        $('#lotesGraficas').empty()
            .append('<div class="xl:col-span-2"><div class="grafica-card"><div class="grafica-body"><div class="grafica-wrap" style="height:120px;"><div class="grafica-loading"><div class="spinner-ring"></div></div></div></div></div></div>');

        var f = leerFiltros();
        var p = new URLSearchParams(f);
        p.set('lotes', JSON.stringify(lotes));

        fetch(cfg.datosUrl + '?' + p.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) {
                    $('#lotesGraficas').empty();
                    mostrarSinLotes();
                    Swal.fire('Error', (j && j.message) || 'No se pudieron cargar los datos.', 'error');
                    return;
                }
                renderLotes(j.lotes || [], f.operacion);
            })
            .catch(function () {
                $('#lotesGraficas').empty();
                mostrarSinLotes();
                Swal.fire('Error', 'Error de conexión al cargar los datos.', 'error');
            });
    }

    function mostrarSinLotes() {
        $('#lotesGraficas').html(
            '<div class="xl:col-span-2"><div class="grafica-card">' +
            '<div class="grafica-empty" style="position: static; padding: 3rem 1.5rem;">' +
            '<i class="fas fa-chart-line"></i>' +
            '<span>Selecciona al menos una granja, campaña y galpón, luego presiona <b>Graficar</b>.</span>' +
            '</div></div></div>'
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       Render: una tarjeta + gráfica por lote
       ═══════════════════════════════════════════════════════════════ */
    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';
    /* ── Plugin: etiqueta sobre las barras (mortalidad) rotada 90° ──
       Con las barras separadas por tipo:
        - Cada barra > 0 muestra su valor sobre sí misma (rotado -90°).
        - Cuando en el día hay venta (S700), en la parte superior del grupo
          se dibuja la división "mortalidad del día/venta" en fucsia.
    */
    var pluginValorBarra = {
        id: 'mortValorBarra',
        afterDatasetsDraw: function (chart) {
            var metasBar = []; // metas de datasets tipo bar (una por tipo de mortalidad)
            var idxVenta = -1;
            chart.data.datasets.forEach(function (ds, i) {
                var m = chart.getDatasetMeta(i);
                if (ds.type === 'line' || (m && m.type === 'line')) {
                    idxVenta = i;
                } else if (m && m.type === 'bar') {
                    metasBar.push(m);
                }
            });
            if (!metasBar.length) {
                return;
            }
            var ctx = chart.ctx;
            var n = chart.data.labels.length;
            var pasoX = n > 1 ? (chart.chartArea.right - chart.chartArea.left) / (n - 1) : 1e9;
            ctx.save();
            ctx.font = '700 9px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            for (var i = 0; i < n; i++) {
                // Barras del día con valor > 0.
                var barras = [];
                metasBar.forEach(function (m) {
                    var bar = m.data[i];
                    if (!bar) {
                        return;
                    }
                    var ds = chart.data.datasets[m.index];
                    var v = Number(ds.data[i]) || 0;
                    if (v <= 0) {
                        return;
                    }
                    barras.push({ x: bar.x, y: bar.y, valor: v });
                });
                if (!barras.length) {
                    continue;
                }

                var venta = idxVenta >= 0 ? (Number(chart.data.datasets[idxVenta].data[i]) || 0) : 0;
                // Mortalidad total del día (numerador de la división mortalidad/venta).
                var totalMort = 0;
                barras.forEach(function (b) { totalMort += b.valor; });

                // Día con división: hay venta y mortalidad ese día.
                var conDivision = venta > 0 && totalMort > 0;
                // Día denso (muchas categorías): solo se rotulan las barras
                // cuando hay espacio, para no saturar la gráfica.
                var denso = pasoX < 24 && barras.length > 1;

                // Día con división "mortalidad/venta".
                if (conDivision) {
                    // Sobre la barra más alta del día: fucsia y con la misma
                    // rotación -90° (lectura ascendente).
                    var topeY = Infinity;
                    var xDiv = barras[0].x;
                    barras.forEach(function (b) {
                        if (b.y < topeY) {
                            topeY = b.y;
                            xDiv = b.x;
                        }
                    });
                    ctx.fillStyle = 'rgba(236, 72, 153, 0.98)';
                    ctx.save();
                    ctx.translate(xDiv, Math.max(topeY - 16, 4));
                    ctx.rotate(-Math.PI / 2); // -90°: lectura ascendente
                    ctx.fillText(totalMort + '/' + venta, 0, 0);
                    ctx.restore();
                    continue;
                }

                // Sin división: valor sobre cada barra (o solo la más alta si es denso).
                if (!denso) {
                    barras.forEach(function (b) {
                        ctx.fillStyle = 'rgba(30, 41, 59, 0.92)';
                        ctx.save();
                        ctx.translate(b.x, b.y - 10);
                        ctx.rotate(-Math.PI / 2); // -90°: lectura ascendente
                        ctx.fillText(String(b.valor), 0, 0);
                        ctx.restore();
                    });
                } else {
                    // Denso y sin división: rotular solo la barra más alta.
                    var max = barras[0];
                    barras.forEach(function (b) {
                        if (b.y < max.y) {
                            max = b;
                        }
                    });
                    ctx.fillStyle = 'rgba(30, 41, 59, 0.92)';
                    ctx.save();
                    ctx.translate(max.x, max.y - 10);
                    ctx.rotate(-Math.PI / 2);
                    ctx.fillText(String(max.valor), 0, 0);
                    ctx.restore();
                }
            }
            ctx.restore();
        }
    };
    Chart.register(pluginValorBarra);

    function tooltipEstilo() {
        return {
            backgroundColor: 'rgba(15, 23, 42, 0.92)',
            titleColor: '#e2e8f0',
            bodyColor: '#f1f5f9',
            padding: 12,
            cornerRadius: 10,
            displayColors: true,
            boxPadding: 4,
            usePointStyle: true
        };
    }

    function destruirCharts() {
        charts.forEach(function (c) { if (c) c.destroy(); });
        charts = [];
    }

    function tituloLote(lote) {
        var g3 = normGranja3(lote.granja);
        var nom = nombreGranja(g3);
        var galps = lote.galpones || [];
        var t = 'Granja ' + g3 + (nom ? ' ' + nom : '') + ' · Campaña ' + lote.campania;
        if (galps.length === 1) {
            t += ' · Galpón ' + galps[0];
        } else if (galps.length > 1) {
            t += ' · Galpones ' + galps.join(', ');
        } else {
            t += ' · Todos los galpones';
        }
        return t;
    }

    /** Etiqueta de sexo para mostrar en el título. */
    function labelSexo(sexo) {
        if (sexo === 'macho') return 'Machos';
        if (sexo === 'hembra') return 'Hembras';
        return '';
    }

    /** HTML de la tarjeta de un lote (título + select sexo + cuerpo con canvas). */
    function htmlTarjetaLote(lote, idx) {
        var cardId = 'lote-card-' + idx;
        var canvasId = 'lote-canvas-' + idx;
        var emptyId = 'lote-empty-' + idx;
        var sexo = String(lote.sexo || 'todos');
        var lblSexo = labelSexo(sexo);
        var subHtml = '';
        if (lblSexo !== '') {
            subHtml = 'Sexo: <b>' + esc(lblSexo) + '</b>';
        }

        return '' +
            '<div class="grafica-card" id="' + cardId + '"' +
            ' data-granja="' + esc(lote.granja) + '"' +
            ' data-campania="' + esc(lote.campania) + '"' +
            ' data-galpones="' + esc(JSON.stringify(lote.galpones || [])) + '"' +
            ' data-idx="' + idx + '">' +
            '  <div class="grafica-head">' +
            '    <div class="grafica-titulo"><i class="fas fa-chart-bar"></i> ' + esc(tituloLote(lote)) + '</div>' +
            '    <div class="grafica-sexo">' +
            '      <label for="' + canvasId + '-sexo">Sexo</label>' +
            '      <select id="' + canvasId + '-sexo" class="lote-sexo-select" data-idx="' + idx + '">' +
            '        <option value="todos"' + (sexo === 'todos' ? ' selected' : '') + '>Todos</option>' +
            '        <option value="macho"' + (sexo === 'macho' ? ' selected' : '') + '>Machos</option>' +
            '        <option value="hembra"' + (sexo === 'hembra' ? ' selected' : '') + '>Hembras</option>' +
            '      </select>' +
            '    </div>' +
            '  </div>' +
            (subHtml !== '' ? '  <div class="grafica-sub">' + subHtml + '</div>' : '') +
            '  <div class="grafica-body">' +
            '    <div class="grafica-wrap">' +
            '      <canvas id="' + canvasId + '" aria-label="Gráfico mortalidad vs venta ' + (idx + 1) + '"></canvas>' +
            '      <div class="grafica-empty lay-hidden" id="' + emptyId + '">' +
            '        <i class="fas fa-chart-bar"></i>' +
            '        <span>Sin datos para este lote</span>' +
            '      </div>' +
            '    </div>' +
            '  </div>' +
            '</div>';
    }

    /** Crea la gráfica de una tarjeta ya insertada en el DOM. */
    function crearChartTarjeta(lote, idx, operacion) {
        var puntos = lote.puntos || [];
        var tieneDatos = puntos.some(function (p) {
            return (Number(p.mortalidad) || 0) > 0 || (Number(p.venta) || 0) > 0;
        });
        var emptyId = 'lote-empty-' + idx;
        if (!tieneDatos) {
            $('#' + emptyId).removeClass('lay-hidden');
            return;
        }

        var labels = puntos.map(function (p) { return 'D' + p.dia; });
        var venta = puntos.map(function (p) { return Number(p.venta) || 0; });

        var datasets = [];
        if (operacion === 'todos' || operacion === 'mortalidad') {
            // Barras SEPARADAS por tipo de mortalidad (S808): si en un día
            // hay despacho y producción a la vez se dibujan dos barras.
            // Los días sin datos van en null + skipNull: así solo los tipos
            // que realmente tienen mortalidad ese día ocupan espacio y la
            // barra sale gruesa. Todos los tipos siguen en la leyenda.
            TIPOS_MORT.forEach(function (tipo) {
                var key = 'm' + tipo.charAt(0).toUpperCase() + tipo.slice(1);
                var data = puntos.map(function (p) {
                    var v = Number(p[key]) || 0;
                    return v > 0 ? v : null;
                });
                datasets.push(datasetBar(COLORES[tipo].label, COLORES[tipo], data, tipo));
            });
        }
        if (operacion === 'todos' || operacion === 'venta') {
            datasets.push(datasetLineaPuntos('Venta', COLORES.venta, venta));
        }

        // Padding superior extra si algún día lleva división mortalidad/venta.
        var hayDivision = puntos.some(function (p) {
            return (Number(p.venta) || 0) > 0 && (Number(p.mortalidad) || 0) > 0;
        });

        var canvasId = 'lote-canvas-' + idx;
        var ctx = $('#' + canvasId)[0].getContext('2d');
        var chart = new Chart(ctx, {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: hayDivision ? 58 : 26 } }, // espacio para etiquetas sobre barras altas
                animation: { duration: 1100, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { usePointStyle: true, boxWidth: 8, padding: 18 } },
                    tooltip: Object.assign(tooltipEstilo(), {
                        callbacks: {
                            title: function (items) {
                                if (!items.length) return '';
                                var i = items[0].dataIndex;
                                var p = puntos[i] || {};
                                return 'Día ' + p.dia + (p.fecha ? ' · ' + esc(String(p.fecha).slice(0, 10)) : '');
                            },
                            label: function (ctx2) {
                                var v = Number(ctx2.raw) || 0;
                                if (v <= 0) {
                                    return null; // ocultar entradas con 0
                                }
                                return ctx2.dataset.label + ': ' + fmtNum(v);
                            }
                        }
                    })
                },
                scales: {
                    x: {
                        grid: { display: false },
                        stacked: false,
                        ticks: { minRotation: 90, maxRotation: 90, autoSkip: true, maxTicksLimit: 60 },
                        title: { display: true, text: 'Día de campaña', color: '#94a3b8', font: { weight: '600', size: 10 } }
                    },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        stacked: false,
                        title: { display: true, text: 'Mortalidad (aves)', color: '#475569', font: { weight: '600', size: 11 } },
                        grid: { color: 'rgba(148, 163, 184, 0.16)' },
                        border: { display: false },
                        ticks: { callback: function (v) { return fmtCompact(v); } }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: { display: true, text: 'Venta (aves)', color: '#ec4899', font: { weight: '600', size: 11 } },
                        grid: { drawOnChartArea: false },
                        border: { display: false },
                        ticks: { callback: function (v) { return fmtCompact(v); } }
                    }
                }
            }
        });
        charts[idx] = chart;
    }

    /** Inserta la tarjeta de un lote (append o reemplazo) y dibuja su gráfica. */
    function insertarTarjetaLote(lote, idx, operacion) {
        var $card = $(htmlTarjetaLote(lote, idx));
        var $existente = $('#lote-card-' + idx);
        if ($existente.length) {
            $existente.replaceWith($card);
        } else {
            $('#lotesGraficas').append($card);
        }
        crearChartTarjeta(lote, idx, operacion);
    }

    function renderLotes(lotes, operacion) {
        destruirCharts();
        lotesActuales = lotes.slice();
        var $host = $('#lotesGraficas');
        $host.empty();

        if (!lotes.length) {
            mostrarSinLotes();
            return;
        }

        lotes.forEach(function (lote, idx) {
            insertarTarjetaLote(lote, idx, operacion);
        });
    }

    function datasetBar(label, color, data, tipo) {
        // La mortalidad por Despacho suele tener valores muy menores a los de
        // Producción; con minBarLength su barra conserva una altura mínima
        // (px) para que no se vea como una línea casi invisible en la escala.
        // Los demás tipos usan un mínimo pequeño solo para no desaparecer.
        var esDespacho = tipo === 'despacho';
        return {
            label: label,
            data: data,
            yAxisID: 'y',
            tipo: tipo || '',
            skipNull: true, // días sin dato (null) no dibujan barra ni reservan espacio
            backgroundColor: color.bg,
            borderColor: color.border,
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: false,
            hoverBackgroundColor: color.bgHover,
            maxBarThickness: 38,
            minBarLength: esDespacho ? 20 : 6,
            categoryPercentage: 0.85,
            barPercentage: 0.92
        };
    }

    /** Línea con puntos (eje derecho): venta S700. */
    function datasetLineaPuntos(label, color, data) {
        return {
            type: 'line',
            label: label,
            data: data,
            yAxisID: 'y1',
            borderColor: color.border,
            borderWidth: 2.5,
            backgroundColor: color.bg,
            fill: false,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: color.border,
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointHoverRadius: 7,
            pointHoverBackgroundColor: color.border,
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 2.5
        };
    }

    /* ═══════════════════════════════════════════════════════════════
       Eventos
       ═══════════════════════════════════════════════════════════════ */
    function bindEventos() {
        $('#btnToggleFiltrosGrf').on('click', function () {
            $('#contenidoFiltrosGrf').toggleClass('hidden');
            $('#iconoFiltrosGrf').toggleClass('rotate-180');
        });

        $('#periodoTipo').on('change', aplicarVisibilidadPeriodo);

        $('#btnFiltrarGrf').on('click', function () { buscarDatos(); });

        // Cambio de sexo en una tarjeta: recarga solo esa gráfica.
        $('#lotesGraficas').on('change', '.lote-sexo-select', function () {
            var idx = Number($(this).data('idx'));
            var sexo = String($(this).val() || 'todos');
            recargarTarjetaSexo(idx, sexo);
        });

        // Cambio del select global de sexo: se aplica a todas las tarjetas
        // visibles (cada una conserva su propio select para cambios puntuales).
        $('#filtroSexo').on('change', function () {
            var sexo = String($(this).val() || 'todos');
            if (!lotesActuales.length) {
                return; // aún no hay gráficas; se aplicará al graficar
            }
            for (var i = 0; i < lotesActuales.length; i++) {
                recargarTarjetaSexo(i, sexo);
            }
        });

        $('#btnLimpiarGrf').on('click', function () {
            setMultiState({ selCodes: [], campByGranja: {}, galponByGranja: {} });
            syncSelDisplay();
            $('#filtroOperacion').val('todos');
            $('#filtroSexo').val('todos');
            $('#periodoTipo').val('TODOS');
            $('#fechaInicio').val(new Date().getFullYear() + '-01-01');
            $('#fechaFin').val(new Date().toISOString().slice(0, 10));
            aplicarVisibilidadPeriodo();
            destruirCharts();
            lotesActuales = [];
            $('#lotesGraficas').empty();
            mostrarSinLotes();
        });
    }

    /** Recarga la tarjeta (lote) idx aplicando un filtro de sexo. */
    function recargarTarjetaSexo(idx, sexo) {
        var $card = $('#lote-card-' + idx);
        if (!$card.length) {
            return;
        }
        var lote = {
            granja: String($card.data('granja') || ''),
            campania: String($card.data('campania') || ''),
            galpones: $card.data('galpones') || [],
            sexo: sexo
        };
        if (charts[idx]) {
            try { charts[idx].destroy(); } catch (e) { /* noop */ }
            charts[idx] = null;
        }
        // Spinner de carga dentro de la tarjeta.
        var $body = $card.find('.grafica-body');
        $body.empty().append(
            '<div class="grafica-wrap" style="height:340px;">' +
            '<div class="grafica-loading"><div class="spinner-ring"></div></div>' +
            '</div>'
        );

        var f = leerFiltros();
        var p = new URLSearchParams(f);
        p.set('lotes', JSON.stringify([lote]));

        fetch(cfg.datosUrl + '?' + p.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false || !j.lotes || !j.lotes.length) {
                    insertarTarjetaLote(lote, idx, f.operacion || 'todos');
                    return;
                }
                insertarTarjetaLote(j.lotes[0], idx, f.operacion || 'todos');
            })
            .catch(function () {
                insertarTarjetaLote(lote, idx, f.operacion || 'todos');
            });
    }

    /* ═══════════════════════════════════════════════════════════════
       Init
       ═══════════════════════════════════════════════════════════════ */
    function init() {
        aplicarVisibilidadPeriodo();
        bindEventos();
        initGmc();
        syncSelDisplay();
    }

    $(function () {
        init();
    });
})();
