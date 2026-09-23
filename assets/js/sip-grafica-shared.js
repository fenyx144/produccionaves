/**
 * sip-grafica-shared.js
 * Libreria compartida para graficas Chart.js en modulos SIP.
 * Proporciona paletas, plugins, y helpers reutilizables.
 * Cargar siempre ANTES de los renderers especificos (hc_grafica-render.php, gt_grafica-render.php, etc.).
 */
(function () {
    'use strict';

    var SGS = {};

    // -----------------------------------------------------------------------
    // Paletas y constantes
    // -----------------------------------------------------------------------

    SGS.COLOR_PALETTE = [
        '#2563eb', '#3b82f6', '#60a5fa', '#1d4ed8',
        '#93c5fd', '#2563eb', '#3b82f6', '#1d4ed8'
    ];
    SGS.POINT_STYLES = [
        'circle', 'triangle', 'rect', 'cross',
        'star', 'dash', 'rectRot', 'rectRounded'
    ];
    SGS.STD_COLOR = '#16a34a';    // verde para lineas de estandar
    SGS.STD_DASH = [6, 3];
    SGS.CHART_ANIM_DURATION = 780;
    SGS.CHART_ANIM_INTRO_MS = 720;

    // -----------------------------------------------------------------------
    // Animaciones Chart.js 4 (barras desde base, lineas con trazo, franjas fade)
    // -----------------------------------------------------------------------

    SGS.getIntroProgress = function (chart) {
        if (!chart || chart._sipIntroStart == null) return 1;
        var dur = chart._sipIntroDur || SGS.CHART_ANIM_INTRO_MS;
        return Math.min(1, (Date.now() - chart._sipIntroStart) / dur);
    };

    SGS.pluginIntroProgress = {
        id: 'sipIntroProgress',
        beforeInit: function (chart) {
            chart._sipIntroStart = Date.now();
            chart._sipIntroDur = SGS.CHART_ANIM_INTRO_MS;
        },
        afterDraw: function (chart) {
            if (SGS.getIntroProgress(chart) >= 1) return;
            if (chart._sipIntroRaf) return;
            chart._sipIntroRaf = requestAnimationFrame(function () {
                chart._sipIntroRaf = null;
                if (chart._destroyed || !chart.ctx) return;
                if (SGS.getIntroProgress(chart) < 1) chart.draw();
            });
        }
    };

    SGS._animDelay = function (context, duration, delayStep, dsDelay) {
        if (context.type !== 'data') return 0;
        if (context.dataset && context.dataset._hcStdDs) {
            return Math.round(duration * 0.42);
        }
        return context.dataIndex * delayStep + context.datasetIndex * dsDelay;
    };

    SGS._animYFrom = function (context) {
        if (context.type !== 'data') return undefined;
        var scale = context.chart.scales.y;
        if (scale && typeof scale.getBasePixel === 'function') {
            return scale.getBasePixel();
        }
        return undefined;
    };

    SGS._animXFrom = function (context) {
        if (context.type !== 'data') return undefined;
        var scale = context.chart.scales.x;
        if (!scale) return undefined;
        var min = scale.min;
        if (min == null || !isFinite(min)) min = 0;
        return scale.getPixelForValue(min);
    };

    /**
     * Opciones de animacion para Chart.js 4.
     * @param {'bar'|'line'} chartType
     * @param {{duration?:number,stagger?:boolean,delayStep?:number,dsDelay?:number}} extra
     */
    SGS.chartAnimations = function (chartType, extra) {
        extra = extra || {};
        var isLine = chartType === 'line';
        var duration = extra.duration != null ? extra.duration : SGS.CHART_ANIM_DURATION;
        var stagger = extra.stagger !== false;
        var delayStep = extra.delayStep != null ? extra.delayStep : (isLine ? 16 : 26);
        var dsDelay = extra.dsDelay != null ? extra.dsDelay : (isLine ? 35 : 55);
        var delayFn = stagger
            ? function (ctx) { return SGS._animDelay(ctx, duration, delayStep, dsDelay); }
            : undefined;

        var out = {
            animation: {
                duration: duration,
                easing: 'easeOutQuart',
                delay: delayFn
            },
            animations: {
                y: {
                    type: 'number',
                    easing: 'easeOutCubic',
                    duration: duration,
                    from: SGS._animYFrom,
                    delay: delayFn
                },
                colors: {
                    type: 'color',
                    duration: Math.min(duration, 520),
                    easing: 'easeOutQuad',
                    delay: delayFn
                },
                radius: {
                    type: 'number',
                    duration: 420,
                    easing: 'easeOutQuad',
                    delay: delayFn
                }
            },
            transitions: {
                active: {
                    animation: { duration: 220, easing: 'easeOutQuad' }
                },
                resize: {
                    animation: { duration: 0 }
                },
                show: {
                    animation: { duration: 420, easing: 'easeOutQuart' }
                },
                hide: {
                    animation: { duration: 280, easing: 'easeInQuad' }
                }
            }
        };

        if (isLine) {
            out.animations.x = {
                type: 'number',
                easing: 'easeOutQuart',
                duration: Math.round(duration * 0.88),
                from: SGS._animXFrom,
                delay: delayFn
            };
            out.animations.tension = {
                type: 'number',
                easing: 'easeOutQuad',
                duration: Math.round(duration * 0.75),
                from: 0.35,
                to: 0.2,
                delay: delayFn
            };
        }

        return out;
    };

    /** Mezcla animation / animations / transitions en options de Chart.js. */
    SGS.applyChartAnimations = function (options, chartType, extra) {
        options = options || {};
        var anim = SGS.chartAnimations(chartType, extra);
        options.animation = anim.animation;
        options.animations = anim.animations;
        options.transitions = anim.transitions;
        return options;
    };

    /** Plugins base recomendados (intro + opcional extra). */
    SGS.baseChartPlugins = function (extraPlugins) {
        var list = [SGS.pluginIntroProgress];
        if (extraPlugins && extraPlugins.length) {
            extraPlugins.forEach(function (p) { if (p) list.push(p); });
        }
        return list;
    };

    // -----------------------------------------------------------------------
    // Formateo inteligente de valores numericos
    // -----------------------------------------------------------------------

    SGS.fmtVal = function (v) {
        if (v == null || !isFinite(v)) return '';
        if (v === 0) return '0';
        var av = Math.abs(v);
        if (av >= 1000) return v.toFixed(0);
        if (av >= 1) return v.toFixed(2);
        if (av >= 0.01) return v.toFixed(3);
        if (av >= 0.001) return v.toFixed(4);
        if (av >= 1e-6) return v.toFixed(6);
        return v.toExponential(2);
    };

    // -----------------------------------------------------------------------
    // Calculo de rango Y dinamico (excluye datasets de estandar)
    // -----------------------------------------------------------------------

    SGS.calcYRange = function (datasets, stdMin, stdMax, dataPorDia) {
        var vals = [];
        datasets.forEach(function (ds) {
            if (ds._hcStdDs) return;
            (ds.data || []).forEach(function (v) {
                if (v != null && isFinite(v)) vals.push(v);
            });
        });
        if (Array.isArray(dataPorDia)) {
            dataPorDia.forEach(function (dd) {
                if (dd.stdMin != null && isFinite(dd.stdMin)) vals.push(dd.stdMin);
                if (dd.stdMax != null && isFinite(dd.stdMax)) vals.push(dd.stdMax);
            });
        } else {
            if (stdMin != null && isFinite(stdMin)) vals.push(stdMin);
            if (stdMax != null && isFinite(stdMax)) vals.push(stdMax);
        }
        if (!vals.length) return { min: 0, max: 1 };
        var minV = Math.min.apply(null, vals);
        var maxV = Math.max.apply(null, vals);
        var span = maxV - minV;
        var pad = span > 0 ? span * 0.18 : Math.max(Math.abs(maxV), Math.abs(minV), 0.01) * 0.15;
        var yMin = minV < 0 ? (minV - pad) : (minV === 0 ? -pad * 0.4 : 0);
        var yMax = maxV + pad;
        if (minV >= 0 && maxV === minV) {
            yMax = maxV > 0 ? maxV * 1.15 : 0.01;
        }
        return { min: yMin, max: yMax };
    };

    // -----------------------------------------------------------------------
    // Construir dataset de linea de estandar (verde, dashed)
    // -----------------------------------------------------------------------

    SGS.buildStdDataset = function (label, data) {
        return {
            label: label,
            data: data,
            type: 'line',
            borderColor: SGS.STD_COLOR,
            backgroundColor: 'transparent',
            borderWidth: 2,
            borderDash: SGS.STD_DASH,
            pointRadius: 3,
            pointHoverRadius: 4,
            spanGaps: true,
            fill: false,
            tension: 0.1,
            _hcStdDs: true
        };
    };

    // -----------------------------------------------------------------------
    // Plugin de etiquetas de datos con deteccion de colision
    // -----------------------------------------------------------------------

    SGS.customDataLabelsPlugin = {
        id: 'customDataLabels',
        afterDatasetsDraw: function (chart) {
            /* afterDatasetsDraw → bajo tooltip (no tapa la leyenda hover) */
            var ctx2 = chart.ctx;
            var area = chart.chartArea;
            if (area) {
                ctx2.save();
                ctx2.beginPath();
                ctx2.rect(area.left, area.top, area.right - area.left, area.bottom - area.top);
                ctx2.clip();
            }
            var regDsIndices = [];
            chart.data.datasets.forEach(function (ds, i) {
                if (!ds._hcStdDs) regDsIndices.push(i);
            });
            var totalRegDs = regDsIndices.length;
            var labelStep = 1;
            if (totalRegDs >= 6) labelStep = 4;
            else if (totalRegDs >= 4) labelStep = 3;
            else if (totalRegDs >= 3) labelStep = 2;

            var drawnLabels = [];
            function overlap(x, y, tw, th) {
                for (var di = 0; di < drawnLabels.length; di++) {
                    var d = drawnLabels[di];
                    if (Math.abs(x - d.x) < (tw + d.w) * 0.5 && Math.abs(y - d.y) < (th + d.h) * 0.4) {
                        return true;
                    }
                }
                return false;
            }

            chart.data.datasets.forEach(function (ds, i) {
                var meta = chart.getDatasetMeta(i);
                if (ds._hcStdDs) {
                    return;
                }

                var relIdx = regDsIndices.indexOf(i);
                var arriba = (relIdx % 2 === 0);
                meta.data.forEach(function (el, ix) {
                    var val = ds.data[ix];
                    if (val == null || !isFinite(val)) return;
                    if (labelStep > 1 && ix % labelStep !== 0) return;
                    var pos = el.getCenterPoint();
                    if (area && (pos.x < area.left || pos.x > area.right || pos.y < area.top || pos.y > area.bottom)) {
                        return;
                    }
                    var txt = SGS.fmtVal(val);
                    ctx2.save();
                    ctx2.font = 'bold 11px sans-serif';
                    ctx2.fillStyle = '#334155';
                    ctx2.textAlign = 'center';
                    ctx2.textBaseline = 'bottom';
                    var tm = ctx2.measureText(txt);
                    /* Nodos: siempre rotar 90° (etiqueta vertical junto al punto). */
                    var rotate = true;
                    if (window._hcGraficaForceRotateNodeLabels === false) rotate = false;
                    var tw = rotate ? 14 : tm.width;
                    var th = rotate ? tm.width : 14;
                    var labelY = arriba ? (pos.y - 10) : (pos.y + 14);
                    if (overlap(pos.x, labelY, tw, th)) {
                        ctx2.restore();
                        return;
                    }
                    if (rotate) {
                        ctx2.translate(pos.x + 3, labelY - 10);
                        ctx2.rotate(-Math.PI / 2);
                        ctx2.fillText(txt, 3, 3);
                    } else {
                        ctx2.fillText(txt, pos.x, labelY);
                    }
                    drawnLabels.push({ x: pos.x, y: labelY, w: tw, h: th });
                    ctx2.restore();
                });
            });
            if (area) ctx2.restore();
        }
    };

    // -----------------------------------------------------------------------
    // Handle de redimension de canvas (drag-to-resize)
    // -----------------------------------------------------------------------

    SGS.initResizeHandle = function (handleId, wrapId, getChartFn) {
        var handle = document.getElementById(handleId);
        var wrap = document.getElementById(wrapId);
        if (!handle || !wrap) return;
        var lsKey = 'sip_grafica_canvas_height';
        var savedH = parseInt(localStorage.getItem(lsKey), 10);
        if (savedH > 200 && savedH < 2000) {
            wrap.style.minHeight = savedH + 'px';
        }
        function onMove(e) {
            var rect = wrap.parentElement.getBoundingClientRect();
            var maxH = rect.height - 40;
            var minH = 200;
            var clientY = e.touches ? e.touches[0].clientY : e.clientY;
            var wrapRect = wrap.getBoundingClientRect();
            var newH = Math.max(minH, Math.min(maxH, clientY - wrapRect.top + wrapRect.height));
            wrap.style.height = newH + 'px';
            wrap.style.minHeight = newH + 'px';
            var chart = typeof getChartFn === 'function' ? getChartFn() : null;
            if (chart && typeof chart.resize === 'function') {
                chart.resize();
            }
        }
        function onUp() {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            window.removeEventListener('touchmove', onMove);
            window.removeEventListener('touchend', onUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            try { localStorage.setItem(lsKey, wrap.style.minHeight || wrap.style.height); } catch (ex) {}
        }
        function onDown(e) {
            e.preventDefault();
            document.body.style.cursor = 'ns-resize';
            document.body.style.userSelect = 'none';
            window.addEventListener('mousemove', onMove);
            window.addEventListener('mouseup', onUp);
            window.addEventListener('touchmove', onMove, { passive: true });
            window.addEventListener('touchend', onUp);
        }
        handle.addEventListener('mousedown', onDown);
        handle.addEventListener('touchstart', onDown, { passive: false });
    };

    // -----------------------------------------------------------------------
    // Pan con mouse (arrastrar para desplazar) + doble click reset
    // -----------------------------------------------------------------------

    SGS.initPan = function (cvId, getChartFn, getRenderFn) {
        var cv = document.getElementById(cvId);
        if (!cv) return;
        if (cv.getAttribute('data-sip-pan-inited')) return;
        cv.setAttribute('data-sip-pan-inited', '1');
        var isPanning = false;
        var startClientX = 0;
        var startClientY = 0;
        var origMin = null;
        var origMax = null;
        var origYMin = null;
        var origYMax = null;

        function onPd(e) {
            var chart = typeof getChartFn === 'function' ? getChartFn() : null;
            if (!chart) return;
            var chartArea = chart.chartArea;
            if (chartArea) {
                var rect = cv.getBoundingClientRect();
                var pt = e.touches ? e.touches[0] : e;
                var x = pt.clientX - rect.left;
                var y = pt.clientY - rect.top;
                if (x < chartArea.left || x > chartArea.right || y < chartArea.top || y > chartArea.bottom) return;
            }
            isPanning = true;
            var pt = e.touches ? e.touches[0] : e;
            startClientX = pt.clientX;
            startClientY = pt.clientY;
            var xScale = chart.scales && chart.scales.x;
            if (xScale) {
                origMin = xScale.min;
                origMax = xScale.max;
            }
            var yScale = chart.scales && chart.scales.y;
            if (yScale) {
                origYMin = yScale.min;
                origYMax = yScale.max;
            }
            cv.style.cursor = 'grabbing';
        }
        function onPm(e) {
            var chart = typeof getChartFn === 'function' ? getChartFn() : null;
            if (!isPanning || !chart) return;
            var pt = e.touches ? e.touches[0] : e;
            var dx = startClientX - pt.clientX;
            var dy = startClientY - pt.clientY;
            if (Math.abs(dx) < 3 && Math.abs(dy) < 3) return;
            var xScale = chart.scales && chart.scales.x;
            var yScale = chart.scales && chart.scales.y;
            if (xScale && origMin != null && origMax != null) {
                var range = origMax - origMin;
                if (range > 0) {
                    var pixRange = xScale.right - xScale.left;
                    if (pixRange > 0) {
                        var valPerPix = range / pixRange;
                        var newMin = origMin + dx * valPerPix;
                        var newMax = origMax + dx * valPerPix;
                        chart.options.scales.x.min = newMin;
                        chart.options.scales.x.max = newMax;
                    }
                }
            }
            if (yScale && origYMin != null && origYMax != null) {
                var yPixRange = yScale.bottom - yScale.top;
                if (yPixRange > 0) {
                    var yRange = origYMax - origYMin;
                    if (yRange > 0) {
                        var yValPerPix = yRange / yPixRange;
                        chart.options.scales.y.min = origYMin + dy * yValPerPix;
                        chart.options.scales.y.max = origYMax + dy * yValPerPix;
                    }
                }
            }
            chart.update('none');
        }
        function onPu() {
            isPanning = false;
            cv.style.cursor = '';
        }
        cv.addEventListener('mousedown', onPd);
        window.addEventListener('mousemove', onPm);
        window.addEventListener('mouseup', onPu);
        cv.addEventListener('touchstart', onPd, { passive: true });
        window.addEventListener('touchmove', onPm, { passive: true });
        window.addEventListener('touchend', onPu);
        // Doble click: reset view
        cv.addEventListener('dblclick', function () {
            if (typeof getRenderFn === 'function') {
                getRenderFn();
            }
        });
    };

    // -----------------------------------------------------------------------
    // Plugin: barras plagas (cero medido + barra minima)
    // -----------------------------------------------------------------------

    SGS.pluginPlagasBarVis = {
        id: 'sipPlagasBarVis',
        afterDatasetsDraw: function (chart, _args, pluginOpts) {
            var opts = pluginOpts || {};
            if (!opts.enabled && chart.options && chart.options.plugins && chart.options.plugins.sipPlagasBarVis) {
                opts = chart.options.plugins.sipPlagasBarVis;
            }
            if (!opts.enabled) return;
            var minPx = opts.minBarPx != null ? Number(opts.minBarPx) : 8;
            if (!isFinite(minPx) || minPx < 1) minPx = 8;
            var minUnderOne = opts.minBarPxUnderOne != null ? Number(opts.minBarPxUnderOne) : 16;
            if (!isFinite(minUnderOne) || minUnderOne < minPx) minUnderOne = 16;
            var zeroPx = opts.zeroBarPx != null ? Number(opts.zeroBarPx) : 3;
            if (!isFinite(zeroPx) || zeroPx < 1) zeroPx = 3;
            var ctx = chart.ctx;
            var area = chart.chartArea;
            var xScale = chart.scales && chart.scales.x;
            var yScale = chart.scales && chart.scales.y;
            if (!ctx || !area || !xScale || !yScale) return;

            var datasets = (chart.data && chart.data.datasets) || [];
            var labels = (chart.data && chart.data.labels) || [];
            var nLabels = labels.length;
            var i;
            var di;

            function esBarraDs(ds, idx) {
                if (!ds) return false;
                var meta = chart.getDatasetMeta(idx);
                if (meta && meta.hidden) return false;
                var t = ds.type;
                if (!t && chart.config) {
                    t = chart.config.type;
                }
                if (!t && chart.config && chart.config._config) {
                    t = chart.config._config.type;
                }
                return t === 'bar' || !t;
            }

            function pixelX(idx) {
                var x = null;
                if (typeof xScale.getPixelForTick === 'function') {
                    x = xScale.getPixelForTick(idx);
                }
                if (x == null || !isFinite(x)) {
                    x = xScale.getPixelForValue(idx);
                }
                if ((x == null || !isFinite(x)) && labels[idx] != null) {
                    x = xScale.getPixelForValue(labels[idx]);
                }
                return x;
            }

            ctx.save();

            for (di = 0; di < datasets.length; di++) {
                var ds = datasets[di];
                if (!esBarraDs(ds, di)) continue;
                var meta = chart.getDatasetMeta(di);
                if (!meta || !meta.data) continue;
                for (i = 0; i < meta.data.length; i++) {
                    var bar = meta.data[i];
                    if (!bar) continue;
                    var raw = ds.data ? ds.data[i] : null;
                    if (raw === null || raw === undefined || raw === '') continue;
                    var v = Number(raw);
                    if (!isFinite(v)) continue;
                    var w = bar.width || 0;
                    if (w <= 0 || bar.base == null) continue;
                    var fill = ds.backgroundColor || ds.borderColor || '#64748b';
                    if (v === 0) {
                        ctx.fillStyle = fill;
                        ctx.fillRect(bar.x - (w / 2), bar.base - zeroPx, w, zeroPx);
                        ctx.save();
                        ctx.translate(bar.x, bar.base - zeroPx - 10);
                        ctx.rotate(-Math.PI / 2);
                        ctx.fillStyle = ds.borderColor || fill;
                        ctx.font = 'bold 10px Inter, system-ui, sans-serif';
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        ctx.fillText('0', 0, 0);
                        ctx.restore();
                        continue;
                    }
                    var needPx = (Math.abs(v) > 0 && Math.abs(v) < 1) ? minUnderOne : minPx;
                    var h = Math.abs((bar.y != null ? bar.y : 0) - (bar.base != null ? bar.base : 0));
                    if (h >= needPx) continue;
                    ctx.fillStyle = fill;
                    if (v > 0) {
                        ctx.fillRect(bar.x - (w / 2), bar.base - needPx, w, needPx);
                    } else {
                        ctx.fillRect(bar.x - (w / 2), bar.base, w, needPx);
                    }
                }
            }

            ctx.restore();
        }
    };

    SGS.pluginHoyLine = {
        id: 'hoyLine',
        afterDatasetsDraw: function (chart) {
            /* afterDatasetsDraw → bajo tooltip (no tapa la leyenda hover) */
            var opts = chart.options.plugins && chart.options.plugins.hoyLine;
            if (!opts || opts.edadHoy == null) return;
            var edadHoy = opts.edadHoy;
            var xScale = chart.scales && chart.scales.x;
            if (!xScale) return;

            // Encontrar la posicion x para edadHoy
            var xPos = xScale.getPixelForValue(edadHoy);
            if (xPos === undefined || isNaN(xPos)) return;

            var ctx = chart.ctx;
            var chartArea = chart.chartArea;
            if (!chartArea) return;

            ctx.save();
            // Franja semitransparente
            ctx.fillStyle = 'rgba(239, 68, 68, 0.06)';
            var stripeWidth = (xScale.getPixelForValue(edadHoy + 0.5) - xScale.getPixelForValue(edadHoy - 0.5)) || 30;
            ctx.fillRect(xPos - stripeWidth / 2, chartArea.top, stripeWidth, chartArea.bottom - chartArea.top);

            // Linea vertical punteada roja
            ctx.setLineDash([5, 5]);
            ctx.strokeStyle = '#ef4444';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(xPos, chartArea.top);
            ctx.lineTo(xPos, chartArea.bottom);
            ctx.stroke();

            // Etiqueta "Hoy" arriba
            ctx.setLineDash([]);
            ctx.fillStyle = '#ef4444';
            ctx.font = 'bold 11px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.fillText('Hoy', xPos, chartArea.top - 4);
            ctx.restore();
        }
    };

    // -----------------------------------------------------------------------
    // Variación % vs estándar (tipo Tableau)
    // -----------------------------------------------------------------------

    SGS.TIPOS_VAR_PCT = [
        'mortalidad', 'consumo_alimento', 'consumo_gas', 'consumo_agua', 'pesaje_pollo', 'ganancia_peso'
    ];

    SGS.TIPOS_VAR_PCT_SPAN_GAPS = ['pesaje_pollo', 'ganancia_peso'];

    SGS.varPctUsaSpanGaps = function (tipoOrTaskKey) {
        var t = String(tipoOrTaskKey || '').toLowerCase();
        if (SGS.TIPOS_VAR_PCT_SPAN_GAPS.indexOf(t) !== -1) return true;
        var tk = String(tipoOrTaskKey || '').toUpperCase();
        return tk === 'CRIANZA_PESAJE_DE_POLLO' || tk === 'CRIANZA_GANANCIA_DE_PESO';
    };

    SGS.HC_TASKS_VAR_PCT = [
        'CRIANZA_MORTALIDAD_DIARIA',
        'CRIANZA_CONSUMO_DE_ALIMENTO',
        'CRIANZA_CONSUMO_DE_GAS',
        'CRIANZA_CONSUMO_DE_AGUA',
        'CRIANZA_PESAJE_DE_POLLO',
        'CRIANZA_GANANCIA_DE_PESO'
    ];

    SGS.COLOR_CUMPLE_PCT = '#16a34a';
    SGS.COLOR_NO_CUMPLE_PCT = '#dc2626';

    SGS.soportaVarPct = function (tipo, opts) {
        opts = opts || {};
        if (SGS.TIPOS_VAR_PCT.indexOf(tipo) === -1) return false;
        if (tipo === 'mortalidad' && opts.graficoActivo && opts.graficoActivo !== 'linea') return false;
        return true;
    };

    SGS.soportaVarPctHc = function (taskKey) {
        return SGS.HC_TASKS_VAR_PCT.indexOf(String(taskKey || '').toUpperCase()) !== -1;
    };

    SGS.calcVarPct = function (actual, std) {
        if (actual == null || std == null || !isFinite(actual) || !isFinite(std) || std === 0) return null;
        return ((actual - std) / std) * 100;
    };

    SGS.fmtVarPct = function (val) {
        if (val == null || !isFinite(val)) return '';
        var sign = val > 0 ? '+' : '';
        return sign + val.toFixed(2) + '%';
    };

    SGS.buildVarPctData = function (actualArr, stdArr, evalCumpleFn) {
        actualArr = actualArr || [];
        stdArr = stdArr || [];
        var n = Math.max(actualArr.length, stdArr.length);
        var data = [];
        var cumple = [];
        for (var i = 0; i < n; i++) {
            var actual = actualArr[i];
            var std = stdArr[i];
            var pct = SGS.calcVarPct(actual, std);
            data.push(pct);
            if (pct == null) {
                cumple.push(null);
            } else if (typeof evalCumpleFn === 'function') {
                cumple.push(evalCumpleFn(actual, std, i));
            } else {
                cumple.push(null);
            }
        }
        return { data: data, cumple: cumple };
    };

    SGS.pctLegendColor = function (dataset) {
        if (!dataset) return '#2563eb';
        if (dataset._legendColor) return dataset._legendColor;
        var c = dataset.borderColor;
        return (typeof c === 'string' && c) ? c : '#2563eb';
    };

    SGS.pctParseSexoLabel = function (label) {
        var l = String(label || '').trim();
        if (/\sM$/.test(l) || l === 'M') return { sexo: 'M', base: l.replace(/\sM$/, '').trim() };
        if (/\sH$/.test(l) || l === 'H') return { sexo: 'H', base: l.replace(/\sH$/, '').trim() };
        return { sexo: '', base: l };
    };

    SGS.pctChartTieneAmbosSexos = function (chart) {
        var ds = (chart && chart.data && chart.data.datasets) || [];
        var hasM = false;
        var hasH = false;
        ds.forEach(function (d) {
            var p = SGS.pctParseSexoLabel(d.label);
            if (p.sexo === 'M') hasM = true;
            if (p.sexo === 'H') hasH = true;
        });
        return hasM && hasH;
    };

    SGS.pctMetricasDistintas = function (chart) {
        var bases = {};
        ((chart && chart.data && chart.data.datasets) || []).forEach(function (d) {
            var b = SGS.pctParseSexoLabel(d.label).base;
            if (b) bases[b] = true;
        });
        return Object.keys(bases).length;
    };

    SGS.formatPctTooltipLabel = function (ctx) {
        var val = ctx.parsed.y;
        if (val == null || !isFinite(val)) return null;
        var parsed = SGS.pctParseSexoLabel(ctx.dataset.label);
        var txtVal = SGS.fmtVarPct(val);
        if (SGS.pctChartTieneAmbosSexos(ctx.chart) && parsed.sexo) {
            if (SGS.pctMetricasDistintas(ctx.chart) > 1 && parsed.base) {
                return parsed.base + ' · ' + parsed.sexo + ': ' + txtVal;
            }
            return parsed.sexo + ': ' + txtVal;
        }
        var lbl = ctx.dataset.label || '';
        return (lbl ? lbl + ': ' : '') + txtVal;
    };

    SGS.formatPctTooltipLabelColor = function (ctx) {
        var c = SGS.pctLegendColor(ctx.dataset);
        return { borderColor: c, backgroundColor: c };
    };

    SGS.buildVarPctDatasets = function (seriesList, opts) {
        opts = opts || {};
        var n = (seriesList || []).length;
        var useBar = opts.useBar != null ? opts.useBar : n > 1;
        var spanGapsLine = false;
        if (!useBar) {
            spanGapsLine = opts.spanGaps != null
                ? !!opts.spanGaps
                : SGS.varPctUsaSpanGaps(opts.tipo || opts.taskKey);
        }
        return (seriesList || []).map(function (s) {
            var cumpleArr = s.cumple || [];
            var color = s.borderColor || '#2563eb';
            return {
                label: s.label || '',
                data: s.data || [],
                type: useBar ? 'bar' : 'line',
                borderColor: color,
                backgroundColor: useBar ? color : 'transparent',
                _legendColor: color,
                borderWidth: useBar ? 1.5 : 2,
                pointRadius: useBar ? 0 : 4,
                pointHoverRadius: useBar ? 0 : 6,
                barPercentage: useBar ? 0.92 : undefined,
                categoryPercentage: useBar ? 0.82 : undefined,
                minBarLength: useBar ? 6 : undefined,
                spanGaps: spanGapsLine,
                tension: 0,
                fill: false,
                _varPctDs: true,
                _cumpleArr: cumpleArr,
                pointBackgroundColor: function (ctx) {
                    var c = cumpleArr[ctx.dataIndex];
                    if (c === false) return SGS.COLOR_NO_CUMPLE_PCT;
                    if (c === true) return SGS.COLOR_CUMPLE_PCT;
                    return '#64748b';
                },
                pointBorderColor: function (ctx) {
                    var c = cumpleArr[ctx.dataIndex];
                    if (c === false) return SGS.COLOR_NO_CUMPLE_PCT;
                    if (c === true) return SGS.COLOR_CUMPLE_PCT;
                    return '#64748b';
                }
            };
        });
    };

    SGS.calcAdaptiveFontSize = function (chartWidth, count, opts) {
        opts = opts || {};
        var min = opts.min != null ? opts.min : 8;
        var max = opts.max != null ? opts.max : 14;
        if (!chartWidth || !count) return max;
        var size = Math.floor(chartWidth / Math.max(count, 1) / 2.5);
        return Math.max(min, Math.min(max, size));
    };

    SGS.pctLabelCountForFont = function (chart, labelCount) {
        labelCount = Math.max(labelCount || 1, 1);
        if (!chart || !chart.data) return labelCount;
        var labels = chart.data.labels || [];
        var n = labels.length || labelCount;
        var start = 0;
        var end = n - 1;
        if (chart.scales && chart.scales.x) {
            var x = chart.scales.x;
            if (x.min != null && isFinite(x.min)) start = Math.max(0, Math.floor(x.min));
            if (x.max != null && isFinite(x.max)) end = Math.min(n - 1, Math.ceil(x.max));
        }
        var span = Math.max(1, end - start + 1);
        var withData = {};
        (chart.data.datasets || []).forEach(function (ds) {
            if (!ds || ds._hcStdDs) return;
            (ds.data || []).forEach(function (v, i) {
                if (i < start || i > end) return;
                if (v != null && isFinite(v)) withData[i] = true;
            });
        });
        var uniqueWithData = Object.keys(withData).length;
        if (uniqueWithData >= 2 && uniqueWithData < span * 0.55) {
            return Math.max(uniqueWithData, Math.ceil(span * uniqueWithData / (uniqueWithData + 3)));
        }
        return span;
    };

    SGS.calcPctDatalabelFontSize = function (chartWidth, count, seriesCount, opts) {
        opts = opts || {};
        count = Math.max(count || 1, 1);
        seriesCount = seriesCount || 1;
        var w = chartWidth || 300;
        var cols = opts.columns || (opts.singleFullWidth ? 1 : 3);
        var slotW = w / count;
        var size = Math.floor(slotW / (2.1 + Math.min(seriesCount, 3) * 0.12));
        var maxCap = opts.max;
        var minCap = opts.min;
        if (maxCap == null || minCap == null) {
            if (opts.fullscreen) {
                maxCap = maxCap != null ? maxCap : 18;
                minCap = minCap != null ? minCap : 13;
            } else if (opts.singleFullWidth || cols <= 1) {
                maxCap = maxCap != null ? maxCap : 16;
                minCap = minCap != null ? minCap : 12;
            } else if (cols === 2) {
                maxCap = maxCap != null ? maxCap : 14;
                minCap = minCap != null ? minCap : 11;
            } else {
                maxCap = maxCap != null ? maxCap : 13;
                minCap = minCap != null ? minCap : 10;
            }
        }
        if (w < 260) size = Math.max(size, minCap);
        if (count > 32 && count < 40) size = Math.max(size - 1, minCap);
        return Math.max(minCap, Math.min(maxCap, size));
    };

    SGS.fmtVarPctCompact = function (val) {
        if (val == null || !isFinite(val)) return '';
        var sign = val > 0 ? '+' : '';
        return sign + Number(val).toFixed(2) + '%';
    };

    SGS.applyMainChartDatalabelLayout = function (chart, size) {
        if (!chart || !chart.options || !chart.data) return;
        if (chart._sipApplyingMainLayout) return;
        var plugins = chart.options.plugins;
        if (!plugins || !plugins.datalabels) return;

        var n = chart.data.labels ? chart.data.labels.length : 1;
        var w = (size && size.width) ? size.width : (chart.width || 300);
        var cols = (size && size.columns) || 3;
        var minFs = 8;
        var maxFs = 11;
        if (size && size.fullscreen) {
            minFs = 12;
            maxFs = 16;
        } else if (size && size.singleFullWidth) {
            minFs = 10;
            maxFs = 14;
        } else if (cols === 2) {
            minFs = 9;
            maxFs = 12;
        }
        var fs = SGS.calcAdaptiveFontSize(w, n, { min: minFs, max: maxFs });

        var curFs = plugins.datalabels.font;
        var curSize = typeof curFs === 'object' && curFs ? curFs.size : curFs;
        if (curSize === fs) return;

        chart._sipApplyingMainLayout = true;
        try {
            if (typeof plugins.datalabels.font === 'function') {
                var prevFontFn = plugins.datalabels.font;
                plugins.datalabels.font = function (ctx) {
                    var base = prevFontFn(ctx);
                    if (typeof base === 'object' && base) {
                        return Object.assign({}, base, { size: fs });
                    }
                    return { family: 'Inter', size: fs, weight: '600' };
                };
            } else {
                plugins.datalabels.font = Object.assign({}, plugins.datalabels.font || {}, { size: fs });
            }
        } finally {
            chart._sipApplyingMainLayout = false;
        }
    };

    SGS.shouldShowPctDatalabel = function (ctx) {
        var val = ctx.dataset.data[ctx.dataIndex];
        if (val == null || !isFinite(val)) return false;
        var chart = ctx.chart;
        var n = chart.data.labels ? chart.data.labels.length : 0;
        if (!n) return false;
        var w = chart.width || (chart.canvas ? chart.canvas.clientWidth : 0) || 300;
        var slotW = w / n;
        if (slotW < 3) return false;
        if (slotW >= 6) return true;
        var step = Math.max(2, Math.ceil(6 / Math.max(slotW, 1)));
        return ctx.dataIndex % step === 0;
    };

    SGS.calcPctChartHeight = function (mainHeight, singleFullWidth, chartWidth) {
        var main = mainHeight != null ? mainHeight : 320;
        var w = chartWidth || 600;
        if (singleFullWidth) {
            return Math.min(280, Math.max(165, Math.round(main * 0.38)));
        }
        if (w < 380) {
            return Math.max(165, Math.round(main * 0.42));
        }
        return Math.min(260, Math.max(185, Math.round(main * 0.44)));
    };

    SGS.calcPctLayoutPaddingTop = function (yRange, fontSize, opts) {
        opts = opts || {};
        var fs = fontSize || 8;
        var chars = opts.labelChars || 8;
        var base = Math.round(fs * (2.0 + chars * 0.34));
        if (!yRange) return Math.min(68, Math.max(base, 18));
        var span = (yRange.max || 10) - (yRange.min || -10);
        var extra = Math.round(span * 0.025);
        return Math.min(68, Math.max(base, 16 + extra));
    };

    SGS.calcVarPctYRange = function (datasets, opts) {
        opts = opts || {};
        var startIdx = opts.startIdx != null ? opts.startIdx : 0;
        var endIdx = opts.endIdx != null ? opts.endIdx : Infinity;
        var vals = [];
        (datasets || []).forEach(function (ds) {
            (ds.data || []).forEach(function (v, i) {
                if (i < startIdx || i > endIdx) return;
                if (v != null && isFinite(v)) vals.push(Number(v));
            });
        });
        if (!vals.length) return { min: -10, max: 10 };

        vals.sort(function (a, b) { return a - b; });
        var n = vals.length;
        var rawMin = vals[0];
        var rawMax = vals[n - 1];
        var p05 = vals[Math.max(0, Math.floor(n * 0.08))];
        var p95 = vals[Math.min(n - 1, Math.floor(n * 0.92))];
        var iqr = Math.max(p95 - p05, 8);
        var minV = Math.min(p05 - iqr * 0.15, 0);
        var maxV = Math.max(p95 + iqr * 0.15, 0);

        if (rawMax <= maxV + iqr * 0.45) maxV = Math.max(maxV, rawMax);
        if (rawMin >= minV - iqr * 0.45) minV = Math.min(minV, rawMin);

        minV = Math.min(minV, 0);
        maxV = Math.max(maxV, 0);

        var span = maxV - minV;
        if (span < 18) {
            var mid = (maxV + minV) / 2;
            minV = mid - 11;
            maxV = mid + 11;
            minV = Math.min(minV, 0);
            maxV = Math.max(maxV, 0);
            span = maxV - minV;
        }

        var pad = Math.max(span * 0.14, 5);
        return { min: minV - pad, max: maxV + pad };
    };

    SGS.applyPctYRangeForVisibleX = function (pctChart) {
        if (!pctChart || !pctChart.scales || !pctChart.scales.x || !pctChart.data) return;
        var x = pctChart.scales.x;
        var minIdx = 0;
        var maxIdx = (pctChart.data.labels || []).length - 1;
        if (x.min != null && isFinite(x.min)) minIdx = Math.max(0, Math.floor(x.min));
        if (x.max != null && isFinite(x.max)) maxIdx = Math.min(maxIdx, Math.ceil(x.max));
        var yRange = SGS.calcVarPctYRange(pctChart.data.datasets, { startIdx: minIdx, endIdx: maxIdx });
        if (!pctChart.options.scales) pctChart.options.scales = {};
        if (!pctChart.options.scales.y) pctChart.options.scales.y = {};
        pctChart.options.scales.y.min = yRange.min;
        pctChart.options.scales.y.max = yRange.max;
    };

    SGS.applyPctAdaptiveLayout = function (chart, size) {
        if (!chart || !chart.data || !chart.options) return;
        if (chart._sipApplyingPctLayout) return;

        var labels = chart.data.labels || [];
        var n = labels.length || 1;
        var nForFont = SGS.pctLabelCountForFont(chart, n);
        var w = (size && size.width) ? size.width : (chart.width || (chart.canvas && chart.canvas.parentElement ? chart.canvas.parentElement.clientWidth : 300));
        var seriesCount = (chart.data.datasets || []).filter(function (ds) { return ds && !ds._hcStdDs; }).length;
        var fsOpts = {
            singleFullWidth: size && size.singleFullWidth,
            columns: size && size.columns,
            fullscreen: size && size.fullscreen,
            min: size && size.minFont,
            max: size && size.maxFont
        };
        var fs = SGS.calcPctDatalabelFontSize(w, nForFont, seriesCount, fsOpts);
        SGS.applyPctYRangeForVisibleX(chart);
        var yScale = chart.options.scales && chart.options.scales.y;
        var yRange = {
            min: yScale && yScale.min != null ? yScale.min : -10,
            max: yScale && yScale.max != null ? yScale.max : 10
        };
        var padTop = SGS.calcPctLayoutPaddingTop(yRange, fs, { labelChars: 8 });
        var padRight = Math.max(8, Math.round(fs * 1.2));
        var dlOffset = Math.max(3, Math.round(fs * 0.55));

        var plugins = chart.options.plugins;
        var curFs = plugins && plugins.datalabels && plugins.datalabels.font ? plugins.datalabels.font.size : undefined;
        var curPad = chart.options.layout && chart.options.layout.padding ? chart.options.layout.padding.top : undefined;
        var curYMin = chart.options.scales && chart.options.scales.y ? chart.options.scales.y.min : undefined;
        var curYMax = chart.options.scales && chart.options.scales.y ? chart.options.scales.y.max : undefined;

        if (curFs === fs && curPad === padTop && curYMin === yRange.min && curYMax === yRange.max) return;

        chart._sipApplyingPctLayout = true;
        try {
            if (plugins && plugins.datalabels) {
                if (typeof plugins.datalabels.font === 'function') {
                    if (chart._sipPctLabelFont) {
                        chart._sipPctLabelFont.size = fs;
                    }
                } else {
                    if (!plugins.datalabels.font) plugins.datalabels.font = {};
                    plugins.datalabels.font.size = fs;
                    if (!plugins.datalabels.font.weight) plugins.datalabels.font.weight = '600';
                }
                plugins.datalabels.clip = false;
                plugins.datalabels.offset = dlOffset;
            }
            if (chart._sipPctLabelFont && Array.isArray(chart.data && chart.data.datasets)) {
                chart.data.datasets.forEach(function (ds) {
                    if (!ds || !ds.datalabels) return;
                    ds.datalabels.font = Object.assign({}, chart._sipPctLabelFont);
                });
            }
            if (!chart.options.layout) chart.options.layout = {};
            if (!chart.options.layout.padding) chart.options.layout.padding = {};
            chart.options.layout.padding.top = padTop;
            chart.options.layout.padding.right = padRight;
            if (chart.options.scales && chart.options.scales.y) {
                chart.options.scales.y.min = yRange.min;
                chart.options.scales.y.max = yRange.max;
            }
        } finally {
            chart._sipApplyingPctLayout = false;
        }
    };

    SGS.pluginZeroPctLine = {
        id: 'zeroPctLine',
        afterDraw: function (chart) {
            var yScale = chart.scales && chart.scales.y;
            var area = chart.chartArea;
            if (!yScale || !area) return;
            var y0 = yScale.getPixelForValue(0);
            if (y0 < area.top || y0 > area.bottom) return;
            var ctx = chart.ctx;
            ctx.save();
            ctx.setLineDash([]);
            ctx.strokeStyle = 'var(--sip-border, #94a3b8)';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(area.left, y0);
            ctx.lineTo(area.right, y0);
            ctx.stroke();
            ctx.restore();
        }
    };

    SGS.fmtVarPctAxis = function (val) {
        if (val == null || !isFinite(val)) return '';
        var n = Math.round(val);
        var sign = n > 0 ? '+' : '';
        return sign + n + '%';
    };

    SGS.buildPctActiveElements = function (chart, idx) {
        var active = [];
        if (!chart || !chart.data || !chart.data.datasets) return active;
        chart.data.datasets.forEach(function (ds, di) {
            if (ds._hcStdDs) return;
            active.push({ datasetIndex: di, index: idx });
        });
        return active;
    };

    SGS.alignStackedChartAreas = function (mainChart, pctChart) {
        if (!mainChart || !pctChart || !mainChart.chartArea) return;
        if (!mainChart.canvas || !mainChart.canvas.isConnected) return;
        if (!pctChart.canvas || !pctChart.canvas.isConnected) return;
        var targetLeft = mainChart.chartArea.left;
        var pctY = pctChart.scales && pctChart.scales.y;
        if (!pctY) return;
        if (!pctChart.options.layout) pctChart.options.layout = {};
        if (!pctChart.options.layout.padding) pctChart.options.layout.padding = {};
        var yWidth = pctY.width || 0;
        var newPad = Math.round(Math.max(0, targetLeft - yWidth));
        if (pctChart.options.layout.padding.left !== newPad) {
            pctChart.options.layout.padding.left = newPad;
            pctChart.update('none');
        }
    };

    SGS.linkChartsCrossHighlight = function (chartA, chartB) {
        if (!chartA || !chartB || !chartA.canvas || !chartB.canvas) return;
        chartA._sipPctPartner = chartB;
        chartB._sipPctPartner = chartA;

        function bindSource(source, target) {
            if (source._sipCrossHoverBound && source._sipCrossHoverPartner === target) return;
            source._sipCrossHoverBound = true;
            source._sipCrossHoverPartner = target;

            source.canvas.addEventListener('mousemove', function (evt) {
                var pts = source.getElementsAtEventForMode(evt, 'index', { intersect: false }, false);
                if (!pts || !pts.length) {
                    if (target._sipCrossActiveIdx != null) {
                        target._sipCrossActiveIdx = null;
                        target.setActiveElements([]);
                        if (target.tooltip) target.tooltip.setActiveElements([], { x: 0, y: 0 });
                        target.update('none');
                    }
                    return;
                }
                var idx = pts[0].index;
                if (target._sipCrossActiveIdx === idx) return;
                target._sipCrossActiveIdx = idx;
                var active = SGS.buildPctActiveElements(target, idx);
                var el = pts[0].element;
                var pos = el && typeof el.x === 'number' ? { x: el.x, y: el.y } : { x: 0, y: 0 };
                target.setActiveElements(active);
                if (target.tooltip) target.tooltip.setActiveElements(active, pos);
                target.update('none');
            });

            source.canvas.addEventListener('mouseleave', function () {
                target._sipCrossActiveIdx = null;
                target.setActiveElements([]);
                if (target.tooltip) target.tooltip.setActiveElements([], { x: 0, y: 0 });
                target.update('none');
            });
        }

        bindSource(chartA, chartB);
        bindSource(chartB, chartA);
    };

    SGS.finalizePctChartPair = function (mainChart, pctChart) {
        if (!mainChart || !pctChart) return;
        if (!mainChart.canvas || !mainChart.canvas.isConnected) return;
        if (!pctChart.canvas || !pctChart.canvas.isConnected) return;
        SGS.patchMasterZoomForPctSync(mainChart, pctChart);
        var run = function () {
            if (!mainChart.canvas || !mainChart.canvas.isConnected) return;
            if (!pctChart.canvas || !pctChart.canvas.isConnected) return;
            SGS.alignStackedChartAreas(mainChart, pctChart);
            SGS.linkChartsCrossHighlight(mainChart, pctChart);
        };
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(run);
        } else {
            run();
        }
    };

    SGS.createPctChartOptions = function (labels, extra) {
        extra = extra || {};
        var yRange = SGS.calcVarPctYRange(extra.datasets || []);
        var hideXTicks = extra.hideXTicks !== false;
        var tooltipTitleFn = extra.tooltipTitleFn;
        var labelCount = (labels && labels.length) ? labels.length : 0;
        var chartW = extra.chartWidth || 600;
        var seriesCount = (extra.datasets || []).filter(function (ds) { return ds && !ds._hcStdDs; }).length;
        var nForFont = labelCount;
        if (extra.datasets && labelCount > 0) {
            var withData = {};
            extra.datasets.forEach(function (ds) {
                if (!ds || ds._hcStdDs) return;
                (ds.data || []).forEach(function (v, i) {
                    if (v != null && isFinite(v)) withData[i] = true;
                });
            });
            var uniqueWithData = Object.keys(withData).length;
            if (uniqueWithData >= 2 && uniqueWithData < labelCount * 0.55) {
                nForFont = Math.max(uniqueWithData, Math.ceil(labelCount * uniqueWithData / (uniqueWithData + 3)));
            }
        }
        var dlSize = SGS.calcPctDatalabelFontSize(chartW, nForFont, seriesCount, { singleFullWidth: extra.singleFullWidth });
        var padTop = SGS.calcPctLayoutPaddingTop(yRange, dlSize);

        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            layout: {
                padding: { top: padTop, bottom: 4, left: extra.paddingLeft || 4, right: Math.max(8, Math.round(dlSize * 1.2)) }
            },
            plugins: {
                sipPlagasBarVis: {
                    enabled: false
                },
                legend: { display: false },
                datalabels: {
                    display: function (ctx) {
                        return SGS.shouldShowPctDatalabel(ctx);
                    },
                    anchor: 'end',
                    align: 'top',
                    offset: 4,
                    rotation: -90,
                    clip: false,
                    color: function (ctx) {
                        var cumple = ctx.dataset._cumpleArr && ctx.dataset._cumpleArr[ctx.dataIndex];
                        if (cumple === false) return SGS.COLOR_NO_CUMPLE_PCT;
                        if (cumple === true) return SGS.COLOR_CUMPLE_PCT;
                        return 'var(--sip-text, #334155)';
                    },
                    font: { family: 'Inter', size: dlSize, weight: '600' },
                    formatter: function (val) { return SGS.fmtVarPctCompact(val); }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        title: function (items) {
                            if (typeof tooltipTitleFn === 'function') {
                                return tooltipTitleFn(items);
                            }
                            if (!items || !items.length) return '';
                            return items[0].label != null ? String(items[0].label) : '';
                        },
                        label: function (ctx) {
                            return SGS.formatPctTooltipLabel(ctx);
                        },
                        labelColor: function (ctx) {
                            return SGS.formatPctTooltipLabelColor(ctx);
                        }
                    }
                },
                zoom: extra.enableZoom ? {
                    limits: { x: { min: 'original', max: 'original', minRange: 3 } },
                    pan: { enabled: false },
                    zoom: { wheel: { enabled: false }, pinch: { enabled: false } }
                } : undefined
            },
            scales: {
                x: {
                    display: !hideXTicks,
                    grid: { display: false },
                    ticks: hideXTicks ? { display: false } : {
                        autoSkip: false,
                        maxRotation: 90,
                        minRotation: 90,
                        font: { family: 'Inter', size: 8, weight: '600' },
                        color: 'var(--sip-text-muted, #64748b)'
                    }
                },
                y: {
                    min: yRange.min,
                    max: yRange.max,
                    title: {
                        display: true,
                        text: '%',
                        font: { family: 'Inter', size: 9, weight: '600' },
                        color: 'var(--sip-text-muted, #64748b)'
                    },
                    grid: { display: false, drawOnChartArea: false, drawTicks: false },
                    border: { display: false },
                    ticks: {
                        font: { family: 'Inter', size: 8, weight: '600' },
                        color: 'var(--sip-text-muted, #64748b)',
                        precision: 0,
                        callback: function (v) { return SGS.fmtVarPctAxis(v); }
                    }
                }
            }
        };
    };

    SGS.createPctChart = function (canvasId, labels, datasets, extra) {
        var canvas = typeof canvasId === 'string' ? document.getElementById(canvasId) : canvasId;
        if (!canvas || typeof Chart === 'undefined') return null;
        if (Chart.getChart) {
            var prev = Chart.getChart(canvas);
            if (prev) prev.destroy();
        }
        extra = extra || {};
        var chartWidth = extra.chartWidth;
        if (chartWidth == null && canvas.parentElement) {
            chartWidth = canvas.parentElement.clientWidth || 600;
        }
        var useBar = extra.useBar != null
            ? extra.useBar
            : ((datasets || []).length > 1 && (datasets || []).every(function (d) { return d.type === 'bar'; }));
        var chartPlugins = [SGS.pluginZeroPctLine];
        if (typeof ChartDataLabels !== 'undefined') chartPlugins.unshift(ChartDataLabels);
        var opts = SGS.createPctChartOptions(labels, {
            datasets: datasets,
            hideXTicks: extra.hideXTicks,
            enableZoom: extra.enableZoom,
            tooltipTitleFn: extra.tooltipTitleFn,
            paddingLeft: extra.paddingLeft,
            chartWidth: chartWidth,
            useBar: useBar
        });
        var chart = new Chart(canvas.getContext('2d'), {
            type: useBar ? 'bar' : 'line',
            data: { labels: labels, datasets: datasets },
            plugins: chartPlugins,
            options: opts
        });
        chart._sipPctExtra = extra;
        SGS.applyPctAdaptiveLayout(chart, { width: chartWidth });
        chart.update('none');
        return chart;
    };

    SGS.syncChartsZoomX = function (masterChart, slaveChart) {
        if (!masterChart || !slaveChart) return;
        var syncFn = function () {
            var mx = masterChart.scales && masterChart.scales.x;
            var sx = slaveChart.scales && slaveChart.scales.x;
            if (!mx || !sx) return;
            var changed = false;
            if (sx.options.min !== mx.min) { sx.options.min = mx.min; changed = true; }
            if (sx.options.max !== mx.max) { sx.options.max = mx.max; changed = true; }
            if (typeof SGS.applyPctYRangeForVisibleX === 'function') {
                SGS.applyPctYRangeForVisibleX(slaveChart);
                changed = true;
            }
            if (changed) {
                if (typeof SGS.applyPctAdaptiveLayout === 'function') {
                    SGS.applyPctAdaptiveLayout(slaveChart, { width: slaveChart.width || slaveChart.canvas && slaveChart.canvas.clientWidth });
                }
                slaveChart.update('none');
            }
        };
        masterChart._sipSyncPctFn = syncFn;
        slaveChart._sipSyncPctMaster = masterChart;
        syncFn();
    };

    SGS.patchMasterZoomForPctSync = function (masterChart, slaveChart) {
        if (!masterChart || !slaveChart || !masterChart.options) return;
        masterChart._sipPctSyncSlave = slaveChart;
        SGS.syncChartsZoomX(masterChart, slaveChart);
        if (masterChart._sipPctSyncPatched) return;
        masterChart._sipPctSyncPatched = true;
        var zoomCfg = masterChart.options.plugins && masterChart.options.plugins.zoom;
        if (!zoomCfg) return;
        var wrap = function (orig) {
            return function () {
                if (typeof orig === 'function') orig.apply(this, arguments);
                if (masterChart._sipSyncPctFn) masterChart._sipSyncPctFn();
            };
        };
        if (zoomCfg.pan) zoomCfg.pan.onPanComplete = wrap(zoomCfg.pan.onPanComplete);
        if (zoomCfg.zoom) zoomCfg.zoom.onZoomComplete = wrap(zoomCfg.zoom.onZoomComplete);
    };

    SGS.resetChartsZoomX = function (masterChart, slaveChart) {
        if (masterChart && typeof masterChart.resetZoom === 'function') masterChart.resetZoom();
        if (slaveChart && typeof slaveChart.resetZoom === 'function') slaveChart.resetZoom();
    };

    SGS.destroyPctChart = function (pctCanvasId, chartInstancesMap) {
        var el = typeof pctCanvasId === 'string' ? document.getElementById(pctCanvasId) : pctCanvasId;
        if (typeof Chart !== 'undefined' && Chart.getChart && el) {
            var existing = Chart.getChart(el);
            if (existing) existing.destroy();
        }
        if (chartInstancesMap && chartInstancesMap[pctCanvasId]) {
            try { chartInstancesMap[pctCanvasId].destroy(); } catch (e) { /* ignore */ }
            delete chartInstancesMap[pctCanvasId];
        }
    };

    SGS.evalCumpleModo = function (val, std, modoEval) {
        if (val == null || std == null || !isFinite(val) || !isFinite(std)) return null;
        if (modoEval === 'solo_max') return val <= std;
        if (modoEval === 'solo_min') return val >= std;
        return val >= std;
    };

    SGS.legendFilterDataset = function (item, datasets) {
        if (!item || item.datasetIndex == null || !datasets) return true;
        var ds = datasets[item.datasetIndex];
        if (!ds) return true;
        var lbl = String(ds.label || '');
        if (ds._hcStdDs || lbl.indexOf('Estándar') !== -1 || lbl.indexOf('CV ') === 0 || ds.yAxisID === 'yCv') {
            return false;
        }
        return true;
    };

    SGS.buildChartLegendOptions = function (opts) {
        opts = opts || {};
        var sortOrder = opts.sortOrder || null;
        return {
            position: 'bottom',
            labels: {
                usePointStyle: false,
                boxWidth: 12,
                padding: 12,
                font: { family: 'Inter', size: 11, weight: '500' },
                filter: function (item, chartData, datasets) {
                    return SGS.legendFilterDataset(item, datasets);
                },
                generateLabels: function (chart) {
                    var original = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                    var datasets = chart && chart.data ? chart.data.datasets : null;
                    var filtered = original.filter(function (labelItem) {
                        return SGS.legendFilterDataset(labelItem, datasets);
                    });
                    if (sortOrder && sortOrder.length) {
                        filtered.sort(function (a, b) {
                            var idxA = sortOrder.indexOf(a.text);
                            var idxB = sortOrder.indexOf(b.text);
                            return (idxA !== -1 ? idxA : 99) - (idxB !== -1 ? idxB : 99);
                        });
                    }
                    return filtered;
                }
            }
        };
    };

    // -----------------------------------------------------------------------
    // Exportar
    // -----------------------------------------------------------------------
    window.SipGraficaShared = SGS;
    if (typeof Chart !== 'undefined' && typeof Chart.register === 'function') {
        Chart.register(SGS.pluginPlagasBarVis);
    }

})();
