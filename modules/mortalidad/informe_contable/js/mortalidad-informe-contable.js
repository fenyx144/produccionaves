(function () {
    'use strict';

    const cfg = window.INF_CTB_CFG || {};
    let tableInf = null;
    let mrtInfGmc = null;

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

    function syncGranjaDisplay() {
        var g = ($('#mrt-inf-h-granja').val() || '').trim();
        var c = ($('#mrt-inf-h-campania').val() || '').trim();
        var inp = document.getElementById('mrt-inf-granja-resumen');
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

    function initMrtInfGmc() {
        if (!window.GmcGranjasCampanias) {
            console.warn('INF CTB: GmcGranjasCampanias no disponible (script no cargo?)');
            return;
        }
        if (mrtInfGmc) return;
        mrtInfGmc = window.GmcGranjasCampanias.create({
            prefix: 'mrt-inf',
            shellPanelId: 'mrt-inf-modal-granjas',
            mode: 'single',
            bodyOpenClass: 'mrt-inf-modal-granjas-open',
            openTriggerId: 'mrt-inf-granja-resumen',
            getCodigoSeis: function () {
                var g = ($('#mrt-inf-h-granja').val() || '').trim();
                var c = ($('#mrt-inf-h-campania').val() || '').trim();
                return g && c ? g + c : '';
            },
            setCodigoSeis: function (cod) {
                if (cod && cod.length >= 6) {
                    $('#mrt-inf-h-granja').val(cod.slice(0, 3));
                    $('#mrt-inf-h-campania').val(cod.slice(-3));
                } else {
                    $('#mrt-inf-h-granja').val('');
                    $('#mrt-inf-h-campania').val('');
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
                cargarGalponesInfCtb();
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
            granja: ($('#mrt-inf-h-granja').val() || '').trim(),
            campania: ($('#mrt-inf-h-campania').val() || '').trim(),
            galpon: ($('#filtroGalpon').val() || '').trim(),
            sexo: ($('#filtroSexo').val() || '').trim(),
            flujo: ($('#filtroFlujo').val() || '').trim(),
            tcategoria: ($('#filtroTcategoria').val() || '').trim(),
            nomMort: ($('#filtroCausa').val() || '').trim()
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

    function exportarPdf() {
        const f = leerFiltros();
        const qs = paramsFiltros(f).toString();
        const url = cfg.pdfUrl + (qs ? '?' + qs : '');
        window.open(url, '_blank', 'noopener');
    }

    function exportarXls() {
        const f = leerFiltros();
        const qs = paramsFiltros(f).toString();
        const url = cfg.xlsUrl + (qs ? '?' + qs : '');
        window.open(url, '_blank', 'noopener');
    }

    function getFlujoOptions(tcategoria) {
        var map = {
            'PlantaIncubacion': ['Planta Incubacion'],
            'Transporte': ['', 'Transporte', 'Despacho'],
            'Produccion': ['', 'Crianza', 'Laboratorio', 'Necropsia', 'Cuarentena'],
            'Despacho': ['', 'Despacho'],
            '': ['', 'Crianza', 'Transporte', 'Laboratorio', 'Necropsia', 'Cuarentena', 'Despacho', 'Planta Incubacion'],
            'Otros': ['', 'Despacho']
        };
        var opts = map[tcategoria || ''] || [''];
        return opts;
    }

    function actualizarFlujoOptions() {
        var tc = $('#filtroTcategoria').val() || '';
        var $flujo = $('#filtroFlujo');
        var prevVal = $flujo.val() || '';
        var opts = getFlujoOptions(tc);
        $flujo.empty();
        opts.forEach(function (v) {
            var label = v || 'Todos';
            $flujo.append('<option value="' + v + '">' + label + '</option>');
        });
        if (prevVal && $flujo.find('option[value="' + prevVal.replace(/"/g, '\\"') + '"]').length) {
            $flujo.val(prevVal);
        }
    }

    function aplicarVisibilidadPeriodo() {
        const t = $('#periodoTipo').val() || 'POR_MES';
        $('#periodoPorFecha, #periodoEntreFechas, #periodoPorMes, #periodoEntreMeses').addClass('hidden');
        if (t === 'POR_FECHA') $('#periodoPorFecha').removeClass('hidden');
        else if (t === 'ENTRE_FECHAS') $('#periodoEntreFechas').removeClass('hidden');
        else if (t === 'POR_MES') $('#periodoPorMes').removeClass('hidden');
        else if (t === 'ENTRE_MESES') $('#periodoEntreMeses').removeClass('hidden');
    }

    function cargarTabla(resetPaging) {
        const filtros = leerFiltros();

        // Si ya existe: solo recargar (paginación / buscar) sin destruir la tabla
        if (tableInf && $.fn.DataTable.isDataTable('#tablaInforme')) {
            tableInf.ajax.reload(null, resetPaging !== false);
            return;
        }

        tableInf = $('#tablaInforme').DataTable({
            processing: true,
            serverSide: true,
            autoWidth: false,
            order: [],
            deferRender: true,
            searching: true,
            searchDelay: 400,
            dom: '<"dt-top-row"<"flex items-center gap-6" l><"flex items-center gap-2" f>>rt<"dt-bottom-row"<"text-sm text-gray-600" i><"text-sm text-gray-600" p>>',
            ajax: {
                url: cfg.listarUrl,
                type: 'POST',
                data: function (d) {
                    const f = leerFiltros();
                    Object.keys(f).forEach(function (k) {
                        if (f[k] !== '' && f[k] != null) {
                            d[k] = f[k];
                        }
                    });
                    return d;
                },
                dataSrc: function (json) {
                    return json.data || [];
                },
                error: function (xhr) {
                    console.error('Error informe contable', xhr.status, xhr.responseText);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'error', title: 'Error al cargar', text: 'No se pudo obtener el informe contable.' });
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
                { data: 'usuario', defaultContent: '' },
                {
                    data: 'fecha',
                    defaultContent: '',
                    render: function (data, type) {
                        return type === 'display' ? formatearFechaDMY(data) : data;
                    }
                },
                {
                    data: 'hora',
                    defaultContent: '',
                    render: function (data, type) {
                        return type === 'display' ? formatearHora(data) : data;
                    }
                },
                {
                    data: 'fechaCont',
                    defaultContent: '',
                    render: function (data, type) {
                        return type === 'display' ? formatearFechaDMY(data) : data;
                    }
                },
                { data: 'cencos', defaultContent: '' },
                { data: 'nomCencos', defaultContent: '' },
                { data: 'galpon', defaultContent: '' },
                { data: 'edad', defaultContent: '' },
                { data: 'codigo', defaultContent: '' },
                { data: 'nomProd', defaultContent: '' },
                { data: 'codTra', defaultContent: '' },
                { data: 'transaccion', defaultContent: '' },
                { data: 'sexo', defaultContent: '', className: 'text-center' },
                { data: 'tcategoria', defaultContent: '' },
                { data: 'flujo', defaultContent: '' },
                { data: 'numFac', defaultContent: '' },
                { data: 'idmovi', defaultContent: '' },
                {
                    data: 'cantidad',
                    className: 'text-right',
                    render: function (data) {
                        return Number(data || 0).toLocaleString('es-PE', { maximumFractionDigits: 2 });
                    }
                },
                { data: 'codMort', defaultContent: '' },
                { data: 'nomMort', defaultContent: '' },
                {
                    data: 'cantidadMort',
                    className: 'text-right',
                    render: function (data) {
                        return data ? Number(data).toLocaleString('es-PE', { maximumFractionDigits: 2 }) : '';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function (data, type, row) {
                        var urls = row.evidenciaUrls || [];
                        var color = urls.length ? 'text-blue-600' : 'text-gray-300';
                        return '<button type="button" class="btn-detalle-inf-ctb ' + color + ' hover:text-blue-800 inline-flex items-center justify-center" title="Ver fotos"><i class="fas fa-eye"></i></button>';
                    }
                }
            ],
            columnDefs: [{ orderable: false, targets: '_all' }],
            language: window.DATATABLES_LANG_ES || {},
            pageLength: 25,
            lengthMenu: [[25, 50, 100], [25, 50, 100]]
        });
    }

    function limpiarFiltros() {
        const d = new Date();
        const anio = d.getFullYear();
        $('#periodoTipo').val('POR_MES');
        $('#fechaUnica').val(d.toISOString().slice(0, 10));
        $('#fechaInicio').val('');
        $('#fechaFin').val('');
        $('#mesUnico').val(anio + '-' + String(d.getMonth() + 1).padStart(2, '0'));
        $('#mesInicio').val('');
        $('#mesFin').val('');
        $('#mrt-inf-h-granja').val('');
        $('#mrt-inf-h-campania').val('');
        syncGranjaDisplay();
        $('#filtroGalpon').val('').prop('disabled', true);
        $('#filtroSexo').val('');
        $('#filtroTcategoria').val('');
        actualizarFlujoOptions();
        $('#filtroCausa').val('');
        aplicarVisibilidadPeriodo();
        cargarTabla();
    }

    function cargarGalponesInfCtb() {
        var granja = ($('#mrt-inf-h-granja').val() || '').trim();
        var $gal = $('#filtroGalpon');
        var prevGal = $gal.val() || '';
        $gal.empty().append('<option value="">Todos</option>');
        if (!granja) {
            $gal.prop('disabled', true);
            return;
        }
        $gal.prop('disabled', false);
        $.getJSON(cfg.listarUrl.replace('listar_informe_contable.php', 'get_galpones_informe_contable.php'), { granja: granja }, function (j) {
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

    $(document).ready(function () {
        $('#btnToggleFiltros').on('click', function () {
            $('#contenidoFiltros').toggleClass('hidden');
            $('#iconoFiltros').toggleClass('rotate-180');
        });

        $('#periodoTipo').on('change', aplicarVisibilidadPeriodo);

        $('#filtroTcategoria').on('change', function () {
            actualizarFlujoOptions();
        });

        $('#btnFiltrar').on('click', function () { cargarTabla(true); });
        $('#btnLimpiar').on('click', limpiarFiltros);
        $('#btnExportarPdf').on('click', exportarPdf);
        $('#btnExportarXls').on('click', exportarXls);

        // Delegated click para boton detalle fotos
        $(document).on('click', '.btn-detalle-inf-ctb', function () {
            var $row = $(this).closest('tr');
            var data = tableInf ? tableInf.row($row).data() : null;
            if (!data) return;
            var urls = data.evidenciaUrls || [];
            if (!urls.length) {
                $('#bodyFotosInfCtb').html('<p class="text-gray-500">Sin fotos registradas.</p>');
                $('#modalFotosInfCtb').removeClass('hidden').addClass('flex');
                return;
            }
            var total = urls.length;
            var errores = 0;
            var html = '';
            urls.forEach(function (url, idx) {
                html += '<div class="relative">';
                html += '<img src="' + escapeHtml(url) + '" alt="Foto ' + (idx + 1) + '" class="w-full max-w-xs rounded-lg shadow-md mb-3" style="object-fit:cover;">';
                html += '</div>';
            });
            $('#bodyFotosInfCtb').html('<div class="mort-evidencia-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:1rem;">' + html + '</div>');
            // Monitorear carga de imagenes; ocultar las que fallen
            $('#bodyFotosInfCtb img').on('error', function () {
                errores++;
                $(this).closest('div').hide();
            });
            // Mostrar mensaje si todas fallaron
            setTimeout(function () {
                if (errores >= total) {
                    $('#bodyFotosInfCtb').html('<p class="text-gray-500">Sin fotos registradas.</p>');
                }
            }, 3000);
            $('#modalFotosInfCtb').removeClass('hidden').addClass('flex');
        });

        $('#btnCerrarFotosInfCtb').on('click', function () {
            $('#modalFotosInfCtb').addClass('hidden').removeClass('flex');
        });
        $('#modalFotosInfCtb').on('click', function (e) {
            if (e.target === this) {
                $('#modalFotosInfCtb').addClass('hidden').removeClass('flex');
            }
        });

        initMrtInfGmc();
        actualizarFlujoOptions();
        aplicarVisibilidadPeriodo();
        cargarTabla();

        // Reajustar columnas cuando cambie el ancho (sidebar toggle)
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(function () {
                if (tableInf) {
                    tableInf.columns.adjust();
                }
            });
            ro.observe(document.querySelector('.tabla-listado-wrapper') || document.body);
        }
    });
}());
