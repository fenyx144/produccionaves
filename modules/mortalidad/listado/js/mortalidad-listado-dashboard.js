(function () {
    'use strict';

    const cfg = window.MORT_LISTADO_CFG || {};
    let tableMort = null;
    let mrtListGmc = null;
    let clavesGrupoSeleccionadas = [];
    let cencosEditCache = null;
    let motivosPorTipoCache = null;
    let motivosTodosCache = null;
    let editCabIdActual = null;
    let detalleActualModal = null;

    /** Etapas del proceso de despacho (columna camelCase en san_fact_mortalidad_det). */
    const kEtapasDespacho = [
        { col: 'procesoPreparar', nombre: 'Preparar' },
        { col: 'procesoAcorralar', nombre: 'Acorralar' },
        { col: 'procesoSeleccionar', nombre: 'Seleccionar' },
        { col: 'procesoEnjabar', nombre: 'Enjabar' },
        { col: 'procesoPesar', nombre: 'Pesar' },
        { col: 'procesoEstibar', nombre: 'Estibar' }
    ];

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

    function formatearHora(value) {
        if (!value) return '';
        const s = String(value);
        if (s.length >= 16 && s.charAt(10) === ' ') return s.slice(11, 16);
        if (s.length >= 5 && s.indexOf(':') >= 0) return s.slice(0, 5);
        return s;
    }

    function actualizarResumen(resumen) {
        const r = resumen || {};
        $('#resumenRegistros').text((r.registros ?? 0).toLocaleString('es-PE'));
        $('#resumenTotalAves').text((r.totalAves ?? 0).toLocaleString('es-PE'));
        $('#resumenMachos').text((r.machos ?? 0).toLocaleString('es-PE'));
        $('#resumenHembras').text((r.hembras ?? 0).toLocaleString('es-PE'));
    }

    function syncGranjaDisplay() {
        var g = ($('#mrt-list-h-granja').val() || '').trim();
        var c = ($('#mrt-list-h-campania').val() || '').trim();
        var inp = document.getElementById('mrt-list-granja-resumen');
        if (!inp) return;
        if (g && c) {
            var nom = '';
            var meta = (cfg.granjasMeta || []).find(function(m) { return m.granja === g; });
            if (meta) nom = meta.nombre || '';
            inp.value = g + (nom ? ' ' + nom : '') + ' - ' + c;
            inp.title = 'Granja ' + g + (nom ? ' ' + nom : '') + ', Campana ' + c + ' - Clic para cambiar';
        } else {
            inp.value = '';
            inp.title = 'Clic para seleccionar granja y campana';
        }
    }

    function mapaLabelsGrupo() {
        const map = {};
        (cfg.arbolGrupos || []).forEach(function (bloque) {
            (bloque.items || []).forEach(function (item) {
                map[item.key] = item.label || item.key;
            });
        });
        return map;
    }

    function actualizarEtiquetaGruposFiltro() {
        const map = mapaLabelsGrupo();
        const $lbl = $('#lblGruposFiltro');
        if (!clavesGrupoSeleccionadas.length) {
            $lbl.text('Todos');
            return;
        }
        if (clavesGrupoSeleccionadas.length === 1) {
            $lbl.text(map[clavesGrupoSeleccionadas[0]] || clavesGrupoSeleccionadas[0]);
            return;
        }
        $lbl.text(clavesGrupoSeleccionadas.length + ' grupos seleccionados');
    }

    function sincronizarCheckboxesModal() {
        const set = new Set(clavesGrupoSeleccionadas);
        $('.chk-grupo-filtro').each(function () {
            $(this).prop('checked', set.has($(this).val()));
        });
    }

    function leerCheckboxesModal() {
        const sel = [];
        $('.chk-grupo-filtro:checked').each(function () {
            sel.push($(this).val());
        });
        return sel;
    }

    function getCampaniasUrl() {
        var codes = (cfg.granjasMeta || []).map(function (g) { return g.granja || ''; }).filter(Boolean).join(',');
        if (!codes) return '';
        var f = leerFiltros();
        var p = new URLSearchParams({
            granjas: codes,
            periodoTipo: f.periodoTipo || 'TODOS',
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

    function initMrtListGmc() {
        if (!window.GmcGranjasCampanias) {
            console.warn('MORT-LIST: GmcGranjasCampanias no disponible');
            return;
        }
        if (mrtListGmc) return;
        mrtListGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-list',
            shellPanelId: 'mrt-list-modal-granjas',
            mode: 'single',
            bodyOpenClass: 'mrt-list-modal-granjas-open',
            openTriggerId: 'mrt-list-granja-resumen',
            getCodigoSeis: function () {
                var g = ($('#mrt-list-h-granja').val() || '').trim();
                var c = ($('#mrt-list-h-campania').val() || '').trim();
                return g && c ? g + c : '';
            },
            setCodigoSeis: function (cod) {
                if (cod && cod.length >= 6) {
                    $('#mrt-list-h-granja').val(cod.slice(0, 3));
                    $('#mrt-list-h-campania').val(cod.slice(-3));
                } else {
                    $('#mrt-list-h-granja').val('');
                    $('#mrt-list-h-campania').val('');
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

    function cargarCencosEdit() {
        if (cencosEditCache) {
            return Promise.resolve(cencosEditCache);
        }
        const url = cfg.cencosUrl || '';
        if (!url) {
            return Promise.resolve([]);
        }
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                const list = (j && j.data && j.data.cencos) ? j.data.cencos : [];
                // Mismo orden que necropsias: campaña más reciente primero.
                list.sort(function (a, b) {
                    return String(b.codigo || '').localeCompare(String(a.codigo || ''));
                });
                cencosEditCache = list;
                return list;
            })
            .catch(function () {
                cencosEditCache = [];
                return cencosEditCache;
            });
    }

    function cargarGranjasEditar(granjaActual) {
        const $g = $('#editGranja');
        if (!$g.length) return Promise.resolve();
        const prev = String(granjaActual || '').trim();
        return cargarCencosEdit().then(function (cencos) {
            $g.empty().append('<option value="">Seleccione granja</option>');
            const visto = {};
            cencos.forEach(function (c) {
                const cod = String(c.codigo || '').trim();
                if (cod.length < 6) return;
                const g3 = cod.slice(0, 3);
                if (visto[g3]) return;
                visto[g3] = true;
                const nom = String(c.nombre || '').trim();
                const label = g3 + (nom ? ' - ' + nom : '');
                $g.append('<option value="' + escapeHtml(g3) + '">' + escapeHtml(label) + '</option>');
            });
            if (prev) {
                if (!$g.find('option[value="' + prev.replace(/"/g, '\\"') + '"]').length) {
                    $g.append('<option value="' + escapeHtml(prev) + '">' + escapeHtml(prev) + ' (actual)</option>');
                }
                $g.val(prev);
            }
        });
    }

    function cargarCampaniasEditar(campaniaActual) {
        const g = ($('#editGranja').val() || '').trim();
        const $c = $('#editCampania');
        if (!$c.length) return Promise.resolve();
        $c.empty().append('<option value="">Seleccione campaña</option>');
        if (!g) {
            $c.prop('disabled', true);
            return Promise.resolve();
        }
        $c.prop('disabled', false);
        const prev = String(campaniaActual || '').trim();
        return cargarCencosEdit().then(function (cencos) {
            const visto = {};
            cencos.forEach(function (c) {
                const cod = String(c.codigo || '').trim();
                if (cod.length < 6) return;
                if (cod.slice(0, 3) !== g) return;
                const c3 = cod.slice(3, 6);
                if (visto[c3] || c3 === '000') return;
                visto[c3] = true;
                $c.append('<option value="' + escapeHtml(c3) + '">' + escapeHtml(c3) + '</option>');
            });
            if (prev) {
                if (!$c.find('option[value="' + prev.replace(/"/g, '\\"') + '"]').length) {
                    $c.append('<option value="' + escapeHtml(prev) + '">' + escapeHtml(prev) + ' (actual)</option>');
                }
                $c.val(prev);
            }
        });
    }

    function cargarGalponesEditar(galponActual) {
        const g = ($('#editGranja').val() || '').trim();
        const c = ($('#editCampania').val() || '').trim();
        const $gal = $('#editGalpon');
        if (!$gal.length) return Promise.resolve();
        const prev = String(galponActual || '').trim();
        $gal.empty().append('<option value="">Seleccione galpón</option>');
        if (!g || !c) {
            $gal.prop('disabled', true);
            return Promise.resolve();
        }
        $gal.prop('disabled', false);
        return cargarCencosEdit().then(function (cencos) {
            let galpones = [];
            let cenco = null;
            const codigo6 = g + c;
            cencos.forEach(function (x) {
                if (!cenco && String(x.codigo || '').trim() === codigo6) cenco = x;
            });
            if (!cenco) {
                cencos.forEach(function (x) {
                    if (!cenco && String(x.codigo || '').trim().slice(0, 3) === g) cenco = x;
                });
            }
            if (cenco) galpones = cenco.galpones || [];
            galpones.forEach(function (gp) {
                const t = String(gp && (gp.tcodint != null ? gp.tcodint : gp.galpon) || '').trim();
                if (!t) return;
                $gal.append('<option value="' + escapeHtml(t) + '">' + escapeHtml(t) + '</option>');
            });
            if (prev && !$gal.find('option[value="' + prev.replace(/"/g, '\\"') + '"]').length) {
                $gal.append('<option value="' + escapeHtml(prev) + '">' + escapeHtml(prev) + ' (actual)</option>');
            }
            if (prev) {
                $gal.val(prev);
            }
        });
    }

    function leerFiltros() {
        return {
            periodoTipo: ($('#periodoTipo').val() || 'ENTRE_FECHAS').trim(),
            fechaUnica: ($('#fechaUnica').val() || '').trim(),
            fechaInicio: ($('#fechaInicio').val() || '').trim(),
            fechaFin: ($('#fechaFin').val() || '').trim(),
            mesUnico: ($('#mesUnico').val() || '').trim(),
            mesInicio: ($('#mesInicio').val() || '').trim(),
            mesFin: ($('#mesFin').val() || '').trim(),
            granja: ($('#mrt-list-h-granja').val() || '').trim(),
            campania: ($('#mrt-list-h-campania').val() || '').trim(),
            galpon: ($('#filtroGalpon').val() || '').trim(),
            clavesGrupo: clavesGrupoSeleccionadas
        };
    }

    function paramsFiltros(f) {
        const p = new URLSearchParams();
        Object.keys(f).forEach(function (k) {
            if (f[k] !== '' && f[k] != null) {
                if (Array.isArray(f[k])) {
                    f[k].forEach(function (v) { p.append(k, v); });
                } else {
                    p.set(k, f[k]);
                }
            }
        });
        return p;
    }

    function aplicarVisibilidadPeriodo() {
        const t = $('#periodoTipo').val() || 'ENTRE_MESES';
        $('#periodoPorFecha, #periodoEntreFechas, #periodoPorMes, #periodoEntreMeses').addClass('hidden');
        if (t === 'POR_FECHA') $('#periodoPorFecha').removeClass('hidden');
        else if (t === 'ENTRE_FECHAS') $('#periodoEntreFechas').removeClass('hidden');
        else if (t === 'POR_MES') $('#periodoPorMes').removeClass('hidden');
        else if (t === 'ENTRE_MESES') $('#periodoEntreMeses').removeClass('hidden');
    }

    function exportarPdfMortalidad() {
        if (cfg.sinPermisos) return;
        const f = leerFiltros();
        const qs = paramsFiltros(f).toString();
        const url = cfg.pdfUrl + (qs ? '?' + qs : '');
        window.open(url, '_blank', 'noopener');
    }

    function cargarOpcionesFiltros() {
        const f = leerFiltros();
        const qs = paramsFiltros(f).toString();
        const url = cfg.filtrosUrl + (qs ? '?' + qs : '');

        return fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) return j;

                // Galpones (la granja ya viene del modal)
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

    function textoCausaDetalle(row, muestraCausa) {
        if (!muestraCausa || !row) return '';
        const text = String(row.nomMortalidad || row.codMortalidad || '').trim();
        return text;
    }

    /** Mapa de etapas desde fila de detalle (proceso_etapas o columnas sueltas). */
    function etapasDesdeFila(row) {
        const out = {};
        kEtapasDespacho.forEach(function (et) {
            let raw = null;
            if (row && row.proceso_etapas && row.proceso_etapas[et.col] !== undefined && row.proceso_etapas[et.col] !== null) {
                raw = row.proceso_etapas[et.col];
            } else if (row && row[et.col] !== undefined && row[et.col] !== null) {
                raw = row[et.col];
            }
            const n = parseInt(String(raw ?? '0'), 10);
            out[et.col] = (!isNaN(n) && n > 0) ? n : 0;
        });
        return out;
    }

    /** Etapas del despacho en solo lectura dentro del detalle (por sexo). */
    function htmlEtapasVerDespacho(row, esDespacho) {
        if (!esDespacho || !row) return '';
        const etapas = etapasDesdeFila(row);
        let suma = 0;
        kEtapasDespacho.forEach(function (et) {
            suma += etapas[et.col] || 0;
        });
        let html = '<div class="mt-2 pt-2 border-t border-gray-200">';
        html += '<p class="text-[11px] uppercase tracking-wide text-gray-400 mb-1.5 font-semibold">Etapas del proceso (' + suma + ')</p>';
        html += '<div class="grid grid-cols-3 gap-1.5">';
        kEtapasDespacho.forEach(function (et) {
            const v = etapas[et.col] || 0;
            html += '<div class="text-center bg-white border border-gray-200 rounded-lg px-1 py-1">';
            html += '<p class="text-[9px] uppercase tracking-wide text-gray-400 mb-0">' + et.nombre + '</p>';
            html += '<p class="text-sm font-semibold text-gray-800 mb-0">' + v + '</p>';
            html += '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function renderFilaSexo(row, etiqueta, muestraCausa, cabId, bloqueado, esDespacho) {
        if (!row) {
            return '<div class="mort-sexo-card mort-sexo-vacio"><p class="text-gray-400 text-xs mb-0">' + escapeHtml(etiqueta) + ': sin datos</p></div>';
        }
        let html = '<div class="mort-sexo-card text-xs">';
        html += '<div class="mort-sexo-card-head">';
        html += '<p class="font-semibold text-gray-800 mb-0">' + escapeHtml(etiqueta) + '</p>';
        html += '</div>';
        html += '<p class="mb-0.5"><span class="text-gray-500">Cantidad:</span> ' + escapeHtml(row.cantidad) + '</p>';
        const causa = textoCausaDetalle(row, muestraCausa);
        if (causa) {
            html += '<p class="mb-0.5"><span class="text-gray-500">Causa:</span> ' + escapeHtml(causa) + '</p>';
        }
        if (row.observacion) {
            html += '<p class="mb-0.5"><span class="text-gray-500">Obs.:</span> ' + escapeHtml(row.observacion) + '</p>';
        }
        const urls = row.evidenciaUrls || [];
        if (urls.length) {
            html += '<div class="mort-evidencia-grid">';
            urls.forEach(function (u) {
                html += '<a href="' + escapeHtml(u) + '" target="_blank" rel="noopener">';
                html += '<img src="' + escapeHtml(u) + '" alt="Evidencia">';
                html += '</a>';
            });
            html += '</div>';
        }
        html += htmlEtapasVerDespacho(row, !!esDespacho);
        html += '</div>';
        return html;
    }

    function construirHtmlDetalle(cab, detalleAgrupado) {
        const codGranja = cab.codigoGranja || cab.granja || '';
        const nomGranja = cab.nombreGranja || cab.granjaNombre || '';
        const camp = cab.codigoCampania || cab.campania || '';
        const cenco = codGranja + camp;
        const muestraCausa = cab.muestraCausa !== false;
        const cabId = cab.id || '';
        const bloqueado = !!(cab.fechaCerrada || cab.campaniaCerrada);

        let html = '<div class="space-y-4">';
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-gray-50 rounded-xl p-4">';
        const sinDocumento = !(cab.serie || cab.numero);
        html += '<p><strong>Documento:</strong> ' + (sinDocumento ? '<span class="text-gray-400 italic">Sin documento</span>' : escapeHtml((cab.serie || '') + '-' + (cab.numero || ''))) + '</p>';
        html += '<p><strong>Tipo:</strong> ' + escapeHtml(cab.tipoLabel || cab.tipoMortalidad || '') + '</p>';
        if (cab.subtipoLabel) {
            html += '<p><strong>Subtipo:</strong> ' + escapeHtml(cab.subtipoLabel) + '</p>';
        }
        if (cab.tipoMortalidad === 'incubacion' && cab.fechaLlegada) {
            html += '<p><strong>Fecha de llegada:</strong> ' + escapeHtml(formatearFechaDMY(cab.fechaLlegada)) + '</p>';
        }
        html += '<p><strong>Fecha de referencia:</strong> ' + escapeHtml(formatearFechaDMY(cab.fechaRegistro)) + '</p>';
        html += '<p><strong>Codigo granja:</strong> ' + escapeHtml(codGranja) + '</p>';
        html += '<p><strong>Nombre granja:</strong> ' + escapeHtml(nomGranja || '—') + '</p>';
        html += '<p><strong>Campana:</strong> ' + escapeHtml(camp || '—') + '</p>';
        html += '<p><strong>Galpon:</strong> ' + escapeHtml(cab.galpon || '—') + '</p>';
        html += '<p><strong>Usuario:</strong> ' + escapeHtml(String(cab.nombreUsuario || cab.usuarioRegistro || '').trim().toUpperCase()) + '</p>';
        html += '</div>';

        if (cab.observaciones) {
            html += '<p class="text-gray-700"><strong>Observaciones generales:</strong> ' + escapeHtml(cab.observaciones) + '</p>';
        }

        const tipoCab = String(cab.tipoMortalidad || '').toLowerCase();
        const subTransCab = String(cab.subtipoTransporte || '').toLowerCase();
        const subProdCab = String(cab.subtipoProduccion || '').toLowerCase();
        const multiDet = permiteMultiDetalle(tipoCab, subTransCab, subProdCab);
        const esDespacho = tipoCab === 'despacho';
        const grupos = detalleAgrupado || [];
        html += '<div class="mt-2"><h6 class="font-semibold text-gray-800 mb-2">' + (multiDet ? 'Detalles:' : 'Detalle:') + '</h6>';
        if (!grupos.length) {
            if (sinDocumento) {
                // Registro sin mortalidad (0 aves): mostrar ambos sexos con 0 explícito.
                html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3">';
                html += renderFilaSexo({ cantidad: 0 }, 'Macho', muestraCausa, cabId, bloqueado, esDespacho);
                html += renderFilaSexo({ cantidad: 0 }, 'Hembra', muestraCausa, cabId, bloqueado, esDespacho);
                html += '</div>';
            } else {
                html += '<p class="text-gray-500">Sin lineas de detalle.</p>';
            }
        } else {
            grupos.forEach(function (g, idx) {
                html += '<div class="mort-detalle-pos">';
                if (multiDet) {
                    html += '<p class="font-medium text-gray-800 mb-2">Detalle ' + (idx + 1) + '</p>';
                }
                html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3">';
                html += renderFilaSexo(g.macho, 'Macho', muestraCausa, cabId, bloqueado, esDespacho);
                html += renderFilaSexo(g.hembra, 'Hembra', muestraCausa, cabId, bloqueado, esDespacho);
                html += '</div></div>';
            });
        }
        html += '</div></div>';
        return html;
    }

    function abrirDetalle(id) {
        const $modal = $('#modalDetalleMort');
        $('#modalDetalleMortTitulo').text('Detalle de registro');
        $('#modalDetalleMortBody').html('<div class="text-gray-500">Cargando...</div>');
        $modal.removeClass('hidden').addClass('flex');

        fetch(cfg.detalleUrl + '?id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) {
                    $('#modalDetalleMortBody').html('<p class="text-red-600">' + escapeHtml(j.message || 'Error al cargar') + '</p>');
                    return;
                }
                const cab = j.cabecera || {};
                const agrupado = j.detalleAgrupado || [];
                detalleActualModal = agrupado;
                const numFac = cab.numFac || '';
                var titulo = 'Detalle';
                if (numFac) {
                    titulo = 'Num. fac ' + String(numFac).trim();
                }
                $('#modalDetalleMortTitulo').text(titulo);
                $('#modalDetalleMortBody').html(construirHtmlDetalle(cab, agrupado));
            })
            .catch(function () {
                $('#modalDetalleMortBody').html('<p class="text-red-600">Error de conexion.</p>');
            });
    }

    function cargarMotivos() {
        if (motivosPorTipoCache) {
            return Promise.resolve(motivosPorTipoCache);
        }
        if (!cfg.motivosUrl) {
            return Promise.resolve({});
        }
        return fetch(cfg.motivosUrl)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                motivosPorTipoCache = (j && j.motivos_por_tipo) ? j.motivos_por_tipo : {};
                motivosTodosCache = (j && j.motivos) ? j.motivos : [];
                return motivosPorTipoCache;
            })
            .catch(function () {
                motivosPorTipoCache = {};
                motivosTodosCache = [];
                return motivosPorTipoCache;
            });
    }

    function normalizarCodCausa(cod) {
        const s = String(cod == null ? '' : cod).trim();
        if (s === '') return '';
        if (/^\d+$/.test(s)) {
            return s.padStart(2, '0');
        }
        return s;
    }

    function listaMotivosParaTipo(tipoMort) {
        const cache = motivosPorTipoCache || {};
        const seen = {};
        const out = [];
        function pushLista(arr) {
            (arr || []).forEach(function (m) {
                const c = normalizarCodCausa(m.tcod_mort);
                if (!c || seen[c]) return;
                seen[c] = true;
                out.push({ tcod_mort: c, tnom_mort: String(m.tnom_mort || '') });
            });
        }
        // Todas las opciones del catalogo (libertad de eleccion)
        if (Array.isArray(motivosTodosCache) && motivosTodosCache.length) {
            pushLista(motivosTodosCache);
        } else {
            Object.keys(cache).forEach(function (k) {
                pushLista(cache[k] || []);
            });
        }
        out.sort(function (a, b) {
            return String(a.tnom_mort).localeCompare(String(b.tnom_mort), 'es');
        });
        return out;
    }

    function opcionesCausaHtml(tipoMort, codSeleccionado, nomSeleccionado) {
        const lista = listaMotivosParaTipo(tipoMort);
        const codSel = normalizarCodCausa(codSeleccionado);
        const nomSel = String(nomSeleccionado || '').trim().toLowerCase();
        let matched = false;
        let html = '<option value="">Seleccione causa</option>';

        lista.forEach(function (m) {
            const cod = normalizarCodCausa(m.tcod_mort);
            const nom = String(m.tnom_mort || '');
            const porCod = codSel !== '' && cod === codSel;
            const porNom = !porCod && nomSel !== '' && nom.trim().toLowerCase() === nomSel;
            const sel = (porCod || porNom) ? ' selected' : '';
            if (sel) matched = true;
            html += '<option value="' + escapeHtml(cod) + '" data-nombre="' + escapeHtml(nom) + '"' + sel + '>' + escapeHtml(nom || cod) + '</option>';
        });

        // Si la causa guardada no esta en el catalogo del tipo, inyectarla
        if (!matched && (codSel !== '' || nomSel !== '')) {
            let cod = codSel;
            let nom = String(nomSeleccionado || '').trim();
            if (cod === '' && nom !== '' && Array.isArray(motivosTodosCache)) {
                for (let i = 0; i < motivosTodosCache.length; i++) {
                    const m = motivosTodosCache[i];
                    if (String(m.tnom_mort || '').trim().toLowerCase() === nomSel) {
                        cod = normalizarCodCausa(m.tcod_mort);
                        nom = String(m.tnom_mort || nom);
                        break;
                    }
                }
            }
            if (cod === '' && nom !== '') {
                cod = '_custom';
            }
            if (cod !== '') {
                html += '<option value="' + escapeHtml(cod) + '" data-nombre="' + escapeHtml(nom) + '" selected>' + escapeHtml(nom || cod) + '</option>';
            }
        }
        return html;
    }

    function rutaDesdeEvidenciaUrl(u) {
        const s = String(u || '');
        const m = s.match(/[?&]ruta=([^&]+)/);
        if (m) {
            try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
        }
        if (s.indexOf('uploads/mortalidad/') >= 0) {
            return s.replace(/^.*?(uploads\/mortalidad\/)/, 'uploads/mortalidad/');
        }
        return '';
    }

    function calcMuestraCausa(tipo, subTrans, subProd) {
        const t = String(tipo || '').toLowerCase();
        if (t === 'incubacion') return false;
        if (t === 'produccion') {
            const s = String(subProd || '').toLowerCase();
            if (s === 'necropsia' || s === 'laboratorio') return false;
        }
        return true;
    }

    /** Misma logica de la app (_useInlineAvesEntry): solo algunos tipos permiten mas de 1 detalle. */
    function permiteMultiDetalle(tipo, subTrans, subProd) {
        const t = String(tipo || '').toLowerCase();
        if (t === 'transporte' || t === 'despacho') {
            return true;
        }
        if (t === 'produccion') {
            const s = String(subProd || 'crianza').toLowerCase();
            return s === 'crianza' || s === 'cuarentena';
        }
        // incubacion, necropsia, laboratorio: un solo bloque macho/hembra
        return false;
    }

    /** Registro 0 aves permitido en todos los tipos salvo Necropsia/Laboratorio de producción. */
    function permiteRegistroCero(tipo, subProd) {
        const t = String(tipo || '').toLowerCase();
        if (t === 'produccion') {
            const s = String(subProd || 'crianza').toLowerCase();
            if (s === 'necropsia' || s === 'laboratorio') return false;
        }
        return true;
    }

    /** Etiqueta amigable: "Detalle 1 Macho", "Detalle 2 Hembra", etc. */
    function etiquetaDetalleEdit($linea) {
        const $grupo = $linea.closest('.edit-grupo-det');
        let numDet = '';
        if ($grupo.length) {
            const titulo = ($grupo.find('.edit-grupo-titulo').first().text() || '').trim();
            const m = titulo.match(/Detalle\s+(\d+)/i);
            if (m) {
                numDet = m[1];
            } else {
                const idx = $('#editGruposContainer .edit-grupo-det').index($grupo);
                if (idx >= 0) {
                    numDet = String(idx + 1);
                }
            }
        }
        const sexo = String($linea.data('sexo') || $linea.find('.edit-sexo').val() || 'M');
        const sexoLabel = sexo === 'H' ? 'Hembra' : 'Macho';
        if (numDet !== '') {
            return 'Detalle ' + numDet + ' ' + sexoLabel;
        }
        return sexoLabel;
    }

    /** Vacia una linea macho/hembra pero mantiene los inputs para volver a ingresar datos. */
    function limpiarLineaSexoEdit($linea) {
        const sexo = String($linea.data('sexo') || $linea.find('.edit-sexo').val() || 'M');
        const etiqueta = sexo === 'H' ? 'Hembra' : 'Macho';
        const grupoKey = String($linea.data('grupo') || '');
        const tipoMort = tipoEditarActual();
        const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
        const subProd = ($('#editSubtipoProduccion').val() || '').trim();
        const muestraCausa = calcMuestraCausa(tipoMort, subTrans, subProd);
        const detIdAnterior = String($linea.data('detid') || '').trim();
        const $nueva = $(renderCampoEditarSexo(null, etiqueta, sexo, muestraCausa, tipoMort, grupoKey));
        if (detIdAnterior) {
            $nueva.attr('data-detid-eliminado', detIdAnterior);
        }
        $linea.replaceWith($nueva);
    }

    /** Vacia un bloque de detalle (macho + hembra) conservando la estructura del formulario. */
    function limpiarGrupoDetalleEdit($grupo) {
        const tipoMort = tipoEditarActual();
        const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
        const subProd = ($('#editSubtipoProduccion').val() || '').trim();
        const muestraCausa = calcMuestraCausa(tipoMort, subTrans, subProd);
        const grupoKey = String($grupo.data('grupo') || '');
        $grupo.find('.edit-det-linea').each(function () {
            const detIdAnterior = String($(this).data('detid') || '').trim();
            const sexo = String($(this).data('sexo') || $(this).find('.edit-sexo').val() || 'M');
            const etiqueta = sexo === 'H' ? 'Hembra' : 'Macho';
            const $nueva = $(renderCampoEditarSexo(null, etiqueta, sexo, muestraCausa, tipoMort, grupoKey));
            if (detIdAnterior) {
                $nueva.attr('data-detid-eliminado', detIdAnterior);
            }
            $(this).replaceWith($nueva);
        });
    }

    function sincronizarUiMultiDetalle() {
        const tipo = tipoEditarActual();
        const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
        const subProd = ($('#editSubtipoProduccion').val() || '').trim();
        const multi = permiteMultiDetalle(tipo, subTrans, subProd);
        const n = $('#editGruposContainer .edit-grupo-det').length;
        $('#btnAgregarDetalleMort').toggleClass('hidden', !multi);
        $('#editDetalleWrap h6').first().text(multi ? 'Detalles:' : 'Detalle:');
        $('#editGruposContainer .edit-grupo-det').each(function (i) {
            const $head = $(this).children('.flex').first();
            if (!$head.length) return;
            $head.toggleClass('hidden', !multi);
            $head.find('.edit-grupo-titulo').text('Detalle ' + (i + 1));
            $head.find('.btn-quitar-grupo-det').toggleClass('hidden', !multi);
        });
    }

    function renderFotosEditar(row) {
        const urls = (row && row.evidenciaUrls) ? row.evidenciaUrls : [];
        let html = '<div class="edit-fotos-wrap mt-2">';
        html += '<label class="block text-xs text-gray-500 mb-1">Evidencias</label>';
        html += '<div class="mort-evidencia-grid edit-fotos-grid">';
        urls.forEach(function (u) {
            const ruta = rutaDesdeEvidenciaUrl(u);
            if (!ruta) return;
            html += '<div class="edit-evidencia-item" data-ruta="' + escapeHtml(ruta) + '">';
            html += '<a href="' + escapeHtml(u) + '" target="_blank" rel="noopener"><img src="' + escapeHtml(u) + '" alt="Evidencia"></a>';
            html += '<button type="button" class="btn-quitar-foto" title="Quitar foto">&times;</button>';
            html += '</div>';
        });
        html += '</div>';
        html += '<label class="inline-flex items-center gap-1 text-xs text-sky-700 cursor-pointer mt-2">';
        html += '<i class="fas fa-camera"></i> Agregar fotos';
        html += '<input type="file" class="edit-fotos-nuevas hidden" accept="image/jpeg,image/png,image/webp,image/jpg" multiple>';
        html += '</label>';
        html += '</div>';
        return html;
    }

    function renderPreviewsPendientes($wrap) {
        const files = $wrap.data('pendingFiles') || [];
        $wrap.find('.edit-evidencia-preview').each(function () {
            const url = $(this).attr('data-blob');
            if (url) {
                try { URL.revokeObjectURL(url); } catch (e) { /* ignore */ }
            }
            $(this).remove();
        });
        const $grid = $wrap.find('.edit-fotos-grid');
        files.forEach(function (file, idx) {
            if (!file || !file.type || file.type.indexOf('image/') !== 0) return;
            const blobUrl = URL.createObjectURL(file);
            const $item = $('<div class="edit-evidencia-item edit-evidencia-preview" data-preview-idx="' + idx + '"></div>');
            $item.attr('data-blob', blobUrl);
            $item.append($('<img>').attr({ src: blobUrl, alt: 'Nueva' }));
            $item.append($('<button type="button" class="btn-quitar-foto btn-quitar-preview" title="Quitar foto">&times;</button>'));
            $grid.append($item);
        });
    }

    function htmlEtapasEditar(row, existe) {
        const v = function (et) {
            if (!row) return '';
            let raw = row[et.col];
            if (raw === undefined || raw === null || raw === '') {
                raw = (row.proceso_etapas && row.proceso_etapas[et.col] !== undefined)
                    ? row.proceso_etapas[et.col]
                    : '';
            }
            const n = parseInt(String(raw ?? ''), 10);
            return (!isNaN(n) && n > 0) ? String(n) : '';
        };
        let html = '<div class="edit-etapas-wrap mt-2">';
        html += '<div class="flex items-center justify-between mb-1">';
        html += '<label class="block text-xs text-gray-500 mb-0">Etapas del despacho</label>';
        html += '<span class="edit-etapas-total text-xs font-medium text-gray-600">Suma: 0</span>';
        html += '</div>';
        html += '<div class="grid grid-cols-3 gap-2">';
        kEtapasDespacho.forEach(function (et) {
            html += '<div>';
            html += '<label class="block text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">' + et.nombre + '</label>';
            html += '<input type="number" min="0" step="1" class="edit-etapa w-full border border-gray-300 rounded-lg px-1.5 py-1 text-xs" data-etapa="' + et.col + '" value="' + escapeHtml(v(et)) + '">';
            html += '</div>';
        });
        html += '</div>';
        html += '<p class="edit-etapas-msg text-xs mt-1 mb-0 text-amber-700 hidden">La suma de etapas debe ser igual a la cantidad.</p>';
        html += '</div>';
        return html;
    }

    function leerEtapasLinea($linea) {
        const valores = {};
        let suma = 0;
        $linea.find('.edit-etapa').each(function () {
            const id = $(this).data('etapa');
            const n = parseInt($(this).val(), 10);
            const v = (!isNaN(n) && n > 0) ? n : 0;
            valores[id] = v;
            suma += v;
        });
        return { valores: valores, suma: suma };
    }

    function actualizarCuadreEtapasLinea($linea) {
        const $wrap = $linea.find('.edit-etapas-wrap');
        if (!$wrap.length) return null;
        const leido = leerEtapasLinea($linea);
        const cant = parseInt($linea.find('.edit-cantidad').val(), 10);
        const conCantidad = !isNaN(cant) && cant >= 1;
        const cuadra = !conCantidad || leido.suma === cant;
        $wrap.find('.edit-etapas-total').text('Suma: ' + leido.suma);
        $wrap.find('.edit-etapas-msg').toggleClass('hidden', cuadra);
        return { valores: leido.valores, suma: leido.suma, cantidad: isNaN(cant) ? 0 : cant, cuadra: cuadra };
    }

    function actualizarCuadreEtapasTodas() {
        $('#formEditarMort .edit-det-linea').each(function () {
            actualizarCuadreEtapasLinea($(this));
        });
    }

    /** Garantiza el bloque de etapas del despacho en cada linea al cambiar el tipo a despacho. */
    function asegurarEtapasEditarDespacho() {
        const esDespacho = tipoEditarActual() === 'despacho';
        $('#formEditarMort .edit-det-linea').each(function () {
            const $linea = $(this);
            let $wrap = $linea.find('.edit-etapas-wrap').first();
            if (esDespacho && !$wrap.length) {
                $wrap = $(htmlEtapasEditar(null, false));
                const $fotos = $linea.find('.edit-fotos-wrap').first();
                if ($fotos.length) {
                    $fotos.before($wrap);
                } else {
                    $linea.append($wrap);
                }
            }
            if ($wrap.length) {
                $wrap.toggleClass('hidden', !esDespacho);
            }
        });
    }

    function renderCampoEditarSexo(row, etiqueta, sexo, muestraCausa, tipoMort, grupoKey) {
        const existe = !!(row && row.id);
        const detId = existe ? String(row.id) : '';
        const cant = (row && row.cantidad !== undefined && row.cantidad !== null && row.cantidad !== '')
            ? String(row.cantidad)
            : (existe ? String(row.cantidad ?? '') : '');
        const obs = existe ? (row.observacion || '') : '';
        const cod = existe ? (row.codMortalidad || '') : '';
        const nomCausa = existe ? (row.nomMortalidad || '') : '';
        const cantNum = parseInt(String(cant), 10);
        const tieneDatos = !!detId || (!isNaN(cantNum) && cantNum >= 1);

        let html = '<div class="bg-gray-50 rounded-xl p-3 border border-gray-200 edit-det-linea" data-detid="' + escapeHtml(detId) + '" data-sexo="' + escapeHtml(sexo) + '" data-grupo="' + escapeHtml(String(grupoKey)) + '">';
        html += '<div class="flex items-center justify-between gap-2 mb-2">';
        html += '<p class="font-semibold text-gray-700 text-xs mb-0">' + escapeHtml(etiqueta) + '</p>';
        if (tieneDatos) {
            html += '<button type="button" class="btn-quitar-linea-sexo-edit text-red-600 hover:text-red-800 text-xs inline-flex items-center gap-1" title="Eliminar ' + escapeHtml(etiqueta.toLowerCase()) + '">';
            html += '<i class="fas fa-trash-alt"></i><span>Eliminar</span></button>';
        }
        html += '</div>';
        html += '<input type="hidden" class="edit-sexo" value="' + escapeHtml(sexo) + '">';
        html += '<label class="block text-xs text-gray-500 mb-1">Cantidad</label>';
        const placeholderCant = existe ? '' : 'Ingrese cantidad';
        html += '<input type="number" min="0" step="1" class="edit-cantidad w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm mb-2" value="' + escapeHtml(cant) + '" placeholder="' + escapeHtml(placeholderCant) + '">';
        html += '<div class="edit-causa-wrap' + (muestraCausa ? '' : ' hidden') + '">';
        html += '<label class="block text-xs text-gray-500 mb-1">Causa</label>';
        html += '<select class="edit-causa w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm mb-2">' + opcionesCausaHtml(tipoMort, cod, nomCausa) + '</select>';
        html += '</div>';
        html += '<label class="block text-xs text-gray-500 mb-1">Observacion</label>';
        html += '<input type="text" class="edit-obs-det w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value="' + escapeHtml(obs) + '">';
        if (String(tipoMort || '').toLowerCase() === 'despacho') {
            html += htmlEtapasEditar(row || null, !!row);
        }
        html += renderFotosEditar(row || {});
        html += '</div>';
        return html;
    }

    function htmlGrupoDetalle(g, idx, muestraCausa, tipoMort, multiDetalle) {
        const grupoKey = (g && g.posicion) ? g.posicion : ('n' + idx);
        let html = '<div class="mort-detalle-pos mb-3 edit-grupo-det" data-grupo="' + escapeHtml(String(grupoKey)) + '">';
        if (multiDetalle) {
            html += '<div class="flex items-center justify-between mb-2">';
            html += '<p class="font-medium text-gray-800 edit-grupo-titulo">Detalle ' + (idx + 1) + '</p>';
            html += '<button type="button" class="btn-quitar-grupo-det text-red-500 hover:text-red-700 text-xs" title="Quitar este detalle"><i class="fas fa-times"></i> Quitar</button>';
            html += '</div>';
        }
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3">';
        html += renderCampoEditarSexo(g ? g.macho : null, 'Macho', 'M', muestraCausa, tipoMort, grupoKey);
        html += renderCampoEditarSexo(g ? g.hembra : null, 'Hembra', 'H', muestraCausa, tipoMort, grupoKey);
        html += '</div></div>';
        return html;
    }

    function tipoEditarActual() {
        const $sel = $('#editTipoMortalidad');
        const v = $sel.length ? String($sel.val() || '').trim() : '';
        if (v !== '') return v.toLowerCase();
        return String($('#formEditarMort').data('tipo') || '').toLowerCase();
    }

    function refrescarVisibilidadCausasEditar() {
        const tipo = tipoEditarActual();
        const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
        const subProd = ($('#editSubtipoProduccion').val() || '').trim();
        const muestra = calcMuestraCausa(tipo, subTrans, subProd);
        $('#formEditarMort .edit-causa-wrap').toggleClass('hidden', !muestra);
        asegurarEtapasEditarDespacho();
        if (tipo === 'despacho') {
            actualizarCuadreEtapasTodas();
        }
        sincronizarUiMultiDetalle();
    }

    function refrescarCampoCondicionalEditar() {
        const tipo = tipoEditarActual();
        $('#wrapFechaLlegada').toggleClass('hidden', tipo !== 'incubacion');
        $('#wrapSubtipoTransporte').toggleClass('hidden', tipo !== 'transporte');
        $('#wrapSubtipoProduccion').toggleClass('hidden', tipo !== 'produccion');
        $('#formEditarMort').data('tipo', tipo);
        refrescarVisibilidadCausasEditar();
    }

    function construirHtmlEditar(cab, detalleAgrupado) {
        const tipo = String(cab.tipoMortalidad || '').toLowerCase();
        const subTrans = String(cab.subtipoTransporte || '').toLowerCase();
        const subProd = String(cab.subtipoProduccion || 'crianza').toLowerCase();
        const muestraCausa = calcMuestraCausa(tipo, subTrans, subProd);
        const multiDetalle = permiteMultiDetalle(tipo, subTrans, subProd);
        const fecha = String(cab.fechaRegistro || '').slice(0, 10);
        const fechaLleg = String(cab.fechaLlegada || '').slice(0, 10);
        const numFac = cab.numFac ? String(cab.numFac).trim() : '';
        const docLabel = numFac || ((cab.serie || '') + '-' + (cab.numero || ''));
        const sinDocumento = !docLabel && permiteRegistroCero(tipo, subProd);
        const tiposApp = [
            { key: 'incubacion', label: 'Planta de incubación' },
            { key: 'transporte', label: 'Transporte' },
            { key: 'produccion', label: 'Producción' },
            { key: 'despacho', label: 'Despacho' }
        ];

        let html = '<form id="formEditarMort" class="space-y-4" data-cabid="' + escapeHtml(cab.id || '') + '" data-tipo="' + escapeHtml(tipo) + '">';
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-sky-50 rounded-xl p-4">';
        html += '<p><strong>Documento:</strong> ' + (sinDocumento ? '<span class="text-gray-400 italic">Sin documento</span>' : escapeHtml(docLabel)) + '</p>';
        html += '<p><strong>Usuario:</strong> ' + escapeHtml(String(cab.nombreUsuario || cab.usuarioRegistro || '').trim().toUpperCase()) + '</p>';
        html += '</div>';

        html += '<div class="grid grid-cols-1 md:grid-cols-3 gap-3">';
        html += '<div><label class="block text-xs text-gray-500 mb-1">Fecha</label>';
        html += '<input type="date" id="editFechaRegistro" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value="' + escapeHtml(fecha) + '" required></div>';
        html += '<div><label class="block text-xs text-gray-500 mb-1">Granja</label>';
        html += '<select id="editGranja" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm"><option value="">Cargando...</option></select></div>';
        html += '<div><label class="block text-xs text-gray-500 mb-1">Campaña</label>';
        html += '<select id="editCampania" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm" disabled><option value="">Cargando...</option></select></div>';
        html += '</div>';

        html += '<div class="grid grid-cols-1 md:grid-cols-3 gap-3">';
        html += '<div><label class="block text-xs text-gray-500 mb-1">Galpón</label>';
        html += '<select id="editGalpon" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm" disabled><option value="">Cargando...</option></select></div>';
        html += '<div><label class="block text-xs text-gray-500 mb-1">Tipo</label>';
        html += '<select id="editTipoMortalidad" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">';
        tiposApp.forEach(function (t) {
            html += '<option value="' + t.key + '"' + (tipo === t.key ? ' selected' : '') + '>' + t.label + '</option>';
        });
        html += '</select></div>';
        html += '<div id="editCampoCondicional">';
        html += '<div id="wrapFechaLlegada"' + (tipo === 'incubacion' ? '' : ' class="hidden"') + '>';
        html += '<label class="block text-xs text-gray-500 mb-1">Fecha llegada</label>';
        html += '<input type="date" id="editFechaLlegada" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm" value="' + escapeHtml(fechaLleg) + '"></div>';
        html += '<div id="wrapSubtipoTransporte"' + (tipo === 'transporte' ? '' : ' class="hidden"') + '>';
        html += '<label class="block text-xs text-gray-500 mb-1">Subtipo</label>';
        html += '<select id="editSubtipoTransporte" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">';
        html += '<option value="transporte"' + (subTrans === 'transporte' ? ' selected' : '') + '>Transporte</option>';
        html += '<option value="evento"' + (subTrans === 'evento' ? ' selected' : '') + '>Evento</option>';
        html += '</select></div>';
        html += '<div id="wrapSubtipoProduccion"' + (tipo === 'produccion' ? '' : ' class="hidden"') + '>';
        html += '<label class="block text-xs text-gray-500 mb-1">Subtipo</label>';
        html += '<select id="editSubtipoProduccion" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">';
        ['crianza', 'necropsia', 'laboratorio', 'cuarentena'].forEach(function (k) {
            const label = k.charAt(0).toUpperCase() + k.slice(1);
            html += '<option value="' + k + '"' + (subProd === k ? ' selected' : '') + '>' + label + '</option>';
        });
        html += '</select></div>';
        html += '</div>';
        html += '</div>';

        html += '<div><label class="block text-xs text-gray-500 mb-1">Observaciones</label>';
        html += '<textarea id="editObservaciones" rows="2" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">' + escapeHtml(cab.observaciones || '') + '</textarea></div>';

        html += '<div id="editDetalleWrap">';
        html += '<div class="flex items-center justify-between mb-2">';
        html += '<h6 class="font-semibold text-gray-800">' + (multiDetalle ? 'Detalles:' : 'Detalle:') + '</h6>';
        html += '<button type="button" id="btnAgregarDetalleMort" class="btn-outline text-sm py-1.5 px-3' + (multiDetalle ? '' : ' hidden') + '"><i class="fas fa-plus"></i> Agregar detalle</button>';
        html += '</div>';
        html += '<div id="editGruposContainer">';
        const grupos = detalleAgrupado || [];
        if (!grupos.length) {
            if (sinDocumento) {
                // Registro sin mortalidad (0 aves): dejar visible el 0 en los campos de cantidad.
                html += htmlGrupoDetalle(
                    { posicion: 0, macho: { cantidad: 0 }, hembra: { cantidad: 0 } },
                    0, muestraCausa, tipo, multiDetalle
                );
            } else {
                html += htmlGrupoDetalle(null, 0, muestraCausa, tipo, multiDetalle);
            }
        } else {
            grupos.forEach(function (g, idx) {
                html += htmlGrupoDetalle(g, idx, muestraCausa, tipo, multiDetalle);
            });
        }
        html += '</div></div></form>';
        return html;
    }

    function cerrarModalEditar() {
        $('#modalEditarMort').addClass('hidden').removeClass('flex');
        editCabIdActual = null;
    }

    function abrirEditar(id) {
        if (!id || cfg.sinPermisos) return;
        editCabIdActual = id;
        const $modal = $('#modalEditarMort');
        $('#modalEditarMortTitulo').text('Editar registro');
        $('#modalEditarMortBody').html('<div class="text-gray-500">Cargando...</div>');
        $('#btnGuardarEditarMort').prop('disabled', false);
        $modal.removeClass('hidden').addClass('flex');

        Promise.all([
            fetch(cfg.detalleUrl + '?id=' + encodeURIComponent(id)).then(function (r) { return r.json(); }),
            cargarMotivos()
        ]).then(function (results) {
            const j = results[0];
            if (!j || j.success === false) {
                $('#modalEditarMortBody').html('<p class="text-red-600">' + escapeHtml(j && j.message ? j.message : 'Error al cargar') + '</p>');
                return;
            }
            const cab = j.cabecera || {};

            // Seguridad: si el registro está bloqueado, no abrir el formulario.
            if (cab.fechaCerrada) {
                cerrarModalEditar();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Periodo cerrado', text: 'El registro pertenece a un periodo contable ya cerrado.' + (cab.motivoCierre ? ' ' + String(cab.motivoCierre) : '') });
                }
                return;
            }
            if (cab.campaniaCerrada) {
                cerrarModalEditar();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Campaña inactiva', text: 'La campaña del registro ya no está activa, por lo que no se puede editar ni eliminar.' });
                }
                return;
            }

            const numFac = cab.numFac ? String(cab.numFac).trim() : '';
            $('#modalEditarMortTitulo').text(numFac ? ('Editar — ' + numFac) : 'Editar registro');
            $('#modalEditarMortBody').html(construirHtmlEditar(cab, j.detalleAgrupado || []));
            const granjaActual = String(cab.codigoGranja || cab.granja || '').trim();
            const campaniaActual = String(cab.codigoCampania || cab.campania || '').trim();
            cargarGranjasEditar(granjaActual)
                .then(function () { return cargarCampaniasEditar(campaniaActual); })
                .then(function () { return cargarGalponesEditar(String(cab.galpon || '').trim()); });
            refrescarVisibilidadCausasEditar();
            sincronizarUiMultiDetalle();
        }).catch(function () {
            $('#modalEditarMortBody').html('<p class="text-red-600">Error de conexion.</p>');
        });
    }

    function leerPayloadEditar() {
        const cabId = editCabIdActual || ($('#formEditarMort').data('cabid') || '');
        const tipo = tipoEditarActual();
        const fecha = ($('#editFechaRegistro').val() || '').trim();
        const granja = ($('#editGranja').val() || '').trim();
        const campania = ($('#editCampania').val() || '').trim();
        const galpon = ($('#editGalpon').val() || '').trim();
        if (!cabId || !fecha || !granja || !campania || !galpon) {
            return { error: 'Complete granja, campaña, galpon y fecha.' };
        }
        const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
        const subProd = ($('#editSubtipoProduccion').val() || '').trim();
        const muestraCausa = calcMuestraCausa(tipo, subTrans, subProd);

        const payload = {
            id: cabId,
            tipoMortalidad: tipo,
            fechaRegistro: fecha,
            granja: granja,
            campania: campania,
            galpon: galpon,
            observaciones: ($('#editObservaciones').val() || '').trim(),
            detalle: []
        };
        if (tipo === 'incubacion') {
            payload.fechaLlegada = ($('#editFechaLlegada').val() || '').trim();
        }
        if (tipo === 'transporte') {
            payload.subtipoTransporte = subTrans || 'transporte';
        }
        if (tipo === 'produccion') {
            payload.subtipoProduccion = subProd || 'crianza';
        }

        let err = '';
        let hayLinea = false;
        const fotosSinCantDetalles = [];
        $('#formEditarMort .edit-det-linea').each(function () {
            const $el = $(this);
            const cantRaw = ($el.find('.edit-cantidad').val() || '').trim();
            const cant = cantRaw === '' ? 0 : parseInt(cantRaw, 10);
            const pendingFotos = ($el.find('.edit-fotos-wrap').data('pendingFiles') || []).length;
            if (pendingFotos > 0 && (!cant || cant < 1 || isNaN(cant))) {
                fotosSinCantDetalles.push(etiquetaDetalleEdit($el));
            }
            if (cantRaw !== '' && (isNaN(cant) || cant < 0)) {
                err = 'Cantidad invalida en ' + etiquetaDetalleEdit($el) + '.';
                return false;
            }
            if (tipo === 'despacho') {
                const cuadre = actualizarCuadreEtapasLinea($el);
                if (!cant || cant < 1) {
                    // Misma regla que la app: etapas sin cantidad exigen cantidad.
                    if (cuadre && cuadre.suma > 0) {
                        err = 'Ingrese la cantidad de aves en ' + etiquetaDetalleEdit($el) + ' (etapas sin cantidad).';
                        return false;
                    }
                    return;
                }
                if (cuadre && !cuadre.cuadra) {
                    err = 'La suma (' + cuadre.suma + ') no coincide con la cantidad (' + cuadre.cantidad + ') en ' + etiquetaDetalleEdit($el) + '. Revise.';
                    return false;
                }
            }
            if (!cant || cant < 1) {
                return;
            }
            hayLinea = true;
            const detId = String($el.data('detid') || '');
            const grupo = String($el.data('grupo') || '');
            const sexo = String($el.find('.edit-sexo').val() || $el.data('sexo') || 'M');
            let cod = '';
            let nom = '';
            if (muestraCausa) {
                const $causa = $el.find('.edit-causa');
                cod = ($causa.val() || '').trim();
                nom = ($causa.find('option:selected').data('nombre') || $causa.find('option:selected').text() || '').trim();
                if (nom.indexOf('Seleccione') === 0 || nom.indexOf('—') === 0) nom = '';
                if (!cod) {
                    err = 'Seleccione la causa en ' + etiquetaDetalleEdit($el) + '.';
                    return false;
                }
            }
            const evidKeep = [];
            $el.find('.edit-evidencia-item').each(function () {
                const r = ($(this).data('ruta') || '').toString().trim();
                if (r) evidKeep.push(r);
            });
            const detalleObj = {
                id: detId,
                grupo: grupo,
                sexo: sexo,
                cantidad: cant,
                codMortalidad: cod,
                nomMortalidad: nom,
                observacion: ($el.find('.edit-obs-det').val() || '').trim(),
                evidenciaKeep: evidKeep,
                fileKey: detId !== '' ? detId : ('tmp_' + grupo + '_' + sexo)
            };
            if (tipo === 'despacho') {
                const cuadre = actualizarCuadreEtapasLinea($el);
                if (cuadre) {
                    detalleObj.proceso_etapas = cuadre.valores;
                }
            }
            payload.detalle.push(detalleObj);
        });
        if (err) return { error: err };
        if (fotosSinCantDetalles.length) {
            let msgFotos;
            if (fotosSinCantDetalles.length === 1) {
                msgFotos = 'Ingrese cantidad en el ' + fotosSinCantDetalles[0] + ' donde agrego fotos nuevas.';
            } else {
                msgFotos = 'Ingrese cantidad en: ' + fotosSinCantDetalles.join(', ') + '.';
            }
            return { error: msgFotos };
        }
        if (!hayLinea && !permiteRegistroCero(tipo, subProd)) {
            return { error: 'Debe haber al menos un detalle con cantidad.' };
        }
        return { payload: payload };
    }

    function guardarEditar() {
        if (!cfg.actualizarUrl) return;
        const leido = leerPayloadEditar();
        if (leido.error) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: leido.error });
            }
            return;
        }
        const fd = new FormData();
        fd.append('data', JSON.stringify(leido.payload));

        $('#formEditarMort .edit-det-linea').each(function () {
            const $el = $(this);
            const cant = parseInt($el.find('.edit-cantidad').val(), 10);
            if (!cant || cant < 1) return;
            const detId = String($el.data('detid') || '');
            const grupo = String($el.data('grupo') || '');
            const sexo = String($el.find('.edit-sexo').val() || $el.data('sexo') || 'M');
            const fileKey = detId !== '' ? detId : ('tmp_' + grupo + '_' + sexo);
            const $wrap = $el.find('.edit-fotos-wrap');
            const pending = $wrap.data('pendingFiles') || [];
            for (let i = 0; i < pending.length; i++) {
                fd.append('img_' + fileKey + '_' + i, pending[i]);
            }
        });

        const $btn = $('#btnGuardarEditarMort');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
        fetch(cfg.actualizarUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Guardar');
                if (j && j.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: 'Guardado', text: j.message || 'Registro actualizado.', timer: 1800, showConfirmButton: false });
                    }
                    cerrarModalEditar();
                    if (tableMort) tableMort.ajax.reload(null, false);
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: j && j.message ? j.message : 'No se pudo guardar.' });
                    }
                }
            })
            .catch(function () {
                $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Guardar');
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexion.' });
                }
            });
    }

    function cargarTabla(resetPaging) {
        if (cfg.sinPermisos) return;

        // Si ya existe: recargar sin destruir (paginación / buscar)
        if (tableMort && $.fn.DataTable.isDataTable('#tablaMortalidad')) {
            tableMort.ajax.reload(null, resetPaging !== false);
            return;
        }

        tableMort = $('#tablaMortalidad').DataTable({
            processing: true,
            serverSide: true,
            autoWidth: false,
            deferRender: true,
            searching: true,
            searchDelay: 400,
            order: [[2, 'desc']],
            dom: '<"dt-top-row"<"flex items-center gap-6" l><"flex items-center gap-2" f>>rt<"dt-bottom-row"<"text-sm text-gray-600" i><"text-sm text-gray-600" p>>',
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
                    if (json && json.resumen) {
                        actualizarResumen(json.resumen);
                    }
                    return json.data || [];
                },
                error: function (xhr) {
                    console.error('Error listado mortalidad', xhr.status, xhr.responseText);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error al cargar', text: 'No se pudo obtener el listado de mortalidad.' });
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
                    data: 'numFac',
                    defaultContent: '',
                    render: function (data, type) {
                        if (!data) return '';
                        return String(data).trim();
                    }
                },
                {
                    data: 'fechaRegistro',
                    render: function (data, type) {
                        if (type !== 'display' && type !== 'filter') return data;
                        return formatearFechaDMY(data);
                    }
                },
                {
                    data: 'fechaHoraRegistro',
                    orderable: false,
                    render: function (data, type) {
                        if (type !== 'display' && type !== 'filter') return data;
                        return formatearHora(data);
                    }
                },
                { data: 'tipoLabel' },
                {
                    data: 'subtipoLabel',
                    defaultContent: ''
                },
                {
                    data: null,
                    render: function (data, type, row) {
                        const nom = row.granjaNombre || '';
                        const cenco = row.cenco || '';
                        return escapeHtml(nom ? nom + ' (' + cenco + ')' : cenco);
                    }
                },
                { data: 'galpon' },
                { data: 'edad', defaultContent: '' },
                { data: 'machos', className: 'text-right' },
                { data: 'hembras', className: 'text-right' },
                { data: 'totalAves', className: 'text-right font-semibold' },
                {
                    data: 'nombreUsuario',
                    defaultContent: '',
                    render: function (data, type, row) {
                        const t = String(data || row.usuarioRegistro || '').trim();
                        if (type !== 'display' && type !== 'filter') {
                            return data || row.usuarioRegistro || '';
                        }
                        return escapeHtml(t ? t.toUpperCase() : '');
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, row) {
                        const id = escapeHtml(row.id || '');
                        return '<button type="button" class="btn-detalle-mort text-blue-600 hover:text-blue-800 font-medium" data-id="' + id + '" title="Ver detalle"><i class="fas fa-eye"></i></button>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function (data, type, row) {
                        if (!cfg.puedeEditarEliminar) {
                            return '<span class="text-gray-300">—</span>';
                        }
                        const id = escapeHtml(row.id || '');
                        const campaniaInactiva = row.campaniaActiva === false || row.campaniaActiva === 0 || row.campaniaActiva === '0';
                        const periodoCerrado = !!row.fechaCerrada;
                        const bloqueoEditar = periodoCerrado || campaniaInactiva;
                        const bloqueoEliminar = cfg.tieneRolSistemas
                            ? false
                            : (campaniaInactiva || periodoCerrado);
                        if (bloqueoEditar || bloqueoEliminar) {
                            const motivoEdit = campaniaInactiva ? 'campania' : (periodoCerrado ? 'periodo' : '');
                            const tituloEdit = campaniaInactiva ? 'Campaña inactiva' : (periodoCerrado ? 'Periodo cerrado' : '');
                            let htmlAcc = '<div class="inline-flex items-center gap-2">';
                            if (bloqueoEditar) {
                                htmlAcc += '<button type="button" class="btn-bloqueado-mort text-gray-300 cursor-not-allowed" data-id="' + id + '" data-motivo="' + motivoEdit + '" title="' + tituloEdit + '"><i class="fas fa-pen"></i></button>';
                            } else {
                                htmlAcc += '<button type="button" class="btn-editar-mort text-amber-600 hover:text-amber-800 font-medium" data-id="' + id + '" title="Editar"><i class="fas fa-pen"></i></button>';
                            }
                            if (bloqueoEliminar) {
                                const motivoDel = campaniaInactiva ? 'campania' : 'periodo';
                                const tituloDel = campaniaInactiva ? 'Campaña inactiva' : 'Periodo cerrado';
                                htmlAcc += '<button type="button" class="btn-bloqueado-mort text-gray-300 cursor-not-allowed" data-id="' + id + '" data-motivo="' + motivoDel + '" title="' + tituloDel + '"><i class="fas fa-trash"></i></button>';
                            } else {
                                htmlAcc += '<button type="button" class="btn-eliminar-mort text-red-600 hover:text-red-800 font-medium" data-id="' + id + '" title="Eliminar"><i class="fas fa-trash"></i></button>';
                            }
                            htmlAcc += '</div>';
                            return htmlAcc;
                        }
                        return '<div class="inline-flex items-center gap-2">' +
                            '<button type="button" class="btn-editar-mort text-amber-600 hover:text-amber-800 font-medium" data-id="' + id + '" title="Editar"><i class="fas fa-pen"></i></button>' +
                            '<button type="button" class="btn-eliminar-mort text-red-600 hover:text-red-800 font-medium" data-id="' + id + '" title="Eliminar"><i class="fas fa-trash"></i></button>' +
                            '</div>';
                    }
                }
            ],
            columnDefs: [{ orderable: false, targets: [0, 4, 13, 14] }],
            language: window.DATATABLES_LANG_ES || {},
            pageLength: 25,
            lengthMenu: [[25, 50, 100], [25, 50, 100]]
        });
    }

    function limpiarFiltros() {
        const d = new Date();
        const anio = d.getFullYear();
        $('#periodoTipo').val('ENTRE_MESES');
        $('#fechaUnica').val(d.toISOString().slice(0, 10));
        $('#fechaInicio').val(anio + '-01-01');
        $('#fechaFin').val(anio + '-12-31');
        $('#mesUnico').val(anio + '-' + String(d.getMonth() + 1).padStart(2, '0'));
        $('#mesInicio').val(anio + '-01');
        $('#mesFin').val(anio + '-12');
        $('#mrt-list-h-granja').val('');
        $('#mrt-list-h-campania').val('');
        syncGranjaDisplay();
        $('#filtroGalpon').val('').prop('disabled', true);
        clavesGrupoSeleccionadas = [];
        actualizarEtiquetaGruposFiltro();
        aplicarVisibilidadPeriodo();
        cargarOpcionesFiltros().then(cargarTabla);
    }

    function eliminarRegistroCompleto(id) {
        const eliminarUrl = cfg.eliminarUrl || cfg.listarUrl.replace('listar_registros.php', 'eliminar_registro.php');
        return fetch(eliminarUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: 'Eliminado', text: j.message || 'Registro eliminado correctamente.', timer: 2000, showConfirmButton: false });
                    }
                    $('#modalDetalleMort').addClass('hidden').removeClass('flex');
                    detalleActualModal = null;
                    if (tableMort) tableMort.ajax.reload(null, false);
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: j && j.message ? j.message : 'Error al eliminar.' });
                    }
                }
                return j;
            })
            .catch(function () {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexion.' });
                }
            });
    }

    $(document).ready(function () {
        $('#btnToggleFiltrosMort').on('click', function () {
            $('#contenidoFiltrosMort').toggleClass('hidden');
            $('#iconoFiltrosMort').toggleClass('rotate-180');
        });

        $('#periodoTipo').on('change', function () {
            aplicarVisibilidadPeriodo();
            var g = ($('#mrt-list-h-granja').val() || '').trim();
            if (g) {
                cargarOpcionesFiltros();
            }
        });

        // Grupo de registro modal
        $('#btnAbrirGruposFiltro').on('click', function () {
            sincronizarCheckboxesModal();
            $('#modalGruposFiltro').removeClass('hidden').addClass('flex');
        });
        $('#btnCerrarGruposFiltro, #modalGruposFiltro').on('click', function (e) {
            if (e.target === this) {
                $('#modalGruposFiltro').addClass('hidden').removeClass('flex');
            }
        });
        $('#btnLimpiarGruposModal').on('click', function () {
            $('.chk-grupo-filtro').prop('checked', false);
        });
        $('#btnAplicarGruposModal').on('click', function () {
            clavesGrupoSeleccionadas = leerCheckboxesModal();
            actualizarEtiquetaGruposFiltro();
            $('#modalGruposFiltro').addClass('hidden').removeClass('flex');
        });

        $('#btnFiltrarMort').on('click', function () { cargarTabla(true); });
        $('#btnExportarPdfMort').on('click', exportarPdfMortalidad);
        $('#btnLimpiarMort').on('click', limpiarFiltros);

        initMrtListGmc();

        // Cascada de selects del modal de edicion: granja -> campaña -> galpon
        $(document).on('change', '#editGranja', function () {
            $('#editCampania').val('').empty().append('<option value="">Seleccione campaña</option>');
            $('#editGalpon').val('').empty().append('<option value="">Seleccione galpón</option>').prop('disabled', true);
            cargarCampaniasEditar();
        });
        $(document).on('change', '#editCampania', function () {
            $('#editGalpon').val('').empty().append('<option value="">Seleccione galpón</option>').prop('disabled', true);
            cargarGalponesEditar();
        });

        $(document).on('click', '.btn-detalle-mort', function () {
            const id = $(this).data('id');
            if (id) abrirDetalle(id);
        });

        // Registros bloqueados (periodo cerrado / campaña inactiva): no abrir
        // ningún diálogo, solo informar con un sweetalert.
        $(document).on('click', '.btn-bloqueado-mort', function () {
            const motivo = $(this).data('motivo') === 'periodo' ? 'periodo' : 'campania';
            if (typeof Swal === 'undefined') return;
            if (motivo === 'periodo') {
                Swal.fire({ icon: 'warning', title: 'Periodo cerrado', text: 'El registro pertenece a un periodo contable ya cerrado, por lo que no se puede editar ni eliminar.' });
            } else {
                Swal.fire({ icon: 'warning', title: 'Campaña inactiva', text: 'La campaña del registro ya no está activa, por lo que no se puede editar ni eliminar.' });
            }
        });

        $(document).on('click', '.btn-editar-mort', function () {
            if (!cfg.puedeEditarEliminar) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Sin permiso', text: 'No tiene permiso para editar registros. Se requiere rol de sistemas o usuario autorizado.' });
                }
                return;
            }
            const id = $(this).data('id');
            if (id) abrirEditar(id);
        });

        $('#btnCerrarEditarMort, #btnCancelarEditarMort, #modalEditarMort').on('click', function (e) {
            if (e.target === this || e.target.id === 'btnCerrarEditarMort' || e.target.id === 'btnCancelarEditarMort') {
                cerrarModalEditar();
            }
        });
        $('#btnGuardarEditarMort').on('click', guardarEditar);

        $(document).on('click', '#btnAgregarDetalleMort', function () {
            const tipo = tipoEditarActual();
            const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
            const subProd = ($('#editSubtipoProduccion').val() || '').trim();
            if (!permiteMultiDetalle(tipo, subTrans, subProd)) {
                return;
            }
            const muestra = calcMuestraCausa(tipo, subTrans, subProd);
            const idx = $('#editGruposContainer .edit-grupo-det').length;
            const grupoKey = 'n' + Date.now();
            const html = htmlGrupoDetalle({ posicion: grupoKey, macho: null, hembra: null }, idx, muestra, tipo, true);
            $('#editGruposContainer').append(html);
            $('#editGruposContainer .edit-grupo-det').each(function (i) {
                $(this).find('.edit-grupo-titulo').text('Detalle ' + (i + 1));
            });
            sincronizarUiMultiDetalle();
        });

        $(document).on('click', '.btn-quitar-grupo-det', function () {
            const tipo = String($('#formEditarMort').data('tipo') || '').toLowerCase();
            const subTrans = ($('#editSubtipoTransporte').val() || '').trim();
            const subProd = ($('#editSubtipoProduccion').val() || '').trim();
            if (!permiteMultiDetalle(tipo, subTrans, subProd)) {
                return;
            }
            const $grupo = $(this).closest('.edit-grupo-det');
            const $grupos = $('#editGruposContainer .edit-grupo-det');
            const limpiar = function () {
                if ($grupos.length <= 1) {
                    limpiarGrupoDetalleEdit($grupo);
                    return;
                }
                $grupo.remove();
                $('#editGruposContainer .edit-grupo-det').each(function (i) {
                    $(this).find('.edit-grupo-titulo').text('Detalle ' + (i + 1));
                });
                sincronizarUiMultiDetalle();
            };
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Quitar detalle',
                    text: $grupos.length <= 1
                        ? 'Se limpiaran los datos de este detalle al guardar. Podra ingresar informacion nueva.'
                        : 'Este bloque se eliminara al guardar los cambios.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Si, quitar',
                    cancelButtonText: 'Cancelar'
                }).then(function (result) {
                    if (result.isConfirmed) limpiar();
                });
            } else {
                limpiar();
            }
        });

        $(document).on('click', '.btn-quitar-foto', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.edit-evidencia-item');
            if ($item.hasClass('edit-evidencia-preview')) {
                const $wrap = $item.closest('.edit-fotos-wrap');
                const idx = parseInt($item.attr('data-preview-idx'), 10);
                const files = ($wrap.data('pendingFiles') || []).slice();
                if (!isNaN(idx) && idx >= 0 && idx < files.length) {
                    files.splice(idx, 1);
                }
                $wrap.data('pendingFiles', files);
                renderPreviewsPendientes($wrap);
                return;
            }
            $item.remove();
        });

        $(document).on('change', '.edit-fotos-nuevas', function () {
            const input = this;
            const $wrap = $(input).closest('.edit-fotos-wrap');
            const actuales = ($wrap.data('pendingFiles') || []).slice();
            const nuevos = input.files ? Array.prototype.slice.call(input.files) : [];
            nuevos.forEach(function (f) {
                if (f && f.type && f.type.indexOf('image/') === 0) {
                    actuales.push(f);
                }
            });
            $wrap.data('pendingFiles', actuales);
            input.value = '';
            renderPreviewsPendientes($wrap);
        });

        $(document).on('change', '#editSubtipoProduccion, #editSubtipoTransporte', refrescarVisibilidadCausasEditar);
        $(document).on('change', '#editTipoMortalidad', refrescarCampoCondicionalEditar);
        $(document).on('input', '#formEditarMort .edit-etapa, #formEditarMort .edit-cantidad', function () {
            const $linea = $(this).closest('.edit-det-linea');
            if ($linea.length) actualizarCuadreEtapasLinea($linea);
        });

        // Eliminar registro (solo usuarios autorizados)
        $(document).on('click', '.btn-eliminar-mort', function () {
            if (!cfg.puedeEditarEliminar) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Sin permiso', text: 'No tiene permiso para eliminar registros. Se requiere rol de sistemas o usuario autorizado.' });
                }
                return;
            }
            const id = $(this).data('id');
            if (!id) return;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Confirmar eliminacion',
                    text: 'Se eliminara el registro y todos sus datos asociados. Esta accion no se puede deshacer.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Si, eliminar',
                    cancelButtonText: 'Cancelar'
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    eliminarRegistroCompleto(id);
                });
            }
        });

        $(document).on('click', '.btn-quitar-linea-sexo-edit', function () {
            if (!cfg.puedeEditarEliminar) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'warning', title: 'Sin permiso', text: 'No tiene permiso para eliminar detalles.' });
                }
                return;
            }
            const $linea = $(this).closest('.edit-det-linea');
            if (!$linea.length) return;

            const limpiar = function () {
                limpiarLineaSexoEdit($linea);
            };
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Eliminar registro',
                    text: 'Los datos se borraran al guardar. .',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Si, limpiar',
                    cancelButtonText: 'Cancelar'
                }).then(function (result) {
                    if (result.isConfirmed) limpiar();
                });
            } else {
                limpiar();
            }
        });

        $('#btnCerrarDetalleMort, #modalDetalleMort').on('click', function (e) {
            if (e.target === this || e.target.id === 'btnCerrarDetalleMort') {
                $('#modalDetalleMort').addClass('hidden').removeClass('flex');
                detalleActualModal = null;
            }
        });

        aplicarVisibilidadPeriodo();
        cargarTabla();
        cargarOpcionesFiltros();

        // Reajustar columnas cuando cambie el ancho (sidebar toggle)
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(function () {
                if (tableMort) {
                    tableMort.columns.adjust();
                }
            });
            ro.observe(document.querySelector('.tabla-listado-wrapper') || document.body);
        }
    });
}());
