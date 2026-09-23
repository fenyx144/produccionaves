(function () {
    'use strict';

    const cfg = window.MORT_AUD_LISTADO_CFG || {};
    let tableAud = null;
    let clavesGrupoSeleccionadas = [];
    let mrtAudGmc = null;

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
            const v = ($(this).val() || '').trim();
            if (v) sel.push(v);
        });
        return sel;
    }

    function actualizarResumen(resumen) {
        const r = resumen || {};
        $('#resumenRegistros').text((r.totalRegistros ?? 0).toLocaleString('es-PE'));
        $('#resumenTotalAves').text((r.totalAves ?? 0).toLocaleString('es-PE'));
    }

    function syncGranjaDisplay() {
        var g = ($('#mrt-aud-h-granja').val() || '').trim();
        var c = ($('#mrt-aud-h-campania').val() || '').trim();
        var inp = document.getElementById('mrt-aud-granja-resumen');
        if (!inp) return;
        if (g && c) {
            var nom = '';
            var meta = (cfg.granjasMeta || []).find(function(m) { return m.granja === g; });
            if (meta) nom = meta.nombre || '';
            inp.value = g + (nom ? ' ' + nom : '') + ' - ' + c;
            inp.title = 'Granja ' + g + (nom ? ' ' + nom : '') + ', Campaña ' + c + ' - Clic para cambiar';
        } else {
            inp.value = '';
            inp.title = 'Clic para seleccionar granja y campaña';
        }
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

    function initMrtAudGmc() {
        if (!window.GmcGranjasCampanias) {
            console.warn('MORT-AUD: GmcGranjasCampanias no disponible');
            return;
        }
        if (mrtAudGmc) return;
        mrtAudGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-aud',
            shellPanelId: 'mrt-aud-modal-granjas',
            mode: 'single',
            bodyOpenClass: 'mrt-aud-modal-granjas-open',
            openTriggerId: 'mrt-aud-granja-resumen',
            getCodigoSeis: function () {
                var g = ($('#mrt-aud-h-granja').val() || '').trim();
                var c = ($('#mrt-aud-h-campania').val() || '').trim();
                return g && c ? g + c : '';
            },
            setCodigoSeis: function (cod) {
                if (cod && cod.length >= 6) {
                    $('#mrt-aud-h-granja').val(cod.slice(0, 3));
                    $('#mrt-aud-h-campania').val(cod.slice(-3));
                } else {
                    $('#mrt-aud-h-granja').val('');
                    $('#mrt-aud-h-campania').val('');
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
                        return { granjas: cfg.granjasMeta || [], campanias_por_granja: {}, aviso: 'Error al cargar campañas' };
                    });
            },
            onApply: function () {
                syncGranjaDisplay();
                cargarGalponesAud();
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
            granja: ($('#mrt-aud-h-granja').val() || '').trim(),
            campania: ($('#mrt-aud-h-campania').val() || '').trim(),
            galpon: ($('#filtroGalpon').val() || '').trim()
        };
    }

    function aplicarVisibilidadPeriodo() {
        const t = $('#periodoTipo').val() || 'ENTRE_MESES';
        $('#periodoPorFecha, #periodoEntreFechas, #periodoPorMes, #periodoEntreMeses').addClass('hidden');
        if (t === 'POR_FECHA') $('#periodoPorFecha').removeClass('hidden');
        else if (t === 'ENTRE_FECHAS') $('#periodoEntreFechas').removeClass('hidden');
        else if (t === 'POR_MES') $('#periodoPorMes').removeClass('hidden');
        else if (t === 'ENTRE_MESES') $('#periodoEntreMeses').removeClass('hidden');
    }

    function renderEvidencias(urls) {
        if (!urls || !urls.length) return '';
        let html = '<div class="mort-evidencia-grid">';
        urls.forEach(function (u) {
            html += '<a href="' + escapeHtml(u) + '" target="_blank" rel="noopener">';
            html += '<img src="' + escapeHtml(u) + '" alt="Evidencia">';
            html += '</a>';
        });
        html += '</div>';
        return html;
    }

    function construirHtmlDetalle(sesion, grupos) {
        const s = sesion || {};
        let html = '<div class="space-y-4">';
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-gray-50 rounded-xl p-4">';
        html += '<p><strong>Fecha:</strong> ' + escapeHtml(formatearFechaDMY(s.fecha)) + '</p>';
        html += '<p><strong>Hora registro:</strong> ' + escapeHtml(formatearHora(s.fechaHoraRegistro)) + '</p>';
        html += '<p><strong>Auditor:</strong> ' + escapeHtml(s.nombreAuditor || s.usuarioRegistro || '') + '</p>';
        html += '<p><strong>Granja:</strong> ' + escapeHtml(s.codGranja ? s.codGranja + ' ' + (s.nombreGranja || '') : '—') + '</p>';
        html += '<p><strong>Campaña:</strong> ' + escapeHtml(s.campania || '—') + '</p>';
        html += '<p><strong>Galpón:</strong> ' + escapeHtml(s.galpon || '—') + '</p>';
        html += '<p><strong>Registros auditados:</strong> ' + escapeHtml(s.totalRegistros ?? 0) + '</p>';
        html += '<p><strong>Total aves:</strong> ' + escapeHtml(s.totalAves ?? 0) + '</p>';
        html += '</div>';

        if (s.observaciones) {
            html += '<div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 rounded"><p class="text-gray-700"><strong>Observaciones generales:</strong> ' + escapeHtml(s.observaciones) + '</p></div>';
        }

        var evidenciaUrls = s.evidenciaUrls || [];
        if (evidenciaUrls.length) {
            html += '<div class="mt-4"><h6 class="font-semibold text-gray-800 mb-2">Fotos de la auditoría</h6>';
            html += '<div class="aud-foto-grid" style="display:flex;flex-wrap:wrap;gap:0.5rem;">';
            evidenciaUrls.forEach(function (u) {
                html += '<a href="' + escapeHtml(u) + '" target="_blank" rel="noopener">';
                html += '<img src="' + escapeHtml(u) + '" alt="Foto auditoría" class="w-32 h-32 object-cover rounded-lg border border-gray-200 cursor-pointer hover:opacity-80 transition">';
                html += '</a>';
            });
            html += '</div></div>';
        }

        // Mostrar cada grupo/registro auditado
        if (grupos && grupos.length) {
            html += '<div class="mt-6"><h6 class="font-semibold text-gray-800 mb-3 text-base border-b pb-2">Registros auditados (' + grupos.length + ')</h6>';
            grupos.forEach(function (g, idx) {
                html += '<div class="border border-gray-200 rounded-lg p-3 mb-3 bg-white">';
                html += '<p class="font-medium text-gray-800">' + (idx + 1) + '. ' + escapeHtml(g.label || g.clave || '') + '</p>';
                html += '<p class="text-sm text-gray-600">Registros: ' + escapeHtml(g.totalRegistros ?? 0) + ' | Aves: ' + escapeHtml(g.totalAves ?? 0) + '  \u2022  Machos: ' + Number(g.machos || 0).toLocaleString('es-PE') + '  \u2022  Hembras: ' + Number(g.hembras || 0).toLocaleString('es-PE') + '</p>';

                // Detalle individual de cada registro
                var dts = g.detalles || [];
                if (dts.length > 0) {
                    html += '<div class="mt-2 text-xs">';
                    html += '<table class="w-full border-collapse">';
                    html += '<thead><tr class="text-gray-500">';
                    html += '<th class="text-left px-1 py-0.5 border-b w-6">#</th>';
                    html += '<th class="text-left px-1 py-0.5 border-b">Fecha</th>';
                    html += '<th class="text-right px-1 py-0.5 border-b">M</th>';
                    html += '<th class="text-right px-1 py-0.5 border-b">H</th>';
                    html += '<th class="text-right px-1 py-0.5 border-b">Aves</th>';
                    html += '</tr></thead><tbody>';
                    dts.forEach(function (d, di) {
                        html += '<tr class="' + (di % 2 === 0 ? '' : 'bg-gray-50') + '">';
                        html += '<td class="px-1 py-0.5 border-b text-gray-400">' + (di + 1) + '</td>';
                        html += '<td class="px-1 py-0.5 border-b">' + escapeHtml(formatearFechaDMY(d.fechaRegistro)) + '</td>';
                        html += '<td class="px-1 py-0.5 border-b text-right">' + Number(d.machos || 0).toLocaleString('es-PE') + '</td>';
                        html += '<td class="px-1 py-0.5 border-b text-right">' + Number(d.hembras || 0).toLocaleString('es-PE') + '</td>';
                        html += '<td class="px-1 py-0.5 border-b text-right font-medium">' + Number(d.totalAves || 0).toLocaleString('es-PE') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    html += '</div>';
                }

                if (g.observaciones) {
                    html += '<p class="text-sm text-gray-700 mt-1"><span class="font-medium">Obs.:</span> ' + escapeHtml(g.observaciones) + '</p>';
                }
                var gUrls = g.evidenciaUrls || [];
                if (gUrls.length) {
                    html += '<div class="flex flex-wrap gap-2 mt-2">';
                    gUrls.forEach(function (u) {
                        html += '<a href="' + escapeHtml(u) + '" target="_blank" rel="noopener">';
                        html += '<img src="' + escapeHtml(u) + '" alt="Foto" class="w-20 h-20 object-cover rounded border border-gray-200 cursor-pointer hover:opacity-80 transition">';
                        html += '</a>';
                    });
                    html += '</div>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function abrirDetalle(id) {
        const $modal = $('#modalDetalleAud');
        $('#modalDetalleAudTitulo').text('Detalle de auditoría');
        $('#modalDetalleAudBody').html('<div class="text-gray-500">Cargando...</div>');
        $modal.removeClass('hidden').addClass('flex');

        fetch(cfg.detalleUrl + '?id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) {
                    $('#modalDetalleAudBody').html('<p class="text-red-600">' + escapeHtml(j.message || 'Error al cargar') + '</p>');
                    return;
                }
                var sesion = j.sesion || {};
                var grupos = j.grupos || [];
                $('#modalDetalleAudTitulo').text('Auditoría ' + formatearFechaDMY(sesion.fecha) + ' — ' + (sesion.nombreAuditor || sesion.usuarioRegistro || ''));
                $('#modalDetalleAudBody').html(construirHtmlDetalle(sesion, grupos));
            })
            .catch(function () {
                $('#modalDetalleAudBody').html('<p class="text-red-600">Error de conexión.</p>');
            });
    }

    function cargarTabla(resetPaging) {
        // Si ya existe: recargar sin destruir
        if (tableAud && $.fn.DataTable.isDataTable('#tablaAuditoria')) {
            tableAud.ajax.reload(null, resetPaging !== false);
            return;
        }

        tableAud = $('#tablaAuditoria').DataTable({
            processing: true,
            serverSide: true,
            autoWidth: false,
            deferRender: true,
            searching: true,
            searchDelay: 400,
            order: [[1, 'desc'], [2, 'desc']],
            dom: '<"dt-top-row"<"flex items-center gap-6" l><"flex items-center gap-2" f>>rt<"dt-bottom-row"<"text-sm text-gray-600" i><"text-sm text-gray-600" p>>',
            ajax: {
                url: cfg.listarUrl,
                type: 'POST',
                data: function (d) {
                    var f = leerFiltros();
                    Object.keys(f).forEach(function (k) {
                        if (f[k] !== '' && f[k] != null) {
                            d[k] = f[k];
                        }
                    });
                    d.clavesGrupo = clavesGrupoSeleccionadas.slice();
                    return d;
                },
                dataSrc: function (json) {
                    if (json && json.resumen) {
                        actualizarResumen(json.resumen);
                    }
                    if (json && json.warning && typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'warning', title: 'Aviso', text: json.warning });
                    }
                    return json.data || [];
                },
                error: function (xhr) {
                    console.error('Error listado auditoría', xhr.status, xhr.responseText);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error al cargar', text: 'No se pudo obtener el listado de auditorías.' });
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
                        return type === 'display' ? formatearFechaDMY(data) : data;
                    }
                },
                {
                    data: 'fechaHoraRegistro',
                    orderable: false,
                    render: function (data, type) {
                        return type === 'display' ? formatearHora(data) : data;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function (data, type, row) {
                        var cod = row.codGranja || '';
                        var nom = row.nombreGranja || '';
                        if (cod && nom) return escapeHtml(cod + ' ' + nom);
                        if (cod) return escapeHtml(cod);
                        return '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'campania',
                    orderable: false,
                    defaultContent: '<span class="text-gray-400">—</span>',
                    render: function (data) {
                        return data ? escapeHtml(data) : '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'galpon',
                    orderable: false,
                    defaultContent: '<span class="text-gray-400">—</span>',
                    render: function (data) {
                        return data ? escapeHtml(data) : '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'edad',
                    orderable: false,
                    defaultContent: '<span class="text-gray-400">—</span>',
                    render: function (data) {
                        return data ? escapeHtml(data) : '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'nombreAuditor',
                    defaultContent: '',
                    render: function (data, type, row) {
                        return escapeHtml(data || row.usuarioRegistro || '');
                    }
                },
                {
                    data: 'totalAves',
                    className: 'text-center',
                    render: function (data) {
                        return Number(data || 0).toLocaleString('es-PE');
                    }
                },
                {
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function (data) {
                        return '<button type="button" class="btn-detalle-aud text-blue-600 hover:text-blue-800 font-medium" data-id="' + escapeHtml(data) + '" title="Ver detalle"><i class="fas fa-eye"></i></button>';
                    }
                }
            ],
            columnDefs: [
                { orderable: false, targets: [0, 2, 3, 4, 5, 8] }
            ],
            language: window.DATATABLES_LANG_ES || undefined
        });
    }

    function limpiarFiltros() {
        var d = new Date();
        var anio = d.getFullYear();
        $('#periodoTipo').val('ENTRE_MESES');
        $('#fechaUnica').val(d.toISOString().slice(0, 10));
        $('#fechaInicio').val(anio + '-01-01');
        $('#fechaFin').val(anio + '-12-31');
        $('#mesUnico').val(anio + '-' + String(d.getMonth() + 1).padStart(2, '0'));
        $('#mesInicio').val(anio + '-01');
        $('#mesFin').val(anio + '-12');
        $('#mrt-aud-h-granja').val('');
        $('#mrt-aud-h-campania').val('');
        syncGranjaDisplay();
        $('#filtroGalpon').val('').prop('disabled', true);
        clavesGrupoSeleccionadas = [];
        $('.chk-grupo-filtro').prop('checked', false);
        actualizarEtiquetaGruposFiltro();
        aplicarVisibilidadPeriodo();
    }

    function cargarGalponesAud() {
        var granja = ($('#mrt-aud-h-granja').val() || '').trim();
        var $gal = $('#filtroGalpon');
        var prevGal = $gal.val() || '';
        $gal.empty().append('<option value="">Todos</option>');
        if (!granja) {
            $gal.prop('disabled', true);
            return;
        }
        $gal.prop('disabled', false);
        $.getJSON(cfg.listarUrl.replace('listar_auditorias.php', 'get_galpones_auditoria.php'), { granja: granja }, function (j) {
            if (j && j.galpones) {
                j.galpones.forEach(function (g) {
                    $gal.append('<option value="' + escapeHtml(g) + '">' + escapeHtml(g) + '</option>');
                });
                if (prevGal && $gal.find('option[value="' + prevGal.replace(/"/g, '\\"') + '"]').length) {
                    $gal.val(prevGal);
                }
            }
        }).fail(function () {
            $gal.prop('disabled', true);
        });
    }

    $(function () {
        aplicarVisibilidadPeriodo();
        actualizarEtiquetaGruposFiltro();
        initMrtAudGmc();
        cargarTabla();

        $('#btnToggleFiltrosAud').on('click', function () {
            $('#contenidoFiltrosAud').slideToggle(200);
            $('#iconoFiltrosAud').toggleClass('rotate-180');
        });

        $('#periodoTipo').on('change', aplicarVisibilidadPeriodo);

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

        $('#btnFiltrarAud').on('click', function () {
            if (tableAud) tableAud.ajax.reload(null, true);
            else cargarTabla(true);
        });
        $('#btnLimpiarAud').on('click', function () {
            limpiarFiltros();
            if (tableAud) tableAud.ajax.reload(null, true);
            else cargarTabla(true);
        });

        $('#tablaAuditoria').on('click', '.btn-detalle-aud', function () {
            const id = $(this).data('id');
            if (id) abrirDetalle(id);
        });

        $('#btnCerrarDetalleAud, #modalDetalleAud').on('click', function (e) {
            if (e.target === this) {
                $('#modalDetalleAud').addClass('hidden').removeClass('flex');
            }
        });

        // Reajustar columnas cuando cambie el ancho (sidebar toggle)
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(function () {
                if (tableAud) {
                    tableAud.columns.adjust();
                }
            });
            ro.observe(document.querySelector('.tabla-listado-wrapper') || document.body);
        }
    });
}());
