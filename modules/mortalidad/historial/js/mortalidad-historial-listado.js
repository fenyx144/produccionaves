(function () {
    'use strict';

    const cfg = window.MORT_HIST_CFG || {};
    let tableHist = null;

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
        // Tarjetas de resumen removidas de la vista principal.
    }

    function leerFiltros() {
        return {
            periodoTipo: ($('#periodoTipo').val() || 'ENTRE_MESES').trim(),
            fechaUnica: ($('#fechaUnica').val() || '').trim(),
            fechaInicio: ($('#fechaInicio').val() || '').trim(),
            fechaFin: ($('#fechaFin').val() || '').trim(),
            mesUnico: ($('#mesUnico').val() || '').trim(),
            mesInicio: ($('#mesInicio').val() || '').trim(),
            mesFin: ($('#mesFin').val() || '').trim(),
            accion: ($('#filtroAccion').val() || '').trim()
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

    function renderBadgeOperacion(row) {
        const label = escapeHtml(row.accionLabel || row.accion || '');
        const icon = escapeHtml(row.accionIcon || 'fas fa-info-circle');
        const badge = escapeHtml(row.accionBadge || 'bg-gray-50 text-gray-700 border-gray-200');
        return '<span class="hist-badge ' + badge + '"><i class="' + icon + '"></i> ' + label + '</span>';
    }

    function documentoListado(cab) {
        if (!cab || typeof cab !== 'object') return '';
        const numFac = cab.numFac ? String(cab.numFac).trim() : '';
        if (numFac) return numFac;
        if (cab.documento) return String(cab.documento).trim();
        return [cab.serie, cab.numero].filter(Boolean).join('-');
    }

    function renderCabResumen(cab) {
        if (!cab || typeof cab !== 'object') {
            return '<p class="text-gray-400">Sin datos de cabecera</p>';
        }
        const tipoLabel = cab.tipoLabelCompleto
            || cab.tipoLabel
            || cab.tipoMortalidad
            || '';
        const documento = documentoListado(cab);
        let html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">';
        const campos = [
            ['Tipo', tipoLabel],
            ['Granja', (cab.granjaNombre || cab.granja || '')],
            ['Campaña', cab.campania],
            ['Galpón', cab.galpon],
            ['Fecha registro', formatearFechaDMY(cab.fechaRegistro)],
            ['Documento', documento],
            ['Usuario registro', cab.usuarioRegistro],
            ['Observaciones', cab.observaciones]
        ];
        campos.forEach(function (c) {
            if (c[1] == null || String(c[1]).trim() === '') return;
            html += '<p><strong>' + escapeHtml(c[0]) + ':</strong> ' + escapeHtml(c[1]) + '</p>';
        });
        html += '</div>';
        return html;
    }

    function renderDetalles(det) {
        if (!det || !det.length) {
            return '<p class="text-gray-400 mt-2">Sin líneas de detalle</p>';
        }
        let html = '<div class="mt-3 overflow-x-auto"><table class="w-full border-collapse text-xs">';
        html += '<thead><tr class="text-gray-500">';
        html += '<th class="text-left px-1 py-0.5 border-b">#</th>';
        html += '<th class="text-left px-1 py-0.5 border-b">Sexo</th>';
        html += '<th class="text-right px-1 py-0.5 border-b">Cantidad</th>';
        html += '<th class="text-left px-1 py-0.5 border-b">Causa</th>';
        html += '<th class="text-left px-1 py-0.5 border-b">Obs.</th>';
        html += '</tr></thead><tbody>';
        det.forEach(function (d, i) {
            const causa = ((d.codMortalidad || '') + ' ' + (d.nomMortalidad || '')).trim();
            html += '<tr class="' + (i % 2 === 0 ? '' : 'bg-gray-50') + '">';
            html += '<td class="px-1 py-0.5 border-b text-gray-400">' + (i + 1) + '</td>';
            html += '<td class="px-1 py-0.5 border-b">' + escapeHtml(d.sexo || '') + '</td>';
            html += '<td class="px-1 py-0.5 border-b text-right">' + Number(d.cantidad || 0).toLocaleString('es-PE') + '</td>';
            html += '<td class="px-1 py-0.5 border-b">' + escapeHtml(causa) + '</td>';
            html += '<td class="px-1 py-0.5 border-b">' + escapeHtml(d.observacion || '') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    function renderSnapBlock(titulo, snap) {
        if (!snap || typeof snap !== 'object') {
            return '<div class="mt-4"><h6 class="font-semibold text-gray-800 mb-2">' + escapeHtml(titulo) + '</h6>' +
                '<p class="text-gray-400">Sin snapshot</p></div>';
        }
        const cab = snap.san_fact_mortalidad_cab || snap.cabecera || null;
        const det = snap.san_fact_mortalidad_det || snap.detalle || snap.detalles || [];
        let html = '<div class="mt-4"><h6 class="font-semibold text-gray-800 mb-2">' + escapeHtml(titulo) + '</h6>';
        html += '<div class="bg-gray-50 rounded-xl p-4 border border-gray-100">';
        html += renderCabResumen(cab);
        html += renderDetalles(det);
        html += '</div></div>';
        return html;
    }

    function construirHtmlDetalle(reg) {
        const r = reg || {};
        let html = '<div class="space-y-4">';
        html += '<div class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-gray-50 rounded-xl p-4">';
        html += '<p><strong>Fecha:</strong> ' + escapeHtml(formatearFechaDMY(r.fechaHora)) + '</p>';
        html += '<p><strong>Hora:</strong> ' + escapeHtml(formatearHora(r.fechaHora)) + '</p>';
        html += '<p><strong>Usuario:</strong> ' + escapeHtml(r.nom_usuario || r.cod_usuario || '') + '</p>';
        html += '<p><strong>Código:</strong> ' + escapeHtml(r.cod_usuario || '') + '</p>';
        html += '<p><strong>Operación:</strong> ' + renderBadgeOperacion(r) + '</p>';
        html += '</div>';

        if (r.descripcion) {
            html += '<div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 rounded">';
            html += '<p class="text-gray-700"><strong>Descripción:</strong> ' + escapeHtml(r.descripcion) + '</p>';
            html += '</div>';
        }

        if (r.datos_previos) {
            html += renderSnapBlock('Datos previos', r.datos_previos);
        }
        if (r.datos_nuevos) {
            html += renderSnapBlock('Datos nuevos', r.datos_nuevos);
        }
        if (!r.datos_previos && !r.datos_nuevos) {
            html += '<p class="text-gray-400">No hay snapshots asociados a esta acción.</p>';
        }

        html += '</div>';
        return html;
    }

    function abrirDetalle(id) {
        const $modal = $('#modalDetalleHist');
        $('#modalDetalleHistTitulo').text('Detalle de historial');
        $('#modalDetalleHistBody').html('<div class="text-gray-500">Cargando...</div>');
        $modal.removeClass('hidden').addClass('flex');

        fetch(cfg.detalleUrl + '?id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.success === false) {
                    $('#modalDetalleHistBody').html('<p class="text-red-600">' + escapeHtml(j.message || 'Error al cargar') + '</p>');
                    return;
                }
                var reg = j.registro || {};
                $('#modalDetalleHistTitulo').text(
                    (reg.accionLabel || 'Acción') + ' — ' + (reg.nom_usuario || reg.cod_usuario || '')
                );
                $('#modalDetalleHistBody').html(construirHtmlDetalle(reg));
            })
            .catch(function () {
                $('#modalDetalleHistBody').html('<p class="text-red-600">Error de conexión.</p>');
            });
    }

    function eliminarRegistroHistorial(id) {
        if (!cfg.puedeEliminarHistorial) return;
        fetch(cfg.eliminarUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: 'Eliminado', text: (j.message || 'Registro de historial eliminado.'), timer: 1800, showConfirmButton: false });
                    }
                    if (tableHist) tableHist.ajax.reload(null, false);
                } else {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error', text: (j && j.message) ? j.message : 'Error al eliminar el registro.' });
                    }
                }
            })
            .catch(function () {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión.' });
                }
            });
    }

    function cargarTabla(resetPaging) {
        if (tableHist && $.fn.DataTable.isDataTable('#tablaHistorial')) {
            tableHist.ajax.reload(null, resetPaging !== false);
            return;
        }

        tableHist = $('#tablaHistorial').DataTable({
            processing: true,
            serverSide: true,
            autoWidth: false,
            deferRender: true,
            searching: true,
            searchDelay: 400,
            order: [[1, 'desc'], [2, 'desc']],
            language: window.DATATABLES_LANG_ES || {},
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
                    console.error('Error listado historial mortalidad', xhr.status, xhr.responseText);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error al cargar', text: 'No se pudo obtener el historial.' });
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
                    data: 'fechaHora',
                    render: function (data, type) {
                        return type === 'display' ? formatearFechaDMY(data) : data;
                    }
                },
                {
                    data: 'fechaHora',
                    orderable: false,
                    render: function (data, type) {
                        return type === 'display' ? formatearHora(data) : data;
                    }
                },
                {
                    data: 'nom_usuario',
                    render: function (data, type, row) {
                        const nom = data || row.cod_usuario || '';
                        return escapeHtml(nom);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function (data, type, row) {
                        return renderBadgeOperacion(row);
                    }
                },
                {
                    data: 'granja',
                    orderable: false,
                    defaultContent: '—',
                    render: function (data) {
                        return data ? escapeHtml(data) : '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'campania',
                    orderable: false,
                    defaultContent: '—',
                    render: function (data) {
                        return data ? escapeHtml(data) : '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'galpon',
                    orderable: false,
                    defaultContent: '—',
                    render: function (data) {
                        return data ? escapeHtml(data) : '<span class="text-gray-400">—</span>';
                    }
                },
                {
                    data: 'totalAves',
                    orderable: false,
                    className: 'text-right font-semibold',
                    render: function (data) {
                        return Number(data || 0).toLocaleString('es-PE');
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function (data, type, row) {
                        const id = escapeHtml(row.id || '');
                        return '<button type="button" class="btn-detalle-hist text-blue-600 hover:text-blue-800 font-medium" data-id="' + id + '" title="Ver detalle"><i class="fas fa-eye"></i></button>';
                    }
                },
                ...(cfg.puedeEliminarHistorial ? [
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'text-center',
                        render: function (data, type, row) {
                            if (type !== 'display') return '';
                            const id = escapeHtml(row.id || '');
                            return '<button type="button" class="btn-eliminar-hist text-red-600 hover:text-red-800 font-medium" data-id="' + id + '" title="Eliminar registro de historial"><i class="fas fa-trash"></i></button>';
                        }
                    }
                ] : [])
            ],
            columnDefs: [{ orderable: false, targets: [0, 4, 5, 6, 7, 8, 9] }]
        });
    }

    $(function () {
        aplicarVisibilidadPeriodo();
        cargarTabla(true);

        $('#btnToggleFiltrosHist').on('click', function () {
            $('#contenidoFiltrosHist').toggleClass('hidden');
            $('#iconoFiltrosHist').toggleClass('rotate-180');
        });

        $('#periodoTipo').on('change', aplicarVisibilidadPeriodo);

        $('#btnFiltrarHist').on('click', function () {
            cargarTabla(true);
        });

        $('#btnLimpiarHist').on('click', function () {
            const anio = new Date().getFullYear();
            $('#periodoTipo').val('ENTRE_MESES');
            $('#fechaUnica').val(new Date().toISOString().slice(0, 10));
            $('#fechaInicio').val(anio + '-01-01');
            $('#fechaFin').val(anio + '-12-31');
            $('#mesUnico').val(anio + '-' + String(new Date().getMonth() + 1).padStart(2, '0'));
            $('#mesInicio').val(anio + '-01');
            $('#mesFin').val(anio + '-12');
            $('#filtroAccion').val('');
            aplicarVisibilidadPeriodo();
            if (tableHist) {
                tableHist.search('');
            }
            cargarTabla(true);
        });

        $('#tablaHistorial').on('click', '.btn-detalle-hist', function () {
            const id = $(this).data('id');
            if (id) abrirDetalle(id);
        });

        $('#tablaHistorial').on('click', '.btn-eliminar-hist', function () {
            const id = $(this).data('id');
            if (!id) return;
            if (typeof Swal === 'undefined') {
                eliminarRegistroHistorial(id);
                return;
            }
            Swal.fire({
                title: 'Confirmar eliminación',
                text: 'Se eliminará este registro del historial de mortalidad. Esta acción no se puede deshacer.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then(function (result) {
                if (result.isConfirmed) eliminarRegistroHistorial(id);
            });
        });

        $('#btnCerrarDetalleHist').on('click', function () {
            $('#modalDetalleHist').addClass('hidden').removeClass('flex');
        });

        $('#modalDetalleHist').on('click', function (e) {
            if (e.target === this) {
                $(this).addClass('hidden').removeClass('flex');
            }
        });
    });
})();
