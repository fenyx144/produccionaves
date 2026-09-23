/**
 * Gráficas horizontales (acumulado semanal) — módulo mortalidad.
 * Port de la pestaña "Gráficos Horizontales" de interconsultas (sanidad):
 * tarjetas por tipo, todas las granjas/campañas/galpones en el eje X,
 * series Macho/Hembra/Combinado + Estándar + CV.
 */
(function () {
    'use strict';

    var cfg = window.GRAF_HZ_CFG || {};

    var TIPOS_PESAJE = ['pesaje_pollo', 'ganancia_peso'];
    var TIPOS_LIQUIDACION = ['iep_2_4', 'ica_2_4'];
    var TIPOS_SEMANA = ['mortalidad'];

    var NIVELES_ESCALA_TEXTO = [0.80, 0.90, 1.00, 1.15, 1.30, 1.45];
    var indiceEscalaTexto = 2;

    var FUENTE_GRAF = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    var instanciasGraficosHz = {};
    var cachePorTipoHz = {}; // tipoKey => { data, opcionesFiltro }
    var cachePorCanvasHz = {}; // canvasId => { chartInstance, registrosFiltrados, canvasWrapperId }

    var msUbicacion = null;
    var msTipos = null;
    var msDias = null;
    var diasPorTipoCache = {}; // tipo => [{dia, semana}]

    var modoCombinado = 'ninguno';
    var separarGraficos = false;

    /* ═══════════════════════════════════════════════════════════════
       Utilidades
       ═══════════════════════════════════════════════════════════════ */
    function el(id) {
        return document.getElementById(id);
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function toast(msg, tipo) {
        if (window.Swal) {
            Swal.fire({
                icon: tipo || 'info',
                title: msg,
                toast: true,
                position: 'top-end',
                timer: 3200,
                showConfirmButton: false
            });
        } else {
            console.warn('[GRAF-HZ]', msg);
        }
    }

    function getEscalaTextoActual() {
        return NIVELES_ESCALA_TEXTO[indiceEscalaTexto] || 1;
    }

    function getTamanoFuente(base) {
        return Math.round(base * getEscalaTextoActual() * 10) / 10;
    }

    function getColWidthPorLote() {
        var escala = getEscalaTextoActual();
        return Math.round(28 * Math.max(1, escala * 1.05));
    }

    function getDecimalesPorTipo(tipo) {
        if (TIPOS_LIQUIDACION.indexOf(tipo) !== -1) {
            return 3;
        }
        if (tipo === 'pesaje_pollo' || tipo === 'ganancia_peso') {
            return 3;
        }
        return 2;
    }

    function esTipoLiquidacion(tipo) {
        return TIPOS_LIQUIDACION.indexOf(tipo) !== -1;
    }

    function esTipoSemana(tipo) {
        return TIPOS_SEMANA.indexOf(tipo) !== -1;
    }

    function esUnidadPorcentual(tipo, data) {
        var u = String((data && data.unidad) || '').trim();
        if (u.indexOf('%') !== -1) return true;
        return tipo === 'mortalidad';
    }

    function fmtValorGrafica(val, tipo, data, dec) {
        if (val === null || val === undefined || isNaN(val)) return '';
        var txt = Number(val).toFixed(dec);
        if (esUnidadPorcentual(tipo, data)) {
            return txt + '%';
        }
        var unidad = String((data && data.unidad) || '').trim();
        return unidad ? txt + ' ' + unidad : txt;
    }

    function soportaVarPctHz(tipo) {
        var SGS = window.SipGraficaShared;
        return !!(SGS && SGS.soportaVarPct(tipo, { graficoActivo: 'linea' }));
    }

    function tipoIncluyeCvEnGrafico(tipo) {
        return tipo !== 'pesaje_pollo' && tipo !== 'ganancia_peso';
    }

    function evalCumpleHz(tipo, actual, std) {
        var SGS = window.SipGraficaShared;
        var modo = (tipo === 'pesaje_pollo' || tipo === 'ganancia_peso') ? 'solo_min' : 'solo_max';
        return SGS ? SGS.evalCumpleModo(actual, std, modo) : null;
    }

    function destruirChartHzPct(canvasIdPct) {
        if (instanciasGraficosHz[canvasIdPct]) {
            try {
                instanciasGraficosHz[canvasIdPct].destroy();
            } catch (e) { /* sinop */ }
            delete instanciasGraficosHz[canvasIdPct];
        }
        var SGS = window.SipGraficaShared;
        if (SGS && typeof SGS.destroyPctChart === 'function') {
            SGS.destroyPctChart(canvasIdPct);
        }
    }

    function crearDatasetsVarPctHz(sexo, tipo, dataM, dataH, dataComb, dataStdM, dataStdH, dataStdComb, tieneStdM, tieneStdH) {
        var SGS = window.SipGraficaShared;
        if (!SGS || !soportaVarPctHz(tipo)) return [];
        var seriesList = [];

        if (sexo === 'macho' || sexo === 'ambos') {
            if (Array.isArray(dataM) && Array.isArray(dataStdM) && tieneStdM) {
                var builtM = SGS.buildVarPctData(dataM, dataStdM, function (a, s) { return evalCumpleHz(tipo, a, s); });
                seriesList.push({ label: 'M', data: builtM.data, cumple: builtM.cumple, borderColor: '#2563eb' });
            }
        }
        if (sexo === 'hembra' || sexo === 'ambos') {
            if (Array.isArray(dataH) && Array.isArray(dataStdH) && tieneStdH) {
                var builtH = SGS.buildVarPctData(dataH, dataStdH, function (a, s) { return evalCumpleHz(tipo, a, s); });
                seriesList.push({ label: 'H', data: builtH.data, cumple: builtH.cumple, borderColor: '#ec4899' });
            }
        }
        if (sexo === 'combinado') {
            if (Array.isArray(dataComb) && Array.isArray(dataStdComb)) {
                var builtC = SGS.buildVarPctData(dataComb, dataStdComb, function (a, s) { return evalCumpleHz(tipo, a, s); });
                seriesList.push({ label: 'Comb.', data: builtC.data, cumple: builtC.cumple, borderColor: '#2563eb' });
            }
        }

        return SGS.buildVarPctDatasets(seriesList, { tipo: tipo, useBar: false });
    }

    function datasetReferenciaPrincipal(mainChart) {
        if (!mainChart || !Array.isArray(mainChart.data.datasets)) return null;
        return mainChart.data.datasets.find(function (ds) {
            if (!ds) return false;
            var lbl = String(ds.label || '');
            if (lbl.indexOf('Estándar') !== -1 || lbl.indexOf('CV ') === 0 || ds.yAxisID === 'yCv') return false;
            if (ds.datalabels && ds.datalabels.display === false) return false;
            return true;
        }) || mainChart.data.datasets[0];
    }

    function sincronizarEstiloPctConPrincipal(mainChart, pctChart) {
        if (!mainChart || !pctChart) return;
        var SGS = window.SipGraficaShared;
        var ref = datasetReferenciaPrincipal(mainChart);
        var refDl = (ref && ref.datalabels) ? ref.datalabels : {};
        var fontSizeMain = (refDl.font && refDl.font.size) ? refDl.font.size : getTamanoFuente(12);
        var fontSize = Math.max(9, Math.min(fontSizeMain, Math.round(fontSizeMain * 0.92)));
        var fontFamily = (refDl.font && refDl.font.family) ? refDl.font.family : FUENTE_GRAF;
        var pctLabelFont = { size: fontSize, weight: '600', family: fontFamily, style: 'normal' };
        var offset = refDl.offset != null ? refDl.offset : 10;
        var anchor = refDl.anchor || 'center';
        var align = refDl.align || 'top';
        var rotation = refDl.rotation != null ? refDl.rotation : -90;

        (pctChart.data.datasets || []).forEach(function (ds) {
            if (!ds || ds.type === 'bar') return;
            if (ref) {
                if (ref.pointRadius != null) ds.pointRadius = Math.max(3, ref.pointRadius - 0.5);
                if (ref.pointHoverRadius != null) ds.pointHoverRadius = Math.max(4, ref.pointHoverRadius - 1);
                if (ref.pointBorderWidth != null) ds.pointBorderWidth = ref.pointBorderWidth;
                if (ref.borderWidth != null) ds.borderWidth = ref.borderWidth;
            }
            if (!ds.datalabels) ds.datalabels = {};
            ds.datalabels.font = Object.assign({}, pctLabelFont);
        });

        pctChart._sipPctLabelFont = pctLabelFont;
        var plugins = pctChart.options.plugins;
        if (plugins && plugins.datalabels) {
            plugins.datalabels.anchor = anchor;
            plugins.datalabels.align = align;
            plugins.datalabels.offset = offset;
            plugins.datalabels.rotation = rotation;
            plugins.datalabels.clip = false;
            plugins.datalabels.font = function () {
                return pctChart._sipPctLabelFont || pctLabelFont;
            };
            plugins.datalabels.display = function (ctx) {
                var val = ctx.dataset.data[ctx.dataIndex];
                return val != null && isFinite(val);
            };
        }

        if (SGS) {
            var yScale = pctChart.options.scales && pctChart.options.scales.y;
            var yRange = {
                min: yScale && yScale.min != null ? yScale.min : -10,
                max: yScale && yScale.max != null ? yScale.max : 10
            };
            var padTop = Math.min(48, SGS.calcPctLayoutPaddingTop(yRange, fontSize, { labelChars: 7 }));
            var padRight = Math.max(6, Math.round(fontSize * 1.1));
            var offsetPct = Math.max(6, Math.round((refDl.offset != null ? refDl.offset : 10) * 0.85));
            if (plugins && plugins.datalabels) {
                plugins.datalabels.offset = offsetPct;
            }
            pctLabelFont.size = fontSize;
            if (pctChart._sipPctLabelFont) {
                pctChart._sipPctLabelFont.size = fontSize;
                pctChart._sipPctLabelFont.weight = '600';
            }
            (pctChart.data.datasets || []).forEach(function (ds) {
                if (!ds || !ds.datalabels) return;
                ds.datalabels.font = Object.assign({}, pctLabelFont);
            });
            if (!pctChart.options.layout) pctChart.options.layout = {};
            if (!pctChart.options.layout.padding) pctChart.options.layout.padding = {};
            pctChart.options.layout.padding.top = padTop;
            pctChart.options.layout.padding.right = padRight;
        }

        pctChart._sipMatchMainChart = mainChart;
        pctChart.update('none');
    }

    function enlazarResyncEstiloPctTrasZoom(mainChart) {
        if (!mainChart || mainChart._sipPctStyleResyncBound) return;
        mainChart._sipPctStyleResyncBound = true;
        var origSync = mainChart._sipSyncPctFn;
        if (typeof origSync !== 'function') return;
        mainChart._sipSyncPctFn = function () {
            origSync();
            var slave = mainChart._sipPctSyncSlave;
            if (slave && slave._sipMatchMainChart) {
                sincronizarEstiloPctConPrincipal(mainChart, slave);
            }
        };
    }

    function crearInstanciaChartHzPct(canvasIdPct, labels, datasets, mainChart, btnResetZoomId) {
        var SGS = window.SipGraficaShared;
        if (!SGS || !datasets || !datasets.length) {
            destruirChartHzPct(canvasIdPct);
            return null;
        }
        destruirChartHzPct(canvasIdPct);
        var pctChart = SGS.createPctChart(canvasIdPct, labels, datasets, { hideXTicks: true });
        if (!pctChart) return null;
        instanciasGraficosHz[canvasIdPct] = pctChart;
        if (mainChart) {
            SGS.finalizePctChartPair(mainChart, pctChart);
            enlazarResyncEstiloPctTrasZoom(mainChart);
            sincronizarEstiloPctConPrincipal(mainChart, pctChart);
            SGS.alignStackedChartAreas(mainChart, pctChart);
        }
        if (pctChart.options && pctChart.options.scales && pctChart.options.scales.y && pctChart.options.scales.y.title) {
            pctChart.options.scales.y.title.display = false;
            pctChart.update('none');
        }
        return pctChart;
    }

    function tituloEjePrincipal(data, tipo) {
        return String((data && data.nombre) || tipo || '').trim().toUpperCase();
    }

    function htmlCanvasConPct(canvasId, conPct, tituloMain) {
        if (!conPct) {
            return '<canvas id="' + canvasId + '"></canvas>';
        }
        return '<div class="hz-chart-stack">' +
            '<div class="hz-chart-row hz-chart-row-pct">' +
            '<div class="hz-y-label">VARIACION</div>' +
            '<div class="hz-chart-canvas-wrap"><canvas id="' + canvasId + '-pct"></canvas></div>' +
            '</div>' +
            '<div class="hz-chart-row hz-chart-row-main">' +
            '<div class="hz-y-label">' + esc(tituloMain) + '</div>' +
            '<div class="hz-chart-canvas-wrap"><canvas id="' + canvasId + '"></canvas></div>' +
            '</div>' +
            '</div>';
    }

    function formatearFechaCorta(fechaStr) {
        if (!fechaStr) return '';
        var parts = String(fechaStr).split('-');
        if (parts.length !== 3) return fechaStr;
        var meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        var dia = parseInt(parts[2], 10);
        var mesIdx = parseInt(parts[1], 10) - 1;
        var mesNom = meses[mesIdx] || parts[1];
        return (dia < 10 ? '0' + dia : dia) + '-' + mesNom;
    }

    /** Fecha ISO (YYYY-MM-DD) a dd/mm/yy. */
    function fmtDDMMYY(fechaStr) {
        if (!fechaStr) return '';
        var m = String(fechaStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return String(fechaStr);
        return m[3] + '/' + m[2] + '/' + m[1].slice(2);
    }

    /* ═══════════════════════════════════════════════════════════════
       Multiselects (tipo de gráficas y días de pesaje)
       ═══════════════════════════════════════════════════════════════ */

    function tiposSeleccionados() {
        return msTipos ? msTipos.getSelectedValues() : [];
    }

    function diasSeleccionados() {
        return msDias ? msDias.getSelectedValues() : [];
    }

    function labelDiaPesaje(d) {
        if (d.semana != null && d.semana !== undefined) {
            return 'Día ' + d.dia + ' (Sem ' + d.semana + ')';
        }
        if (d.dia === 1) {
            return 'Día ' + d.dia + ' (Inicial)';
        }
        return 'Día ' + d.dia;
    }

    /* ═══════════════════════════════════════════════════════════════
       Catálogos (tipos y días de pesaje)
       ═══════════════════════════════════════════════════════════════ */
    var TIPOS_CARGADOS = [];

    function cargarTiposGrafica() {
        if (!cfg.tiposUrl) return Promise.resolve();
        return fetch(cfg.tiposUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false || !Array.isArray(j.data)) return;
                TIPOS_CARGADOS = j.data
                    .map(function (t) { return { id: String(t.id || t.tipo || ''), label: t.label || t.nombre || t.id }; })
                    .filter(function (t) { return t.id; });
                if (msTipos) {
                    var opciones = TIPOS_CARGADOS.map(function (t) {
                        return { id: t.id, label: t.label };
                    });
                    msTipos.setOptions(opciones, false);
                    var defs = (cfg.tiposDefecto || ['mortalidad']).filter(function (id) {
                        return opciones.some(function (o) { return o.id === id; });
                    });
                    if (!defs.length && opciones.length) {
                        defs = [opciones[0].id];
                    }
                    msTipos.selectOnlyIds(defs, false);
                }
                return gestionarVisibilidadFiltros();
            })
            .catch(function (err) {
                console.error('GRAF-HZ: error al cargar tipos:', err);
            });
    }

    function cargarDiasDeTipo(tipo) {
        if (diasPorTipoCache[tipo]) return Promise.resolve(diasPorTipoCache[tipo]);
        return fetch(cfg.diasUrl + '?tipo=' + encodeURIComponent(tipo), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var dias = (j && j.success !== false && Array.isArray(j.dias)) ? j.dias : [];
                diasPorTipoCache[tipo] = dias;
                return dias;
            })
            .catch(function () {
                diasPorTipoCache[tipo] = [];
                return [];
            });
    }

    function gestionarVisibilidadFiltros() {
        var wrapSemana = el('ghz-wrap-semana');
        var wrapDias = el('ghz-wrap-dias');
        var tiposPesajeActivos = tiposSeleccionados().filter(function (t) { return TIPOS_PESAJE.indexOf(t) !== -1; });
        var tiposSemanaActivos = tiposSeleccionados().filter(function (t) { return esTipoSemana(t); });

        if (wrapSemana) {
            wrapSemana.style.display = tiposSemanaActivos.length ? '' : 'none';
        }
        return gestionarDiasPesaje(tiposPesajeActivos, wrapDias);
    }

    function gestionarDiasPesaje(tiposPesajeActivos, wrapDias) {
        if (!wrapDias) wrapDias = el('ghz-wrap-dias');
        if (!tiposPesajeActivos) {
            tiposPesajeActivos = tiposSeleccionados().filter(function (t) { return TIPOS_PESAJE.indexOf(t) !== -1; });
        }
        if (!tiposPesajeActivos.length) {
            if (wrapDias) wrapDias.style.display = 'none';
            return Promise.resolve();
        }
        if (wrapDias) wrapDias.style.display = '';
        var promesas = tiposPesajeActivos.map(cargarDiasDeTipo);
        return Promise.all(promesas).then(function () {
            var items = [];
            var vistos = {};
            tiposPesajeActivos.forEach(function (t) {
                (diasPorTipoCache[t] || []).forEach(function (d) {
                    if (!vistos[d.dia]) {
                        vistos[d.dia] = true;
                        items.push(d);
                    }
                });
            });
            items.sort(function (a, b) { return a.dia - b.dia; });
            if (!msDias) return;
            var opcionesDias = items.map(function (d) {
                return { id: String(d.dia), label: labelDiaPesaje(d) };
            });
            var prev = msDias.getSelectedValues();
            msDias.setOptions(opcionesDias, false);
            var idsDisp = opcionesDias.map(function (o) { return o.id; });
            var sel = prev.filter(function (d) { return idsDisp.indexOf(d) !== -1; });
            if (sel.length) {
                msDias.selectOnlyIds(sel, false);
            } else {
                msDias.clearSelection();
            }
        });
    }

    /* ═══════════════════════════════════════════════════════════════
       Dropdown jerárquico granjas / campañas / galpones
       ═══════════════════════════════════════════════════════════════ */
    function paramsPeriodoGranjas() {
        var fi = (el('ghz-fecha-inicio') ? el('ghz-fecha-inicio').value : '').trim();
        var ff = (el('ghz-fecha-fin') ? el('ghz-fecha-fin').value : '').trim();
        var p = new URLSearchParams();
        if (fi && ff) {
            p.set('periodoTipo', 'ENTRE_FECHAS');
            p.set('fechaInicio', fi);
            p.set('fechaFin', ff);
        } else {
            p.set('periodoTipo', 'TODOS');
        }
        return p;
    }

    function cargarCatalogoGranjas() {
        if (!cfg.granjasUrl || !msUbicacion) return Promise.resolve();
        return fetch(cfg.granjasUrl + '?' + paramsPeriodoGranjas().toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) return;
                var preserve = msUbicacion.selectedGalpones.size > 0;
                msUbicacion.setData(j.granjas || [], false);
                if (!preserve) {
                    msUbicacion.selectAll(false);
                }
            })
            .catch(function (err) {
                console.error('GRAF-HZ: error al cargar granjas:', err);
            });
    }

    function initUbicacionUnificada() {
        if (typeof CustomHierarchicalMultiSelect !== 'function') return;
        msUbicacion = new CustomHierarchicalMultiSelect({
            btnId: 'ghz-unificado-btn',
            labelId: 'ghz-unificado-label',
            dropdownId: 'ghz-unificado-dropdown',
            searchId: 'ghz-unificado-search',
            optionsContainerId: 'ghz-unificado-options',
            btnLimpiarId: 'ghz-unificado-limpiar',
            onChange: function () {}
        });
    }

    function cargaInicial() {
        initUbicacionUnificada();
        return cargarTiposGrafica()
            .then(function () { return cargarCatalogoGranjas(); })
            .then(function () { consultarGraficos(); })
            .catch(function (err) {
                console.error('GRAF-HZ: error en carga inicial:', err);
            });
    }

    function obtenerUnificadoFiltro() {
        if (!msUbicacion) return [];
        var totalGranjas = msUbicacion.granjasData.length;
        var totalGalpones = msUbicacion.granjasData.reduce(function (acc, g) {
            return acc + (Array.isArray(g.galpones) ? g.galpones.length : 0);
        }, 0);
        if (totalGranjas > 0
            && msUbicacion.selectedGranjas.size === totalGranjas
            && msUbicacion.selectedGalpones.size === totalGalpones) {
            return [];
        }
        if (msUbicacion.selectedGalpones.size === 0 && msUbicacion.selectedGranjas.size === 0) {
            return [];
        }
        return msUbicacion.getSelectedCombinations();
    }

    /* ═══════════════════════════════════════════════════════════════
       Construcción de tareas de consulta
       ═══════════════════════════════════════════════════════════════ */
    function leerFiltros() {
        return {
            fechaInicio: (el('ghz-fecha-inicio') ? el('ghz-fecha-inicio').value : '').trim(),
            fechaFin: (el('ghz-fecha-fin') ? el('ghz-fecha-fin').value : '').trim(),
            semana: parseInt((el('ghz-semana') ? el('ghz-semana').value : '1'), 10),
            sexo: (el('ghz-sexo') ? el('ghz-sexo').value : 'macho'),
            separar: Boolean(el('ghz-separar') && el('ghz-separar').checked)
        };
    }

    function crearTareas(tipos, f) {
        var tareas = [];
        tipos.forEach(function (tipo) {
            if (TIPOS_PESAJE.indexOf(tipo) !== -1) {
                var dias = diasSeleccionados().map(function (d) { return parseInt(d, 10); }).filter(function (d) { return !isNaN(d); });
                if (dias.length) {
                    tareas.push({ tipo: tipo, semana: null, dia: null, dias: dias, esLiquidacion: false });
                }
            } else if (esTipoLiquidacion(tipo)) {
                tareas.push({ tipo: tipo, semana: null, dia: null, dias: null, esLiquidacion: true });
            } else {
                var semana = f.semana;
                if (isNaN(semana) || semana < 1 || semana > 10) semana = 1;
                tareas.push({ tipo: tipo, semana: semana, dia: null, dias: null, esLiquidacion: false });
            }
        });
        return tareas;
    }

    /* ═══════════════════════════════════════════════════════════════
       Consulta principal
       ═══════════════════════════════════════════════════════════════ */
    function consultarGraficos() {
        var f = leerFiltros();
        if (!f.fechaInicio || !f.fechaFin) {
            toast('Selecciona Fecha inicio y Fecha fin', 'warning');
            return;
        }
        var tiposPesajeActivos = tiposSeleccionados().filter(function (t) { return TIPOS_PESAJE.indexOf(t) !== -1; });
        var tiposSemanaActivos = tiposSeleccionados().filter(function (t) { return esTipoSemana(t); });
        if (tiposPesajeActivos.length && !diasSeleccionados().length) {
            toast('Selecciona al menos un día de pesaje/ganancia', 'warning');
            return;
        }
        if (tiposSemanaActivos.length) {
            var sem = f.semana;
            if (isNaN(sem) || sem < 1 || sem > 10) {
                toast('Ingresa un número de semana válido (1-10)', 'warning');
                return;
            }
        }
        var tiposSel = tiposSeleccionados();
        if (!tiposSel.length) {
            toast('Selecciona al menos un tipo de gráfica', 'warning');
            return;
        }

        var tareas = crearTareas(tiposSel, f);
        if (!tareas.length) {
            toast('No hay consultas que ejecutar con los filtros actuales', 'warning');
            return;
        }

        var opcionesFiltro = {
            sexo: f.sexo,
            separarGraficos: f.sexo === 'ambos' && f.separar,
            unificado: obtenerUnificadoFiltro()
        };

        limpiarGraficos();
        var cont = el('ghz-graficos');
        cont.innerHTML = '<div class="hz-card-grafica"><div class="hz-loading"><div class="hz-spinner"></div>' +
            '<span>Cargando gráficas horizontales…</span></div></div>';

        var btn = el('ghz-btn-cargar');
        if (btn) btn.disabled = true;

        var total = tareas.length;
        var completadas = 0;
        var respuestas = [];
        var huboError = false;
        var cursor = 0;

        function consultarTarea(t) {
            var params = new URLSearchParams({
                tipo: t.tipo,
                fechaInicio: f.fechaInicio,
                fechaFin: f.fechaFin
            });
            if (Array.isArray(t.dias) && t.dias.length) {
                params.set('dias', t.dias.join(','));
            } else if (t.dia != null) {
                params.set('dia', String(t.dia));
            } else if (!t.esLiquidacion && t.semana != null) {
                params.set('semana', String(t.semana));
            }
            return fetch(cfg.datosUrl + '?' + params.toString(), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); });
        }

        function procesarRespuesta(json, tipo) {
            if (!json) {
                huboError = true;
                return;
            }
            if (json.success === false) {
                huboError = true;
                toast(json.message || ('Error al cargar ' + tipo), 'error');
                return;
            }
            if (Array.isArray(json.items)) {
                json.items.forEach(function (it) { respuestas.push(it); });
            } else {
                respuestas.push(json);
            }
        }

        function trabajador() {
            while (cursor < tareas.length) {
                var t = tareas[cursor++];
                consultarTarea(t)
                    .then(function (json) { procesarRespuesta(json, t.tipo); })
                    .catch(function (err) {
                        huboError = true;
                        console.error('GRAF-HZ: error consultando', t.tipo, err);
                    })
                    .then(function () {
                        completadas++;
                        if (completadas >= total) {
                            terminar();
                        }
                    });
            }
        }

        function terminar() {
            if (btn) btn.disabled = false;
            cont.innerHTML = '';
            if (respuestas.length) {
                respuestas.forEach(function (r) { renderizarGraficoHorizontal(r, opcionesFiltro); });
                if (huboError) {
                    toast('Algunas gráficas no se pudieron cargar', 'warning');
                }
            } else {
                cont.innerHTML =
                    '<div class="hz-card-grafica"><div class="hz-empty">' +
                    '<i class="fas fa-chart-line"></i>' +
                    '<span>' + (huboError ? 'No se pudieron cargar los datos.' : 'Sin datos en el periodo seleccionado.') + '</span>' +
                    '</div></div>';
            }
        }

        var n = Math.max(1, Math.min(2, tareas.length));
        for (var i = 0; i < n; i++) {
            trabajador();
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       Helpers numéricos
       ═══════════════════════════════════════════════════════════════ */
    function num(v) {
        return (v !== null && v !== undefined && !isNaN(v)) ? Number(v) : null;
    }

    function combinarMh(m, h) {
        var vm = num(m);
        var vh = num(h);
        if (vm !== null && vh !== null) return Number(((vm + vh) / 2).toFixed(4));
        if (vm !== null) return vm;
        if (vh !== null) return vh;
        return null;
    }

    /* ═══════════════════════════════════════════════════════════════
       Render de gráficas
       ═══════════════════════════════════════════════════════════════ */
    function limpiarGraficos() {
        Object.keys(instanciasGraficosHz).forEach(function (key) {
            if (key.indexOf('-pct') !== -1) {
                destruirChartHzPct(key);
                return;
            }
            if (instanciasGraficosHz[key]) {
                try {
                    instanciasGraficosHz[key].destroy();
                } catch (e) { /* sinop */ }
                delete instanciasGraficosHz[key];
            }
        });
        cachePorTipoHz = {};
        cachePorCanvasHz = {};
        var cont = el('ghz-graficos');
        if (cont) cont.innerHTML = '';
    }

    function renderizarGraficoHorizontal(data, opcionesFiltro) {
        var contenedor = el('ghz-graficos');
        if (!contenedor || !data || !Array.isArray(data.registros)) return;

        var tipo = data.tipo || 'mortalidad';
        var tipoKey = data.dia ? tipo + '-dia-' + data.dia : tipo;
        var cardId = 'card-graf-hz-' + tipoKey;

        cachePorTipoHz[tipoKey] = {
            data: data,
            opcionesFiltro: {
                sexo: opcionesFiltro.sexo,
                separarGraficos: Boolean(opcionesFiltro.separarGraficos),
                unificado: (opcionesFiltro.unificado || []).slice()
            }
        };

        var registrosFiltrados = data.registros;
        var unificado = opcionesFiltro.unificado || [];
        if (unificado.length) {
            registrosFiltrados = registrosFiltrados.filter(function (r) {
                return unificado.some(function (u) {
                    var matchGranja = (u.granja && (String(u.granja) === String(r.granja) || String(u.granja) === String(r.granjaNombre)));
                    if (!matchGranja) return false;
                    if (u.campania && String(u.campania) !== String(r.campania)) return false;
                    if (u.galpon && String(u.galpon) !== String(r.galpon)) return false;
                    return true;
                });
            });
        }

        // Destruir instancias previas de este tipo.
        ['', '-macho', '-hembra', '-pct', '-macho-pct', '-hembra-pct'].forEach(function (suf) {
            var cId = 'canvas-hz-' + tipoKey + suf;
            if (cId.indexOf('-pct') !== -1) {
                destruirChartHzPct(cId);
            } else if (instanciasGraficosHz[cId]) {
                try {
                    instanciasGraficosHz[cId].destroy();
                } catch (e) { /* sinop */ }
                delete instanciasGraficosHz[cId];
            }
            delete cachePorCanvasHz[cId];
        });

        var cardExistente = el(cardId);
        if (cardExistente) cardExistente.remove();

        var textoPeriodoHz;
        if (data.esLiquidacion) {
            textoPeriodoHz = 'Liquidación · ' + fmtDDMMYY((data.periodo && data.periodo.desde) || '') +
                ' al ' + fmtDDMMYY((data.periodo && data.periodo.hasta) || '');
        } else if (data.dia) {
            textoPeriodoHz = 'Día ' + data.dia + (data.semana ? ' (Semana ' + data.semana + ')' : '');
        } else {
            textoPeriodoHz = 'Semana ' + (data.semana || 1);
        }

        if (!registrosFiltrados.length) {
            var cardVacia = document.createElement('div');
            cardVacia.id = cardId;
            cardVacia.className = 'hz-card-grafica';
            cardVacia.innerHTML = '<div class="hz-empty">' +
                '<i class="fas fa-chart-bar"></i>' +
                '<span><b>' + esc(data.nombre || tipo) + '</b> · ' + esc(textoPeriodoHz) +
                '<br>Sin lotes con los filtros seleccionados.</span></div>';
            contenedor.appendChild(cardVacia);
            return;
        }

        // Construir series.
        var labels = [];
        var dataM = [], dataH = [], dataComb = [];
        var dataCvM = [], dataCvH = [], dataCvComb = [];
        var dataStdM = [], dataStdH = [], dataStdComb = [];
        var tieneCv = false, tieneStdM = false, tieneStdH = false;

        registrosFiltrados.forEach(function (r) {
            var granjaStr = r.granjaNombre || r.granja;
            var fechaStr = formatearFechaCorta(r.fechaSemana || r.fechaCarga);
            labels.push(granjaStr + '-' + r.campania + '-' + r.galpon + ': ' + fechaStr);

            var vM = num(r.valorM), vH = num(r.valorH);
            dataM.push(vM);
            dataH.push(vH);
            dataComb.push(combinarMh(vM, vH));

            var sM = num(r.estandarM), sH = num(r.estandarH);
            dataStdM.push(sM);
            dataStdH.push(sH);
            dataStdComb.push(combinarMh(sM, sH));
            if (sM !== null) tieneStdM = true;
            if (sH !== null) tieneStdH = true;

            var cM = num(r.cvM), cH = num(r.cvH);
            dataCvM.push(cM);
            dataCvH.push(cH);
            dataCvComb.push(combinarMh(cM, cH));
            if (cM !== null || cH !== null) tieneCv = true;
        });

        if (!tipoIncluyeCvEnGrafico(tipo)) {
            tieneCv = false;
        }

        var card = document.createElement('div');
        card.id = cardId;
        card.className = 'hz-card-grafica';

        var nombreGrafico = data.nombre || String(tipo).toUpperCase();
        var esSeparado = (opcionesFiltro.sexo === 'ambos') && Boolean(opcionesFiltro.separarGraficos);

        var textoSexo = 'Macho';
        if (opcionesFiltro.sexo === 'ambos') {
            textoSexo = esSeparado ? 'Macho y Hembra (separados)' : 'Macho y Hembra';
        } else if (opcionesFiltro.sexo === 'hembra') {
            textoSexo = 'Hembra';
        } else if (opcionesFiltro.sexo === 'combinado') {
            textoSexo = 'Combinado (promedio)';
        }

        var minCanvasWidth = Math.max(600, registrosFiltrados.length * getColWidthPorLote());
        var tensionVal = 0;
        var conPct = soportaVarPctHz(tipo);
        var wrapClass = conPct ? 'hz-canvas-wrap hz-con-pct' : 'hz-canvas-wrap';
        var tituloEje = tituloEjePrincipal(data, tipo);
        var wrapHeight = conPct ? '660px' : '360px';

        var btnResetHead = esSeparado ? '' :
            '<button type="button" class="hz-btn-reset-zoom" id="rz-hz-' + esc(tipoKey) + '">↺ Reset zoom</button>';

        var headHtml =
            '<div class="hz-card-grafica-head">' +
            '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">' +
            '<span class="hz-badge-tipo">' + esc(nombreGrafico) + '</span>' +
            '<span style="font-size:.76rem;font-weight:600;color:#334155;">' + esc(textoPeriodoHz) + ' — ' + esc(textoSexo) + '</span>' +
            '</div>' +
            '<div class="hz-card-grafica-meta">' +
            '<span>Período: <strong>' + esc(fmtDDMMYY((data.periodo && data.periodo.desde) || '')) + '</strong> al <strong>' + esc(fmtDDMMYY((data.periodo && data.periodo.hasta) || '')) + '</strong></span>' +
            '<span class="hz-badge-unidad">Unidad: ' + esc(data.unidad || '') + (tieneCv ? ' / CV%' : '') + (conPct ? ' / Var.%' : '') + '</span>' +
            btnResetHead +
            '</div></div>';

        if (esSeparado) {
            var idM = 'canvas-hz-' + tipoKey + '-macho';
            var idH = 'canvas-hz-' + tipoKey + '-hembra';
            card.innerHTML = headHtml +
                '<div class="hz-grafico-sub" style="border-top:1px solid #f1f5f9;">' +
                '<span class="hz-badge-tipo" style="background:#eff6ff;color:#1d4ed8;">Macho</span> ' +
                '<button type="button" class="hz-btn-reset-zoom" id="rz-hz-' + tipoKey + '-macho">↺ Reset zoom</button></div>' +
                '<div class="hz-canvas-scroll"><div class="' + wrapClass + '" id="wrap-canvas-hz-' + tipoKey + '-macho" style="height:' + wrapHeight + ';">' +
                htmlCanvasConPct(idM, conPct, tituloEje) + '</div></div>' +
                '<div class="hz-chart-leyenda" id="leyenda-hz-' + tipoKey + '-macho"></div>' +
                '<div class="hz-grafico-sub"><span class="hz-badge-tipo" style="background:#fff1f2;color:#be123c;">Hembra</span> ' +
                '<button type="button" class="hz-btn-reset-zoom" id="rz-hz-' + tipoKey + '-hembra">↺ Reset zoom</button></div>' +
                '<div class="hz-canvas-scroll"><div class="' + wrapClass + '" id="wrap-canvas-hz-' + tipoKey + '-hembra" style="height:' + wrapHeight + ';">' +
                htmlCanvasConPct(idH, conPct, tituloEje) + '</div></div>' +
                '<div class="hz-chart-leyenda" id="leyenda-hz-' + tipoKey + '-hembra"></div>';
            contenedor.appendChild(card);

            var chartM = crearInstanciaChart(idM, labels, crearDatasets('macho', data, tipo, dataM, dataH, dataComb, dataCvM, dataCvH, dataCvComb, dataStdM, dataStdH, dataStdComb, tensionVal, tieneCv, tieneStdM, tieneStdH), data, 'rz-hz-' + tipoKey + '-macho', tieneCv, 'wrap-canvas-hz-' + tipoKey + '-macho', registrosFiltrados, 'leyenda-hz-' + tipoKey + '-macho', conPct);
            if (conPct) {
                crearInstanciaChartHzPct(idM + '-pct', labels, crearDatasetsVarPctHz('macho', tipo, dataM, dataH, dataComb, dataStdM, dataStdH, dataStdComb, tieneStdM, tieneStdH), chartM, 'rz-hz-' + tipoKey + '-macho');
            }
            var chartH = crearInstanciaChart(idH, labels, crearDatasets('hembra', data, tipo, dataM, dataH, dataComb, dataCvM, dataCvH, dataCvComb, dataStdM, dataStdH, dataStdComb, tensionVal, tieneCv, tieneStdM, tieneStdH), data, 'rz-hz-' + tipoKey + '-hembra', tieneCv, 'wrap-canvas-hz-' + tipoKey + '-hembra', registrosFiltrados, 'leyenda-hz-' + tipoKey + '-hembra', conPct);
            if (conPct) {
                crearInstanciaChartHzPct(idH + '-pct', labels, crearDatasetsVarPctHz('hembra', tipo, dataM, dataH, dataComb, dataStdM, dataStdH, dataStdComb, tieneStdM, tieneStdH), chartH, 'rz-hz-' + tipoKey + '-hembra');
            }
        } else {
            var idU = 'canvas-hz-' + tipoKey;
            var wrapHeightU = conPct ? '660px' : '530px';
            card.innerHTML = headHtml +
                '<div class="hz-canvas-scroll" style="padding-top:.6rem;">' +
                '<div class="' + wrapClass + '" id="wrap-canvas-hz-' + tipoKey + '" style="height:' + wrapHeightU + ';">' +
                htmlCanvasConPct(idU, conPct, tituloEje) +
                '</div></div>' +
                '<div class="hz-chart-leyenda" id="leyenda-hz-' + tipoKey + '"></div>';
            contenedor.appendChild(card);
            var chartU = crearInstanciaChart(idU, labels, crearDatasets(opcionesFiltro.sexo, data, tipo, dataM, dataH, dataComb, dataCvM, dataCvH, dataCvComb, dataStdM, dataStdH, dataStdComb, tensionVal, tieneCv, tieneStdM, tieneStdH), data, 'rz-hz-' + tipoKey, tieneCv, 'wrap-canvas-hz-' + tipoKey, registrosFiltrados, 'leyenda-hz-' + tipoKey, conPct);
            if (conPct) {
                crearInstanciaChartHzPct(idU + '-pct', labels, crearDatasetsVarPctHz(opcionesFiltro.sexo, tipo, dataM, dataH, dataComb, dataStdM, dataStdH, dataStdComb, tieneStdM, tieneStdH), chartU, 'rz-hz-' + tipoKey);
            }
        }
    }

    /** Leyenda HTML centrada fuera del área con scroll horizontal. */
    function construirLeyendaHtml(leyendaId, chartInstance) {
        var contLeyenda = leyendaId ? el(leyendaId) : null;
        if (!contLeyenda || !chartInstance) return;
        var datasets = (chartInstance.data && chartInstance.data.datasets) || [];
        var html = datasets.map(function (ds) {
            var color = ds.borderColor || '#64748b';
            var dashed = (Array.isArray(ds.borderDash) && ds.borderDash.length) ? ' dashed' : '';
            return '<span class="hz-ley-item"><i class="hz-ley-linea' + dashed + '" style="color:' + color + ';"></i>' +
                '<span>' + esc(ds.label || '') + '</span></span>';
        }).join('');
        contLeyenda.innerHTML = html || '';
    }

    function crearInstanciaChart(canvasId, labels, datasets, data, btnResetZoomId, tieneCv, wrapperId, registrosFiltrados, leyendaId, ejeYExterno) {
        var canvas = el(canvasId);
        if (!canvas) return null;
        var ctx = canvas.getContext('2d');
        var btnResetZoom = btnResetZoomId ? el(btnResetZoomId) : null;
        var wrapper = wrapperId ? el(wrapperId) : null;
        if (wrapper && Array.isArray(registrosFiltrados)) {
            wrapper.style.minWidth = Math.max(700, registrosFiltrados.length * getColWidthPorLote()) + 'px';
        }

        var plugins = [];
        if (window.ChartDataLabels) plugins.push(window.ChartDataLabels);

        // Configuración del gráfico según modo combinado o serie única.
        var esCombinado = Boolean(data.esCombinado) && Array.isArray(data.seriesList);
        var tieneEjeDerecho = tieneCv || (esCombinado && data.seriesList.some(function (s) { return s.position === 'right'; }));

        var escalas = {
            x: {
                grid: { color: 'rgba(226, 232, 240, 0.7)', drawBorder: false },
                ticks: {
                    autoSkip: false,
                    maxRotation: 90,
                    minRotation: 90,
                    font: { size: getTamanoFuente(13), weight: '600', family: FUENTE_GRAF },
                    color: '#334155'
                },
                border: { display: false }
            }
        };

        if (esCombinado) {
            data.seriesList.forEach(function (s, idx) {
                escalas[s.axisId || 'y'] = {
                    beginAtZero: true,
                    position: s.position,
                    grid: {
                        drawOnChartArea: idx === 0,
                        color: 'rgba(226, 232, 240, 0.7)',
                        drawBorder: false
                    },
                    title: {
                        display: true,
                        text: s.nombre + ' (' + (s.unidad || '') + ')',
                        font: { size: getTamanoFuente(10), weight: 'bold', family: FUENTE_GRAF },
                        color: s.color
                    },
                    ticks: {
                        font: { size: getTamanoFuente(9.5), weight: '600', family: FUENTE_GRAF },
                        color: s.color
                    }
                };
            });
        } else {
            var tipoGraf = (data && data.tipo) || '';
            var ejePorcentual = esUnidadPorcentual(tipoGraf, data);
            escalas.y = {
                beginAtZero: true,
                position: 'left',
                grid: { color: 'rgba(226, 232, 240, 0.7)', drawBorder: false },
                title: {
                    display: !ejeYExterno,
                    text: data.unidad || '',
                    font: { size: getTamanoFuente(11), weight: 'bold', family: FUENTE_GRAF },
                    color: '#475569'
                },
                ticks: {
                    font: { size: getTamanoFuente(10), weight: '600', family: FUENTE_GRAF },
                    color: '#475569',
                    callback: ejePorcentual ? function (val) { return val + '%'; } : undefined
                }
            };
            if (tieneCv) {
                escalas.yCv = {
                    beginAtZero: true,
                    position: 'right',
                    grid: { drawOnChartArea: false, color: 'rgba(226, 232, 240, 0.5)', drawBorder: false },
                    title: {
                        display: true,
                        text: 'CV %',
                        font: { size: getTamanoFuente(11), weight: 'bold', family: FUENTE_GRAF },
                        color: '#0284c7'
                    },
                    ticks: {
                        font: { size: getTamanoFuente(10), weight: '600', family: FUENTE_GRAF },
                        color: '#0284c7',
                        callback: function (val) { return val + '%'; }
                    }
                };
            }
        }

        var chartInstance = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            plugins: plugins,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                devicePixelRatio: Math.max(window.devicePixelRatio || 1, 2),
                layout: { padding: { top: 52, right: tieneEjeDerecho ? 30 : 20, bottom: 10, left: 10 } },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    zoom: {
                        pan: {
                            enabled: true,
                            mode: 'x',
                            onPanComplete: function () {
                                if (btnResetZoom) btnResetZoom.classList.add('visible');
                            }
                        },
                        zoom: {
                            wheel: { enabled: true, speed: 0.1 },
                            pinch: { enabled: true },
                            mode: 'x',
                            onZoomComplete: function () {
                                if (btnResetZoom) btnResetZoom.classList.add('visible');
                            }
                        }
                    },
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { size: 12, weight: 'bold' },
                        bodyFont: { size: 11 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            title: function (items) {
                                return items.length ? (items[0].label || '') : '';
                            },
                            label: function (context) {
                                var val = context.parsed.y;
                                if (val === null || val === undefined) return context.dataset.label + ': S/D';
                                if (context.dataset.yAxisID === 'yCv' || String(context.dataset.label).indexOf('CV') !== -1) {
                                    return context.dataset.label + ': ' + Number(val).toFixed(2) + '%';
                                }
                                var dec = getDecimalesPorTipo((data && data.tipo) || '');
                                return context.dataset.label + ': ' + fmtValorGrafica(val, (data && data.tipo) || '', data, dec);
                            }
                        }
                    }
                },
                scales: escalas
            }
        });

        construirLeyendaHtml(leyendaId, chartInstance);

        function resetZoomPar() {
            var pctInst = instanciasGraficosHz[canvasId + '-pct'];
            var SGS = window.SipGraficaShared;
            if (SGS && pctInst && typeof SGS.resetChartsZoomX === 'function') {
                SGS.resetChartsZoomX(chartInstance, pctInst);
            } else if (chartInstance && typeof chartInstance.resetZoom === 'function') {
                chartInstance.resetZoom();
            }
            if (btnResetZoom) btnResetZoom.classList.remove('visible');
        }

        if (btnResetZoom) {
            btnResetZoom.addEventListener('click', resetZoomPar);
        }
        canvas.addEventListener('dblclick', resetZoomPar);

        instanciasGraficosHz[canvasId] = chartInstance;
        cachePorCanvasHz[canvasId] = {
            chartInstance: chartInstance,
            data: data,
            registrosFiltrados: registrosFiltrados,
            wrapperId: wrapperId || null
        };
        return chartInstance;
    }

    /* ═══════════════════════════════════════════════════════════════
       Datasets
       ═══════════════════════════════════════════════════════════════ */
    function crearDatasets(sexo, data, tipo, dataM, dataH, dataComb, dataCvM, dataCvH, dataCvComb, dataStdM, dataStdH, dataStdComb, tensionVal, tieneCv, tieneStdM, tieneStdH) {
        var datasets = [];
        var fontDatalabelsSize = getTamanoFuente(12);
        var dec = getDecimalesPorTipo(tipo);

        // Valor del nodo rotado 90° (etiquetas verticales sobre cada punto).
        function datalabelsNodo(color) {
            return {
                display: true,
                rotation: -90,
                anchor: 'center',
                align: 'top',
                offset: 10,
                clamp: false,
                color: color,
                font: { size: fontDatalabelsSize, weight: 'bold', family: FUENTE_GRAF },
                formatter: function (value) {
                    return fmtValorGrafica(value, tipo, data, dec);
                }
            };
        }

        if (data.esCombinado && Array.isArray(data.seriesList)) {
            data.seriesList.forEach(function (s) {
                var arrData = s.dataM;
                if (sexo === 'hembra') arrData = s.dataH;
                else if (sexo === 'combinado') arrData = s.dataComb;
                datasets.push({
                    label: s.nombre + ' (' + (s.unidad || '') + ')',
                    data: arrData,
                    yAxisID: s.axisId || 'y',
                    borderColor: s.color,
                    backgroundColor: s.bg || s.color,
                    borderWidth: 2.2,
                    pointBackgroundColor: s.color,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 1.8,
                    pointRadius: 3.5,
                    pointHoverRadius: 6,
                    tension: tensionVal,
                    fill: false,
                    datalabels: { display: false } // Muchas series: solo tooltip.
                });
            });
            return datasets;
        }

        var labelPeriodo = data.esLiquidacion ? 'Liq' : (data.dia ? 'D' + data.dia : 'S' + (data.semana || 1));

        function datasetReal(etiqueta, arr, color, colorTexto) {
            return {
                label: etiqueta,
                data: arr,
                yAxisID: 'y',
                borderColor: color,
                backgroundColor: 'rgba(255,255,255,0)',
                borderWidth: 2.2,
                pointBackgroundColor: color,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 1.8,
                pointRadius: 4,
                pointHoverRadius: 6.5,
                tension: tensionVal,
                fill: false,
                datalabels: datalabelsNodo(colorTexto || color)
            };
        }

        function datasetEstandar(etiqueta, arr, color) {
            return {
                label: etiqueta,
                data: arr,
                yAxisID: 'y',
                borderColor: color,
                backgroundColor: 'transparent',
                borderWidth: 1.8,
                borderDash: [5, 4],
                pointBackgroundColor: color,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 1.5,
                pointRadius: 3.5,
                pointHoverRadius: 5.5,
                tension: tensionVal,
                fill: false,
                datalabels: { display: false } // Sin valores en el nodo del estándar.
            };
        }

        function datasetCv(etiqueta, arr, color) {
            return {
                label: etiqueta,
                data: arr,
                yAxisID: 'yCv',
                borderColor: color,
                backgroundColor: 'rgba(255,255,255,0)',
                borderWidth: 2,
                borderDash: [5, 4],
                pointBackgroundColor: color,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 1.8,
                pointRadius: 4,
                pointHoverRadius: 6.5,
                tension: tensionVal,
                fill: false,
                datalabels: { display: false } // Evita solaparse con los valores reales rotados.
            };
        }

        if (sexo === 'macho' || sexo === 'ambos') {
            datasets.push(datasetReal((data.nombre || tipo) + ' ' + labelPeriodo + ' Macho', dataM, '#2563eb', '#1d4ed8'));
            if (tieneStdM && Array.isArray(dataStdM)) {
                datasets.push(datasetEstandar('Estándar ' + labelPeriodo + ' Macho', dataStdM, '#16a34a'));
            }
            if (tieneCv && Array.isArray(dataCvM)) {
                datasets.push(datasetCv('CV ' + labelPeriodo + ' Macho (%)', dataCvM, '#0284c7'));
            }
        }
        if (sexo === 'hembra' || sexo === 'ambos') {
            datasets.push(datasetReal((data.nombre || tipo) + ' ' + labelPeriodo + ' Hembra', dataH, '#ea0c43', '#be123c'));
            if (tieneStdH && Array.isArray(dataStdH)) {
                datasets.push(datasetEstandar('Estándar ' + labelPeriodo + ' Hembra', dataStdH, '#166534'));
            }
            if (tieneCv && Array.isArray(dataCvH)) {
                datasets.push(datasetCv('CV ' + labelPeriodo + ' Hembra (%)', dataCvH, '#ea580c'));
            }
        }
        if (sexo === 'combinado') {
            datasets.push(datasetReal((data.nombre || tipo) + ' ' + labelPeriodo + ' Combinado', dataComb, '#8b5cf6', '#6d28d9'));
            if ((tieneStdM || tieneStdH) && Array.isArray(dataStdComb)) {
                datasets.push(datasetEstandar('Estándar ' + labelPeriodo + ' Combinado', dataStdComb, '#15803d'));
            }
            if (tieneCv && Array.isArray(dataCvComb)) {
                datasets.push(datasetCv('CV ' + labelPeriodo + ' Combinado (%)', dataCvComb, '#7c3aed'));
            }
        }

        return datasets;
    }

    /* ═══════════════════════════════════════════════════════════════
       Actualizaciones en caliente (sin recargar el endpoint)
       ═══════════════════════════════════════════════════════════════ */
    function actualizarSexo(nuevoSexo) {
        Object.keys(cachePorTipoHz).forEach(function (tipo) {
            var item = cachePorTipoHz[tipo];
            if (item && item.data) {
                item.opcionesFiltro.sexo = nuevoSexo;
                if (nuevoSexo !== 'ambos') {
                    item.opcionesFiltro.separarGraficos = false;
                }
                renderizarGraficoHorizontal(item.data, item.opcionesFiltro);
            }
        });
    }

    function actualizarSeparacion(separar) {
        Object.keys(cachePorTipoHz).forEach(function (tipo) {
            var item = cachePorTipoHz[tipo];
            if (item && item.data) {
                item.opcionesFiltro.separarGraficos = Boolean(separar);
                renderizarGraficoHorizontal(item.data, item.opcionesFiltro);
            }
        });
    }

    function actualizarTamanoTexto(direccion) {
        var nuevoIndice = indiceEscalaTexto + (direccion > 0 ? 1 : -1);
        if (nuevoIndice < 0 || nuevoIndice >= NIVELES_ESCALA_TEXTO.length) return;
        indiceEscalaTexto = nuevoIndice;

        var label = el('ghz-tamano-label');
        if (label) label.textContent = Math.round(getEscalaTextoActual() * 100) + '%';

        Object.keys(cachePorCanvasHz).forEach(function (canvasId) {
            var item = cachePorCanvasHz[canvasId];
            if (!item || !item.chartInstance) return;
            var chart = item.chartInstance;
            var wrapper = item.wrapperId ? el(item.wrapperId) : null;
            if (wrapper && Array.isArray(item.registrosFiltrados)) {
                wrapper.style.minWidth = Math.max(600, item.registrosFiltrados.length * getColWidthPorLote()) + 'px';
            }
            var escalas = chart.options.scales || {};
            Object.keys(escalas).forEach(function (scaleKey) {
                var sc = escalas[scaleKey];
                if (scaleKey === 'x') {
                    if (sc.ticks && sc.ticks.font) sc.ticks.font.size = getTamanoFuente(10.5);
                } else {
                    if (sc.ticks && sc.ticks.font) sc.ticks.font.size = getTamanoFuente(10);
                    if (sc.title && sc.title.font) sc.title.font.size = getTamanoFuente(11);
                }
            });
            if (Array.isArray(chart.data.datasets)) {
                chart.data.datasets.forEach(function (ds) {
                    if (ds.datalabels && ds.datalabels.font && ds.datalabels.display) {
                        ds.datalabels.font.size = getTamanoFuente(11);
                    }
                });
            }
            chart.resize();
            chart.update();
        });

        Object.keys(instanciasGraficosHz).forEach(function (canvasId) {
            if (canvasId.indexOf('-pct') === -1) return;
            var pctChart = instanciasGraficosHz[canvasId];
            var mainChart = instanciasGraficosHz[canvasId.replace(/-pct$/, '')];
            if (pctChart && mainChart) {
                sincronizarEstiloPctConPrincipal(mainChart, pctChart);
            }
        });
    }

    function sincronizarEstadoCheckSeparar() {
        var sexo = el('ghz-sexo') ? el('ghz-sexo').value : 'macho';
        var chk = el('ghz-separar');
        var chip = el('ghz-chip-separar');
        if (!chk) return;
        var esAmbos = sexo === 'ambos';
        chk.disabled = !esAmbos;
        if (!esAmbos && chk.checked) {
            chk.checked = false;
        }
        if (chip) {
            chip.classList.toggle('hz-dis', !esAmbos);
            chip.classList.toggle('hz-on', esAmbos && chk.checked);
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       Eventos
       ═══════════════════════════════════════════════════════════════ */
    function bindEventos() {
        var btnCargar = el('ghz-btn-cargar');
        if (btnCargar) btnCargar.addEventListener('click', consultarGraficos);

        var btnLimpiar = el('ghz-btn-limpiar');
        if (btnLimpiar) btnLimpiar.addEventListener('click', function () {
            limpiarGraficos();
            var cont = el('ghz-graficos');
            if (cont) {
                cont.innerHTML = '<div class="hz-card-grafica" id="ghz-vacio"><div class="hz-empty">' +
                    '<i class="fas fa-chart-line"></i>' +
                    '<span>Selecciona los filtros y presiona <b>Graficar</b> para ver el acumulado semanal de todas las granjas.</span>' +
                    '</div></div>';
            }
        });

        var toggle = el('ghz-toggle-filtros');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var cuerpo = el('ghz-cuerpo-filtros');
                var abierto = toggle.classList.toggle('hz-abierto');
                toggle.setAttribute('aria-expanded', abierto ? 'true' : 'false');
                if (cuerpo) cuerpo.style.display = abierto ? '' : 'none';
            });
        }

        var sexo = el('ghz-sexo');
        if (sexo) {
            sexo.addEventListener('change', function () {
                var chkSep = el('ghz-separar');
                if (sexo.value !== 'ambos' && chkSep && chkSep.checked) {
                    chkSep.checked = false;
                    separarGraficos = false;
                }
                sincronizarEstadoCheckSeparar();
                actualizarSexo(sexo.value);
            });
        }

        var chkSep = el('ghz-separar');
        if (chkSep) {
            chkSep.addEventListener('change', function () {
                var chip = chkSep.closest ? chkSep.closest('.hz-chip-sep') : null;
                if (chip) chip.classList.toggle('hz-on', chkSep.checked);
                actualizarSeparacion(chkSep.checked);
            });
        }

        var modo = el('ghz-modo-combinado');
        if (modo) {
            modo.addEventListener('change', function () {
                modoCombinado = modo.value;
                consultarGraficos();
            });
        }

        var btnInc = el('ghz-tamano-inc');
        var btnDec = el('ghz-tamano-dec');
        if (btnInc) btnInc.addEventListener('click', function () { actualizarTamanoTexto(1); });
        if (btnDec) btnDec.addEventListener('click', function () { actualizarTamanoTexto(-1); });

        ['ghz-fecha-inicio', 'ghz-fecha-fin'].forEach(function (id) {
            var inp = el(id);
            if (inp) {
                inp.addEventListener('change', function () {
                    cargarCatalogoGranjas();
                });
            }
        });
    }

    /* ═══════════════════════════════════════════════════════════════
       Init
       ═══════════════════════════════════════════════════════════════ */
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof CustomMultiSelect === 'function') {
            msTipos = new CustomMultiSelect({
                btnId: 'ms-tipos-btn',
                labelId: 'ms-tipos-label',
                dropdownId: 'ms-tipos-dropdown',
                searchId: 'ms-tipos-search',
                optionsContainerId: 'ms-tipos-options',
                title: 'Tipo de gráficas',
                options: [],
                onChange: function () {
                    gestionarVisibilidadFiltros();
                }
            });
            msDias = new CustomMultiSelect({
                btnId: 'ms-dias-btn',
                labelId: 'ms-dias-label',
                dropdownId: 'ms-dias-dropdown',
                searchId: 'ms-dias-search',
                optionsContainerId: 'ms-dias-options',
                title: 'Días de pesaje',
                options: [],
                onChange: function () {}
            });
        }
        bindEventos();
        sincronizarEstadoCheckSeparar();
        cargaInicial();
    });

    window.GrafHzConsultar = consultarGraficos;
})();
