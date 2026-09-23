(function () {
    'use strict';

    const cfg = window.MORT_VENTAS_CFG || {};
    let tableVentas = null;
    let mrtVntGmc = null;

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatearFechaDMY(value) {
        if (!value) return '';
        const p = String(value).slice(0, 10).split('-');
        if (p.length !== 3) return value;
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function formatearNumero(v) {
        const n = Number(v || 0);
        return n.toLocaleString('es-PE', { maximumFractionDigits: 0 });
    }

    // Porcentaje con los decimales necesarios para no "anularlo" con el
    // redondeo: si el valor es > 0 pero con 1 decimal quedaría en 0 (p. ej.
    // 1/3726 = 0,0268%), se suben decimales (hasta 4) hasta que se vea.
    function formatoPorcentaje(pct) {
        let dec = 1;
        if (pct > 0) {
            while (dec < 4 && Number(pct.toFixed(dec)) === 0) {
                dec++;
            }
        }
        return pct.toLocaleString('es-PE', { maximumFractionDigits: dec });
    }

    // Etiqueta de celda cuando NO hay registro de mortalidad (sin movimientos
    // S808 del sexo en el día), distinta del "0" de cuando sí se registró pero
    // la suma es cero.
    function htmlSinRegistro() {
        return '<span class="mrtv-sin-reg">SIN REG.</span>';
    }

    // Columna de FRACCIÓN de la relación (p. ej. "112/3726").
    // Solo se muestra cuando la relación SÍ se puede calcular (hay venta del
    // sexo y existe registro S808 ese día):
    //  - Sin venta del sexo            → vacío (no aplica).
    //  - Sin registro S808 del sexo    → vacío (no se registró mortalidad).
    //  - Con S808 que suma 0           → "0/venta" (se registró y no murió nada).
    function fraccionRelacionMortVenta(mort, venta, hayS808) {
        const v = Number(venta || 0);
        if (v <= 0) return '';
        if (!Number(hayS808 || 0)) return '';
        const m = Number(mort || 0);
        return m + '/' + v;
    }

    // Columna de PORCENTAJE de la relación (p. ej. "15,6%"), independiente de
    // la fracción para que quede alineada a la derecha en su propia columna.
    // Vacía cuando la relación no se puede calcular (sin venta o sin registro).
    function pctRelacionMortVenta(mort, venta, hayS808) {
        const v = Number(venta || 0);
        if (v <= 0) return '';
        if (!Number(hayS808 || 0)) return '';
        const m = Number(mort || 0);
        return formatoPorcentaje((m / v) * 100) + '%';
    }

    // Celda de mortalidad ABSOLUTA (Mort. Tot. / Mort. Desp.): se muestra el
    // número real si existe registro S808 del sexo en el día (aunque la suma
    // sea 0 o no haya venta); si no hay registro → etiqueta "SIN REG.".
    function celdaMortAbsoluta(cant, hayS808, type) {
        if (type !== 'display') return cant;
        return Number(hayS808 || 0) ? formatearNumero(cant) : htmlSinRegistro();
    }

    // Valor numérico de la relación (para búsqueda/orden server-side): null si
    // no hay venta, de lo contrario la fracción m/v.
    function ratioMortVenta(mort, venta) {
        const v = Number(venta || 0);
        return v > 0 ? Number(mort || 0) / v : null;
    }

    // Etiquetas de etapa de mortalidad (Incubación/Transporte/Crianza/Despacho)
    const ETIQUETAS_ETAPA = {
        incubacion: { label: 'Incubación', bg: '#fef3c7', color: '#b45309', border: '#fde68a' },
        transporte: { label: 'Transporte', bg: '#ede9fe', color: '#6d28d9', border: '#ddd6fe' },
        crianza: { label: 'Crianza', bg: '#d1fae5', color: '#047857', border: '#a7f3d0' },
        despacho: { label: 'Despacho', bg: '#ffedd5', color: '#c2410c', border: '#fed7aa' }
    };
    function badgeEtapa(etapa) {
        const meta = ETIQUETAS_ETAPA[String(etapa || '').trim()];
        if (!meta) {
            return '<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold" style="background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;">—</span>';
        }
        return '<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold" style="background:' + meta.bg + ';color:' + meta.color + ';border:1px solid ' + meta.border + ';">' + escapeHtml(meta.label) + '</span>';
    }

    function syncGranjaDisplay() {
        var g = ($('#mrt-vnt-h-granja').val() || '').trim();
        var c = ($('#mrt-vnt-h-campania').val() || '').trim();
        var inp = document.getElementById('mrt-vnt-granja-resumen');
        if (!inp) return;
        if (g && c) {
            var nom = '';
            var meta = (cfg.granjasMeta || []).find(function (m) { return String(m.granja || '').trim() === g; });
            if (meta) nom = meta.nombre || '';
            inp.value = g + (nom ? ' ' + nom : '') + ' - ' + c;
            inp.title = 'Granja ' + g + (nom ? ' ' + nom : '') + ', Campana ' + c + ' - Clic para cambiar';
        } else {
            inp.value = '';
            inp.title = 'Clic para seleccionar granja y campana';
        }
    }

    function getCampaniasUrl() {
        var codes = (cfg.granjasMeta || []).map(function (g) { return g.granja || ''; }).filter(Boolean).join(',');
        if (!codes) return '';
        var f = leerFiltros();
        var p = new URLSearchParams({
            granjas: codes,
            periodoTipo: f.periodoTipo || 'ULTIMA_SEMANA',
            fechaUnica: f.fechaUnica || '',
            fechaInicio: f.fechaInicio || '',
            fechaFin: f.fechaFin || '',
            mesUnico: f.mesUnico || '',
            mesInicio: f.mesInicio || '',
            mesFin: f.mesFin || ''
        });
        var baseDir = cfg.listarUrl.substring(0, cfg.listarUrl.lastIndexOf('/'));
        var parentDir = baseDir.substring(0, baseDir.lastIndexOf('/'));
        return parentDir + '/get_campanias_mortalidad.php?' + p.toString();
    }

    function initMrtVntGmc() {
        if (!window.GmcGranjasCampanias) {
            console.warn('MORT-VNT: GmcGranjasCampanias no disponible');
            return;
        }
        if (mrtVntGmc) return;
        mrtVntGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-vnt',
            shellPanelId: 'mrt-vnt-modal-granjas',
            mode: 'single',
            bodyOpenClass: 'mrt-vnt-modal-granjas-open',
            openTriggerId: 'mrt-vnt-granja-resumen',
            getCodigoSeis: function () {
                var g = ($('#mrt-vnt-h-granja').val() || '').trim();
                var c = ($('#mrt-vnt-h-campania').val() || '').trim();
                return g && c ? g + c : '';
            },
            setCodigoSeis: function (cod) {
                if (cod && cod.length >= 6) {
                    $('#mrt-vnt-h-granja').val(cod.slice(0, 3));
                    $('#mrt-vnt-h-campania').val(cod.slice(-3));
                } else {
                    $('#mrt-vnt-h-granja').val('');
                    $('#mrt-vnt-h-campania').val('');
                }
            },
            cencosFetch: function () {
                var url = getCampaniasUrl();
                if (!url) {
                    return Promise.resolve({ granjas: cfg.granjasMeta || [], campanias_por_granja: {}, aviso: '' });
                }
                return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        return {
                            granjas: cfg.granjasMeta || [],
                            campanias_por_granja: (j && j.by_granja) ? j.by_granja : {},
                            aviso: ''
                        };
                    })
                    .catch(function () {
                        return { granjas: cfg.granjasMeta || [], campanias_por_granja: {}, aviso: 'Error al cargar campanas' };
                    });
            },
            onApply: function () {
                syncGranjaDisplay();
                cargarOpcionesFiltros();
            }
        });
    }

    function leerFiltros() {
        return {
            periodoTipo: ($('#periodoTipo').val() || 'ULTIMA_SEMANA').trim(),
            fechaUnica: ($('#fechaUnica').val() || '').trim(),
            fechaInicio: ($('#fechaInicio').val() || '').trim(),
            fechaFin: ($('#fechaFin').val() || '').trim(),
            mesUnico: ($('#mesUnico').val() || '').trim(),
            mesInicio: ($('#mesInicio').val() || '').trim(),
            mesFin: ($('#mesFin').val() || '').trim(),
            granja: ($('#mrt-vnt-h-granja').val() || '').trim(),
            campania: ($('#mrt-vnt-h-campania').val() || '').trim(),
            galpon: ($('#filtroGalpon').val() || '').trim()
        };
    }

    function paramsFiltros(f) {
        const p = new URLSearchParams();
        Object.keys(f).forEach(function (k) {
            if (f[k] !== '' && f[k] != null) {
                p.set(k, f[k]);
            }
        });
        return p;
    }

    function aplicarVisibilidadPeriodo() {
        const t = $('#periodoTipo').val() || 'ULTIMA_SEMANA';
        $('#periodoPorFecha, #periodoEntreFechas, #periodoPorMes, #periodoEntreMeses').addClass('hidden');
        if (t === 'POR_FECHA') $('#periodoPorFecha').removeClass('hidden');
        else if (t === 'ENTRE_FECHAS') $('#periodoEntreFechas').removeClass('hidden');
        else if (t === 'POR_MES') $('#periodoPorMes').removeClass('hidden');
        else if (t === 'ENTRE_MESES') $('#periodoEntreMeses').removeClass('hidden');
    }

    function cargarOpcionesFiltros() {
        const f = leerFiltros();
        const qs = paramsFiltros(f).toString();
        const url = cfg.filtrosUrl + (qs ? '?' + qs : '');

        return fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) return j;

                const granja = f.granja;
                const $gal = $('#filtroGalpon');
                const prevGal = $gal.val() || '';
                $gal.empty().append('<option value="">Todos</option>');
                if (granja) {
                    $gal.prop('disabled', false);
                    (j.galpones || []).forEach(function (g) {
                        $gal.append('<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>');
                    });
                    if (prevGal && $gal.find('option[value="' + prevGal.replace(/"/g, '\\"') + '"]').length) {
                        $gal.val(prevGal);
                    }
                } else {
                    $gal.prop('disabled', true);
                }

                return j;
            })
            .catch(function (e) {
                console.error(e);
            });
    }

    /* ── Detalle: modal con el desglose de la(s) venta(s) y mortalidad(es) ── */

    // Mortalidad del día lado a lado: cada fila es una causa (etapa + causa),
    // con el bloque MACHO a la izquierda y HEMBRA a la derecha, cada uno con
    // sus columnas Etapa | Causa | Cantidad. Los movimientos de la misma causa
    // se suman; conserva el orden del backend (sexo → etapa). Si un sexo tiene
    // menos causas, sus celdas quedan vacías en las filas restantes.
    function htmlTablaDetalleMort(items) {
        if (!items || !items.length) {
            return '<p class="text-gray-400 text-sm py-3">Sin registros.</p>';
        }

        function agruparPorCausa(sexo) {
            const mapa = {};
            const orden = [];
            items.forEach(function (it) {
                if (String(it.tipo || '').trim() !== sexo) return;
                const etapa = String(it.etapa || '').trim();
                const causa = String(it.nomMort || it.codMort || '').trim() || '—';
                const clave = etapa + '\u0001' + causa;
                if (!mapa[clave]) {
                    mapa[clave] = { etapa: etapa, causa: causa, cantidad: 0 };
                    orden.push(clave);
                }
                mapa[clave].cantidad += Number(it.cantidad || 0);
            });
            return orden.map(function (c) { return mapa[c]; });
        }

        const grupos = [];
        const machos = agruparPorCausa('Macho');
        const hembras = agruparPorCausa('Hembra');
        if (machos.length) grupos.push({ titulo: 'Macho', filas: machos, clsGrupo: 'mrtv-det-grupo-macho' });
        if (hembras.length) grupos.push({ titulo: 'Hembra', filas: hembras, clsGrupo: 'mrtv-det-grupo-hembra' });

        const numFilas = grupos.reduce(function (max, g) { return Math.max(max, g.filas.length); }, 0);

        function celdaCausa(grupo, i) {
            const f = grupo ? grupo.filas[i] : null;
            if (!f) {
                return '<td colspan="3" class="mrtv-det-celda-vacia' + (grupo && grupo.sep ? ' mrtv-det-col-sep' : '') + '"></td>';
            }
            let h = '<td class="mrtv-det-td' + (grupo.sep ? ' mrtv-det-col-sep' : '') + '">' + badgeEtapa(f.etapa) + '</td>';
            h += '<td class="mrtv-det-td text-gray-600">' + escapeHtml(f.causa) + '</td>';
            h += '<td class="mrtv-det-td text-right font-semibold text-gray-800">' + formatearNumero(f.cantidad) + '</td>';
            return h;
        }

        let html = '<div class="overflow-x-auto mt-2 mrtv-det-wrap">';
        html += '<table class="w-full text-sm border-collapse mrtv-det-tabla">';
        // Colgroup con table-layout:fixed. La sección Macho se muestra un poco
        // más angosta (47%) que la de Hembra (53%); Etapa y Cantidad más anchas
        // y la Causa se estrecha para compensar. La col N° (2rem) solo existe a
        // la izquierda, por eso la Causa de Macho lleva la resta adicional.
        html += '<colgroup>';
        html += '<col style="width:2rem;">';
        grupos.forEach(function (g, gi) {
            if (grupos.length === 2 && gi === 0) {
                // Bloque izquierdo (Macho, 47%): N° 2rem + Etapa 6.5 + Cant 5.5
                html += '<col style="width:6.5rem;"><col style="width:calc(47% - 14rem);"><col style="width:5.5rem;">';
            } else if (grupos.length === 2) {
                // Bloque derecho (Hembra, 53%)
                html += '<col style="width:6.5rem;"><col style="width:calc(53% - 12rem);"><col style="width:5.5rem;">';
            } else {
                // Un solo grupo: ocupa todo el ancho restante tras N° (2rem)
                html += '<col style="width:6.5rem;"><col style="width:calc(100% - 14rem);"><col style="width:5.5rem;">';
            }
        });
        html += '</colgroup>';
        html += '<thead>';
        html += '<tr>';
        html += '<th rowspan="2" class="mrtv-det-th-num">N°</th>';
        grupos.forEach(function (g, gi) {
            g.sep = gi > 0;
            html += '<th colspan="3" class="mrtv-det-th-grupo ' + g.clsGrupo + (g.sep ? ' mrtv-det-col-sep' : '') + '">' + escapeHtml(g.titulo) + '</th>';
        });
        html += '</tr><tr>';
        grupos.forEach(function (g) {
            html += '<th class="mrtv-det-th-sub' + (g.sep ? ' mrtv-det-col-sep' : '') + '">Etapa</th>';
            html += '<th class="mrtv-det-th-sub">Causa</th>';
            html += '<th class="mrtv-det-th-sub mrtv-det-th-cant">Cantidad</th>';
        });
        html += '</tr></thead><tbody>';
        for (let i = 0; i < numFilas; i++) {
            html += '<tr>';
            html += '<td class="mrtv-det-td text-center text-gray-400">' + (i + 1) + '</td>';
            grupos.forEach(function (g) {
                html += celdaCausa(g, i);
            });
            html += '</tr>';
        }
        html += '</tbody></table></div>';
        return html;
    }

    function construirHtmlDetalle(d) {
        const g = String(d.granja || '').trim();
        const nom = String(d.granjaNombre || '').trim();
        const vMacho = Number(d.ventaMacho || 0);
        const vHembra = Number(d.ventaHembra || 0);
        const mMacho = Number(d.mortMacho || 0);
        const mHembra = Number(d.mortHembra || 0);
        let html = '';

        // Datos del lote (chips).
        html += '<div class="flex flex-wrap gap-2 mb-4">';
        html += '<span class="px-3 py-1.5 rounded-xl bg-sky-50 text-sky-800 text-xs font-medium">Granja ' + escapeHtml(g + (nom && nom !== g ? ' ' + nom : '')) + '</span>';
        html += '<span class="px-3 py-1.5 rounded-xl bg-gray-100 text-gray-700 text-xs font-medium">Campaña ' + escapeHtml(d.campania || '') + '</span>';
        html += '<span class="px-3 py-1.5 rounded-xl bg-gray-100 text-gray-700 text-xs font-medium">Galpón ' + escapeHtml(d.galpon || '') + '</span>';
        html += '</div>';

        // Ventas del día: resumen por sexo (suma general, sin listar cada venta).
        const hayVentas = vMacho > 0 || vHembra > 0;
        html += '<div class="mb-6">';
        html += '<h6 class="font-semibold text-gray-800 text-sm mb-2"><i class="fas fa-money-bill-wave text-blue-600 mr-1"></i> Ventas del día</h6>';
        if (!hayVentas) {
            html += '<p class="text-gray-400 text-sm py-2">Sin ventas de pollos en el día.</p>';
        } else {
            html += '<div class="grid grid-cols-2 gap-3">';
            html += '<div class="rounded-xl px-3 py-4 text-center" style="background-color:#f0f9ff;border:1px solid #e0f2fe;">';
            html += '<div class="text-[11px] uppercase tracking-wide font-semibold mb-1" style="color:#0369a1;">Machos</div>';
            html += '<div class="text-2xl font-bold leading-none" style="color:#075985;">' + formatearNumero(vMacho) + '</div>';
            html += '</div>';
            // Hembras en rosa claro suave (estético)
            html += '<div class="rounded-xl px-3 py-4 text-center" style="background-color:#fdf2f8;border:1px solid #fbcfe8;">';
            html += '<div class="text-[11px] uppercase tracking-wide font-semibold mb-1" style="color:#db2777;">Hembras</div>';
            html += '<div class="text-2xl font-bold leading-none" style="color:#be185d;">' + formatearNumero(vHembra) + '</div>';
            html += '</div>';
            html += '</div>';
            html += '<p class="text-sm text-gray-600 mt-3"><span class="text-gray-500">Total del día:</span> <span class="font-semibold text-gray-800">' + formatearNumero(vMacho + vHembra) + '</span> pollos</p>';
        }
        html += '</div>';

        // Mortalidad registrada: subtotales por sexo + detalle con causa
        // (incluye registros antiguos tcategoria 'P' y registros nuevos por despacho).
        const hayMortalidad = mMacho > 0 || mHembra > 0;
        html += '<div>';
        html += '<h6 class="font-semibold text-gray-800 text-sm mb-2"><i class="fas fa-dove text-blue-600 mr-1"></i> Mortalidad registrada:</h6>';
        if (!hayMortalidad) {
            html += '<p class="text-gray-400 text-sm py-2">Sin mortalidad registrada en el día.</p>';
        } else {
            html += '<div class="grid grid-cols-2 gap-3 mb-3">';
            html += '<div class="rounded-xl px-3 py-3 text-center" style="background-color:#f0f9ff;border:1px solid #e0f2fe;">';
            html += '<div class="text-[11px] uppercase tracking-wide font-semibold mb-1" style="color:#0369a1;">Machos</div>';
            html += '<div class="text-xl font-bold leading-none" style="color:#075985;">' + formatearNumero(mMacho) + '</div>';
            html += '</div>';
            // Hembras en rosa claro suave (estético)
            html += '<div class="rounded-xl px-3 py-3 text-center" style="background-color:#fdf2f8;border:1px solid #fbcfe8;">';
            html += '<div class="text-[11px] uppercase tracking-wide font-semibold mb-1" style="color:#db2777;">Hembras</div>';
            html += '<div class="text-xl font-bold leading-none" style="color:#be185d;">' + formatearNumero(mHembra) + '</div>';
            html += '</div>';
            html += '</div>';
            html += htmlTablaDetalleMort(d.mortalidades || []);
            html += '<p class="text-sm text-gray-600 mt-3"><span class="text-gray-500">Total mortalidad:</span> <span class="font-semibold text-gray-800">' + formatearNumero(mMacho + mHembra) + '</span> pollos</p>';
        }
        html += '</div>';

        return html;
    }

    function abrirDetalleVenta(row) {
        if (!cfg.detalleUrl) return;
        const fecha = String(row.fecha || '').trim();
        const granja = String(row.granja || '').trim();
        const campania = String(row.campania || '').trim();
        const galpon = String(row.galpon || '').trim();
        if (!fecha || !granja || !campania || !galpon) return;

        const p = new URLSearchParams({ fecha: fecha, granja: granja, campania: campania, galpon: galpon });
        $('#modalDetalleVentaTitulo').text('Detalle de venta — ' + formatearFechaDMY(fecha));
        $('#modalDetalleVentaBody').html(
            '<div class="flex items-center justify-center gap-3 py-10 text-sm text-gray-600">' +
            '<div class="h-4 w-4 animate-spin rounded-full border-2 border-blue-600 border-t-transparent"></div>' +
            '<div>Cargando detalle...</div>' +
            '</div>'
        );
        // Mientras el modal está abierto, la píldora "Procesando…" de la tabla
        // no debe verse detrás (transparentaría el modal). Al cerrar se quita.
        document.body.classList.add('mrt-detalle-abierto');
        $('#modalDetalleVenta').removeClass('hidden').addClass('flex');

        fetch(cfg.detalleUrl + '?' + p.toString(), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) {
                    $('#modalDetalleVentaBody').html('<p class="text-red-600">' + escapeHtml((j && j.message) || 'Error al cargar el detalle.') + '</p>');
                    return;
                }
                $('#modalDetalleVentaBody').html(construirHtmlDetalle(j));
            })
            .catch(function () {
                $('#modalDetalleVentaBody').html('<p class="text-red-600">Error de conexión.</p>');
            });
    }

    /* ── Cabecera sticky (estilo gatilladores) ──
       Cuando la cabecera deja de verse al hacer scroll vertical, se clona el
       <thead> (3 filas con rowspan/colspan) en una barra fija que se sincroniza
       con el scroll horizontal del contenedor. El clon conserva la clase
       mrtv-tabla para heredar los mismos estilos de cabecera.

       Para que el clon quede SIEMPRE alineado con el cuerpo se usa un
       <colgroup> con el ancho real de cada columna medido desde la primera
       fila del <tbody> (que mapea 1:1 con las columnas de datos) y
       table-layout: fixed. Copiar anchos celda por celda del thead NO sirve
       con cabecera de 3 filas: las celdas con rowspan/colspan entran en
       conflicto entre filas y el clon se descuadra del cuerpo. */
    function initStickyCabeceraVentas() {
        const wrap = document.querySelector('.mrtv-tbl-wrap');
        if (!wrap || wrap._mrtvStickyBound) return;
        const table = wrap.querySelector('table.mrtv-tabla');
        const thead = table ? table.querySelector('thead') : null;
        if (!table || !thead) return;
        wrap._mrtvStickyBound = true;

        const bar = document.createElement('div');
        bar.className = 'mrtv-sticky-bar';
        bar.setAttribute('aria-hidden', 'true');
        const barScroll = document.createElement('div');
        barScroll.className = 'mrtv-sticky-bar-scroll';
        const cloneTable = document.createElement('table');
        cloneTable.className = table.className + ' mrtv-sticky-clone';
        cloneTable.appendChild(thead.cloneNode(true));

        // Colgroup: un <col> por columna de datos (total de colspan de la 1.ª
        // fila del thead). Esos anchos se actualizan en syncColWidths desde el
        // tbody real.
        const cloneCols = [];
        let nCols = 0;
        const firstHeadRow = thead.rows[0];
        if (firstHeadRow) {
            for (let i = 0; i < firstHeadRow.cells.length; i++) {
                nCols += firstHeadRow.cells[i].colSpan || 1;
            }
        }
        const colgroup = document.createElement('colgroup');
        for (let i = 0; i < nCols; i++) {
            const col = document.createElement('col');
            colgroup.appendChild(col);
            cloneCols.push(col);
        }
        cloneTable.insertBefore(colgroup, cloneTable.firstChild);

        barScroll.appendChild(cloneTable);
        bar.appendChild(barScroll);
        document.body.appendChild(bar);

        const stickTop = 0;
        let syncingScroll = false;

        // Mide la 1.ª fila de datos del tbody (sus celdas son 1:1 con las
        // columnas, a diferencia del thead con rowspan/colspan) y aplica esos
        // anchos a los <col> del clon. Como el clon usa table-layout: fixed +
        // width:max-content, su ancho total es la suma exacta de esos anchos y
        // las columnas quedan alineadas con el cuerpo.
        function syncColWidths() {
            const tbody = table.tBodies[0];
            const row = tbody && tbody.rows[0];
            if (!row) return;
            const n = Math.min(row.cells.length, cloneCols.length);
            for (let i = 0; i < n; i++) {
                const w = row.cells[i].getBoundingClientRect().width;
                if (w > 0 && isFinite(w)) {
                    cloneCols[i].style.width = w + 'px';
                }
            }
        }

        function syncBarScroll() {
            if (syncingScroll) return;
            syncingScroll = true;
            barScroll.scrollLeft = wrap.scrollLeft || 0;
            syncingScroll = false;
        }

        function update() {
            if (!document.body.contains(wrap) || !document.body.contains(table)) {
                try { bar.remove(); } catch (e) { /* noop */ }
                return;
            }
            const wrapRect = wrap.getBoundingClientRect();
            const theadRect = thead.getBoundingClientRect();
            const tableRect = table.getBoundingClientRect();
            const headH = thead.offsetHeight || theadRect.height || 0;
            const tbody = table.tBodies[0];
            const hayFilas = !!(tbody && tbody.rows.length > 0);
            const should = hayFilas
                && theadRect.top <= stickTop
                && tableRect.bottom > (stickTop + headH + 4)
                && wrapRect.bottom > (stickTop + 24)
                && wrapRect.top < (window.innerHeight - 40);
            if (!should) {
                bar.classList.remove('is-active');
                thead.classList.remove('mrtv-thead-is-stuck');
                return;
            }
            syncColWidths();
            bar.style.top = stickTop + 'px';
            bar.style.left = Math.max(0, wrapRect.left) + 'px';
            bar.style.width = Math.max(0, wrapRect.width) + 'px';
            if (headH > 0) bar.style.height = headH + 'px';
            thead.classList.add('mrtv-thead-is-stuck');
            bar.classList.add('is-active');
            syncBarScroll();
        }

        wrap._mrtvStickyUpdate = update;
        wrap.addEventListener('scroll', syncBarScroll, { passive: true });
        barScroll.addEventListener('scroll', function () {
            if (syncingScroll) return;
            syncingScroll = true;
            wrap.scrollLeft = barScroll.scrollLeft || 0;
            syncingScroll = false;
        }, { passive: true });
        update();

        if (!window._mrtvStickyWinBound) {
            window._mrtvStickyWinBound = true;
            let raf = 0;
            function scheduleAll() {
                if (raf) return;
                raf = requestAnimationFrame(function () {
                    raf = 0;
                    const wraps = document.querySelectorAll('.mrtv-tbl-wrap');
                    for (let i = 0; i < wraps.length; i++) {
                        if (typeof wraps[i]._mrtvStickyUpdate === 'function') {
                            wraps[i]._mrtvStickyUpdate();
                        }
                    }
                });
            }
            window.addEventListener('scroll', scheduleAll, { passive: true });
            window.addEventListener('resize', scheduleAll, { passive: true });
        }
    }

    function cargarTabla(resetPaging) {
        if (cfg.sinPermisos) return;

        if (tableVentas && $.fn.DataTable.isDataTable('#tablaVentas')) {
            tableVentas.ajax.reload(null, resetPaging !== false);
            return;
        }

        tableVentas = $('#tablaVentas').DataTable({
            // Indicador de procesamiento ACTIVO, igual que el listado
            // (dashboard-listado.php): DataTables muestra su píldora estándar
            // "Procesando…". Mientras el modal de detalle esté abierto se
            // oculta por CSS (body.mrt-detalle-abierto).
            processing: true,
            serverSide: true,
            // Sin scrollX: la cabecera y el cuerpo viven en la misma <table>, así
            // las columnas siempre coinciden (con scrollX DataTables clonaba el
            // thead y con rowspan/colspan quedaba desalineado del cuerpo).
            autoWidth: false,
            deferRender: true,
            searching: true,
            // La búsqueda es server-side y reagrupa el período; esperar a que el
            // usuario pause evita lanzar la consulta costosa en cada tecla.
            searchDelay: 800,
            dom: '<"mrtv-tbl-toolbar"<"flex items-center gap-6" l><"flex items-center gap-2" f>><"mrtv-tbl-wrap"rt><"dt-bottom-row"<"text-sm text-gray-600" i><"text-sm text-gray-600" p>>',
            ajax: {
                url: cfg.listarUrl,
                type: 'POST',
                data: function (d) {
                    const filtros = leerFiltros();
                    Object.keys(filtros).forEach(function (k) {
                        if (filtros[k] !== '' && filtros[k] != null) {
                            d[k] = filtros[k];
                        }
                    });
                    return d;
                },
                dataSrc: function (json) {
                    return json.data || [];
                },
                error: function (xhr) {
                    console.error('Error ventas mortalidad', xhr.status, xhr.responseText);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error al cargar', text: 'No se pudo obtener el listado de ventas.' });
                    }
                }
            },
            columns: [
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function (data, type, row, meta) {
                        return type === 'display' ? (meta.settings._iDisplayStart + meta.row + 1) : '';
                    }
                },
                {
                    data: 'fecha',
                    render: function (data, type) {
                        if (type !== 'display' && type !== 'filter') return data;
                        return formatearFechaDMY(data);
                    }
                },
                {
                    data: null,
                    render: function (data, type, row) {
                        const g = String(row.granja || '').trim();
                        const nom = String(row.granjaNombre || '').trim();
                        const txt = (nom && nom !== g) ? nom : g;
                        if (type !== 'display' && type !== 'filter') {
                            return txt;
                        }
                        return escapeHtml(txt);
                    }
                },
                {
                    data: 'campania',
                    render: function (data, type) {
                        return type === 'display' ? escapeHtml(String(data || '')) : data;
                    }
                },
                {
                    data: 'galpon',
                    render: function (data, type) {
                        return type === 'display' ? escapeHtml(String(data || '')) : data;
                    }
                },
                {
                    data: 'venta_macho',
                    className: 'text-right col-venta grp-macho-cell',
                    render: function (data, type) {
                        if (type === 'display') return formatearNumero(data);
                        return data;
                    }
                },
                {
                    data: 'mort_macho',
                    className: 'text-right col-mort-tot grp-macho-cell',
                    render: function (data, type, row) {
                        return celdaMortAbsoluta(data, row.tiene_s808_macho, type);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-frac grp-macho-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return fraccionRelacionMortVenta(row.mort_macho, row.venta_macho, row.tiene_s808_macho);
                        return ratioMortVenta(row.mort_macho, row.venta_macho);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-pct grp-macho-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return pctRelacionMortVenta(row.mort_macho, row.venta_macho, row.tiene_s808_macho);
                        return ratioMortVenta(row.mort_macho, row.venta_macho);
                    }
                },
                {
                    data: 'mort_desp_macho',
                    className: 'text-right col-mort-desp grp-macho-cell',
                    render: function (data, type, row) {
                        return celdaMortAbsoluta(data, row.tiene_s808_macho, type);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-frac grp-macho-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return fraccionRelacionMortVenta(row.mort_desp_macho, row.venta_macho, row.tiene_s808_macho);
                        return ratioMortVenta(row.mort_desp_macho, row.venta_macho);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-pct grp-macho-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return pctRelacionMortVenta(row.mort_desp_macho, row.venta_macho, row.tiene_s808_macho);
                        return ratioMortVenta(row.mort_desp_macho, row.venta_macho);
                    }
                },
                {
                    data: 'venta_hembra',
                    className: 'text-right col-venta grp-hembra-cell',
                    render: function (data, type) {
                        if (type === 'display') return formatearNumero(data);
                        return data;
                    }
                },
                {
                    data: 'mort_hembra',
                    className: 'text-right col-mort-tot grp-hembra-cell',
                    render: function (data, type, row) {
                        return celdaMortAbsoluta(data, row.tiene_s808_hembra, type);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-frac grp-hembra-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return fraccionRelacionMortVenta(row.mort_hembra, row.venta_hembra, row.tiene_s808_hembra);
                        return ratioMortVenta(row.mort_hembra, row.venta_hembra);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-pct grp-hembra-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return pctRelacionMortVenta(row.mort_hembra, row.venta_hembra, row.tiene_s808_hembra);
                        return ratioMortVenta(row.mort_hembra, row.venta_hembra);
                    }
                },
                {
                    data: 'mort_desp_hembra',
                    className: 'text-right col-mort-desp grp-hembra-cell',
                    render: function (data, type, row) {
                        return celdaMortAbsoluta(data, row.tiene_s808_hembra, type);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-frac grp-hembra-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return fraccionRelacionMortVenta(row.mort_desp_hembra, row.venta_hembra, row.tiene_s808_hembra);
                        return ratioMortVenta(row.mort_desp_hembra, row.venta_hembra);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-right col-rel-pct grp-hembra-cell',
                    render: function (data, type, row) {
                        if (type === 'display') return pctRelacionMortVenta(row.mort_desp_hembra, row.venta_hembra, row.tiene_s808_hembra);
                        return ratioMortVenta(row.mort_desp_hembra, row.venta_hembra);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function (data, type, row) {
                        if (type !== 'display') return '';
                        return '<button type="button" class="btn-detalle-venta text-blue-600 hover:text-blue-800 font-medium" title="Ver detalle de la venta" data-fecha="' + escapeHtml(String(row.fecha || '')) + '" data-granja="' + escapeHtml(String(row.granja || '')) + '" data-campania="' + escapeHtml(String(row.campania || '')) + '" data-galpon="' + escapeHtml(String(row.galpon || '')) + '"><i class="fas fa-eye"></i></button>';
                    }
                }
            ],
            columnDefs: [{ orderable: false, targets: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19] }],
            language: Object.assign({}, window.DATATABLES_LANG_ES || {}),
            pageLength: 25,
            lengthMenu: [[25, 50, 100], [25, 50, 100]],
            drawCallback: function () {
                // (Re)enlaza la cabecera sticky (clon flotante) y la actualiza.
                initStickyCabeceraVentas();
                if (this && typeof this.api === 'function') {
                    this.api().columns.adjust();
                }
                const wrapD = document.querySelector('.mrtv-tbl-wrap');
                if (wrapD && typeof wrapD._mrtvStickyUpdate === 'function') {
                    wrapD._mrtvStickyUpdate();
                }
            }
        });
    }

    function limpiarFiltros() {
        const d = new Date();
        const anio = d.getFullYear();
        $('#periodoTipo').val('ULTIMA_SEMANA');
        $('#fechaUnica').val(d.toISOString().slice(0, 10));
        $('#fechaInicio').val(anio + '-01-01');
        $('#fechaFin').val(anio + '-12-31');
        $('#mesUnico').val(anio + '-' + String(d.getMonth() + 1).padStart(2, '0'));
        $('#mesInicio').val(anio + '-01');
        $('#mesFin').val(anio + '-12');
        $('#mrt-vnt-h-granja').val('');
        $('#mrt-vnt-h-campania').val('');
        syncGranjaDisplay();
        $('#filtroGalpon').val('').prop('disabled', true);
        aplicarVisibilidadPeriodo();
        cargarOpcionesFiltros().then(function () { cargarTabla(true); });
    }

    $(document).ready(function () {
        $('#btnToggleFiltrosVnt').on('click', function () {
            $('#contenidoFiltrosVnt').toggleClass('hidden');
            $('#iconoFiltrosVnt').toggleClass('rotate-180');
        });

        $('#periodoTipo').on('change', function () {
            aplicarVisibilidadPeriodo();
            var g = ($('#mrt-vnt-h-granja').val() || '').trim();
            if (g) {
                cargarOpcionesFiltros();
            }
        });

        $('#btnFiltrarVnt').on('click', function () { cargarTabla(true); });
        $('#btnLimpiarVnt').on('click', limpiarFiltros);

        initMrtVntGmc();

        // Detalle de venta (ojo azul)
        $(document).on('click', '.btn-detalle-venta', function () {
            const row = {
                fecha: $(this).data('fecha'),
                granja: $(this).data('granja'),
                campania: $(this).data('campania'),
                galpon: $(this).data('galpon')
            };
            abrirDetalleVenta(row);
        });
        $('#btnCerrarDetalleVenta, #modalDetalleVenta').on('click', function (e) {
            if (e.target === this || e.target.id === 'btnCerrarDetalleVenta') {
                $('#modalDetalleVenta').addClass('hidden').removeClass('flex');
                document.body.classList.remove('mrt-detalle-abierto');
            }
        });

        aplicarVisibilidadPeriodo();
        cargarTabla();
        cargarOpcionesFiltros();

        // Reajustar columnas y barra sticky cuando cambie el ancho (sidebar toggle)
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(function () {
                if (tableVentas) {
                    tableVentas.columns.adjust();
                }
                const wrap = document.querySelector('.mrtv-tbl-wrap');
                if (wrap && typeof wrap._mrtvStickyUpdate === 'function') {
                    wrap._mrtvStickyUpdate();
                }
            });
            ro.observe(document.querySelector('.tabla-listado-wrapper') || document.body);
        }
    });
}());
