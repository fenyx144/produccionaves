/**
 * Modal reutilizable Granjas y campañas (modos multi y single).
 */
(function (global) {
    'use strict';

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function normGranja3(c) {
        var s = String(c == null ? '' : c).trim();
        if (!s) {
            return '';
        }
        while (s.length < 3) {
            s = '0' + s;
        }
        return s.slice(0, 3);
    }

    function padCampania(c) {
        var d = String(c || '').replace(/\s+/g, '');
        if (d && /^\d+$/.test(d)) {
            return d.length >= 3 ? d.slice(-3) : d.padStart(3, '0');
        }
        return String(c || '').trim();
    }

    function padCorrelativo(c) {
        var d = padCampania(c).replace(/\D/g, '');
        while (d.length < 4) {
            d = '0' + d;
        }
        return d.slice(-4);
    }

    function codigoCenco6(granja, campania) {
        return normGranja3(granja) + padCampania(campania);
    }

    /** Formato correlativo: 621-0001 */
    function formatCodigoCorrelativo(granja, campania) {
        var g = normGranja3(granja);
        if (!g) {
            return '';
        }
        return g + '-' + padCorrelativo(campania);
    }

    /** Normaliza 621-0001 o 621001 a 621001. */
    function normalizarCodigoCenco(cod) {
        var s = String(cod == null ? '' : cod).trim();
        if (!s) {
            return '';
        }
        var m = s.match(/^(\d{3})-(\d{3,4})$/);
        if (m) {
            return m[1] + ('000' + m[2]).slice(-3);
        }
        var digits = s.replace(/\D/g, '');
        if (digits.length >= 6) {
            return digits.slice(0, 3) + digits.slice(-3);
        }
        return s;
    }

    function codigoCencoEquivalent(a, b) {
        return normalizarCodigoCenco(a) === normalizarCodigoCenco(b);
    }

    function codigoCencoValido(cod) {
        return normalizarCodigoCenco(cod).length === 6;
    }

    function granjaFromCodigo(cod) {
        var n = normalizarCodigoCenco(cod);
        return n.length >= 6 ? n.slice(0, 3) : '';
    }

    function campaniaFromCodigo(cod) {
        var n = normalizarCodigoCenco(cod);
        return n.length >= 6 ? n.slice(-3) : '';
    }

    function ordenarGranjas(lista) {
        return (lista || []).slice().sort(function (a, b) {
            var ag = String((a && a.granja) || '').trim();
            var bg = String((b && b.granja) || '').trim();
            var c0 = ag.localeCompare(bg, 'es', { sensitivity: 'base', numeric: true });
            if (c0 !== 0) {
                return c0;
            }
            return String((a && a.nombre) || '').trim().localeCompare(String((b && b.nombre) || '').trim(), 'es', { sensitivity: 'base', numeric: true });
        });
    }

    function agruparPorZonaSubzona(lista) {
        var map = {};
        (lista || []).forEach(function (g) {
            var z = (g.zona && String(g.zona).trim()) ? String(g.zona).trim() : '(Sin zona)';
            var sz = (g.subzona && String(g.subzona).trim()) ? String(g.subzona).trim() : '(Sin subzona)';
            if (!map[z]) {
                map[z] = {};
            }
            if (!map[z][sz]) {
                map[z][sz] = [];
            }
            map[z][sz].push(g);
        });
        return map;
    }

    function el(id) {
        return document.getElementById(id);
    }

    function create(cfg) {
        cfg = cfg || {};
        var prefix = String(cfg.prefix || 'hc').trim();
        var mode = cfg.mode === 'multi' ? 'multi' : 'single';
        var shellId = cfg.shellPanelId || (prefix + '-modal');
        var ids = {
            backdrop: prefix + '-backdrop',
            panel: shellId,
            close: prefix + '-modal-close',
            buscar: prefix + '-buscar',
            chkTodas: prefix + '-chk-todas',
            lista: prefix + '-lista',
            btnCancel: prefix + '-btn-cancel',
            btnApply: prefix + '-btn-apply'
        };

        var metaLista = [];
        var campByGranjaExt = {};
        var campByGranjaSingle = {};
        var cencosAviso = '';
        var galponesData = cfg.galponesData || {};
        var treeOpenZ = new Set();
        var treeOpenS = new Set();
        var treeWired = false;
        var justOpened = false;
        var snapMulti = { selCodes: [], campByGranja: {}, galponByGranja: {} };
        var snapSingle = '';
        var multiState = cfg.getMultiState ? null : { selCodes: [], campByGranja: {}, galponByGranja: {} };
        var _opening = false;
        /** Si llega reload mientras open() carga, reejecutar al terminar. */
        var _reloadQueued = false;
        /** Al abrir el modal: UI limpia (galpones todos, campañas ninguna), no restaurar selección aplicada. */
        var _freshUiOnRender = false;

        function getMultiState() {
            if (typeof cfg.getMultiState === 'function') {
                return cfg.getMultiState();
            }
            return multiState;
        }

        function mountToBody() {
            var bd = el(ids.backdrop);
            var pan = el(ids.panel);
            if (bd && bd.parentNode !== document.body) {
                document.body.appendChild(bd);
            }
            if (pan && pan.parentNode !== document.body) {
                document.body.appendChild(pan);
            }
        }

        function isOpen() {
            var pan = el(ids.panel);
            return pan && !pan.classList.contains('lay-hidden');
        }

        function syncAviso() {
            var avId = cfg.avisoId || (prefix + '-modal-granjas-aviso');
            var av = el(avId);
            if (!av) {
                return;
            }
            if (cencosAviso) {
                av.textContent = cencosAviso;
                av.classList.remove('hidden');
            } else {
                av.textContent = '';
                av.classList.add('hidden');
            }
        }

        function galponesParaGranja(cod) {
            var g3 = normGranja3(cod);
            return (galponesData && (galponesData[cod] || galponesData[g3])) || [];
        }

        /** Galpones en layout fila (necropsias): cuerpo sin etiqueta, inline Todos + lista. */
        function renderGalponRowBodyHtml(cod, galponList) {
            if (!galponList || !galponList.length) {
                return '';
            }
            var collapsible = cfg.galponesCollapsible === true;
            var codSlug = cod.replace(/[^a-z0-9_-]/gi, '_');
            var _galpChkAllId = prefix + '-galp-all-' + codSlug;
            var html = '<div class="hc-galpon-body hc-galpon-col hc-galpon-col--row';
            html += collapsible ? ' hc-galpon-col--collapsible">' : '">';
            html += '<div class="hc-galpon-content">';
            html += '<label class="hc-galpon-chkall-line cursor-pointer" for="' + _galpChkAllId + '">';
            html += '<input type="checkbox" class="hc-chk-galpon-all w-4 h-4" id="' + _galpChkAllId + '" data-granja="' + escHtml(cod) + '" checked>';
            html += '<span>Todos</span></label>';
            if (collapsible) {
                html += '<button type="button" class="hc-exp-galp hc-exp-galp-btn text-slate-500 p-0 border-0 bg-transparent cursor-pointer inline-flex items-center justify-center flex-shrink-0" aria-expanded="false" aria-label="Expandir galpones" data-granja="' + escHtml(cod) + '">';
                html += '<i class="fas fa-chevron-right hc-chev"></i></button>';
            }
            html += '<div class="hc-galpon-wrap" role="group" aria-label="Galpones ' + escHtml(cod) + '"' + (collapsible ? ' style="display:none"' : '') + '>';
            galponList.forEach(function (gp) {
                var gpid = prefix + '-galp-' + codSlug + '-' + String(gp).replace(/[^a-z0-9_-]/gi, '_');
                html += '<label class="hc-galpon-line cursor-pointer" for="' + gpid + '">';
                html += '<input type="checkbox" class="hc-chk-galpon w-4 h-4" id="' + gpid + '" data-granja="' + escHtml(cod) + '" value="' + escHtml(gp) + '" checked>';
                html += '<span>' + escHtml(gp) + '</span></label>';
            });
            html += '</div></div></div>';
            return html;
        }

        /** HTML columna galpones (multi/single). Con galponesCollapsible: cabecera Galpones + Todos + ›; lista oculta. */
        function renderGalponColHtml(cod, galponList) {
            if (!galponList || !galponList.length) {
                return '';
            }
            if (cfg.multiLayout === 'row') {
                return renderGalponRowBodyHtml(cod, galponList);
            }
            var collapsible = cfg.galponesCollapsible === true;
            var rowLayout = cfg.multiLayout === 'row';
            var stackLayout = cfg.multiLayout === 'stack';
            var codSlug = cod.replace(/[^a-z0-9_-]/gi, '_');
            var _galpChkAllId = prefix + '-galp-all-' + codSlug;
            var html = '<div class="hc-galpon-col' + (collapsible ? ' hc-galpon-col--collapsible' : '') + (rowLayout ? ' hc-galpon-col--row' : (stackLayout ? ' hc-galpon-col--stack' : '')) + '">';
            if (collapsible) {
                html += '<div class="hc-galpon-head hc-galpon-head--collapsed">';
                html += '<span class="hc-galpon-label">Galpones</span>';
                html += '<div class="hc-galpon-collapsed-actions">';
                html += '<label class="hc-galpon-chkall-line cursor-pointer" for="' + _galpChkAllId + '">';
                html += '<input type="checkbox" class="hc-chk-galpon-all w-4 h-4" id="' + _galpChkAllId + '" data-granja="' + escHtml(cod) + '" checked>';
                html += '<span>Todos</span></label>';
                html += '<button type="button" class="hc-exp-galp hc-exp-galp-btn text-slate-500 p-0 border-0 bg-transparent cursor-pointer inline-flex items-center justify-center flex-shrink-0" aria-expanded="false" aria-label="Expandir galpones" data-granja="' + escHtml(cod) + '">';
                html += '<i class="fas fa-chevron-right hc-chev"></i></button>';
                html += '</div></div>';
            } else {
                html += '<div class="hc-galpon-head">';
                html += '<span class="hc-galpon-label">Galpones</span>';
                html += '<label class="hc-galpon-chkall-line cursor-pointer" for="' + _galpChkAllId + '">';
                html += '<input type="checkbox" class="hc-chk-galpon-all w-4 h-4" id="' + _galpChkAllId + '" data-granja="' + escHtml(cod) + '" checked>';
                html += '<span>Todos</span></label>';
                html += '</div>';
            }
            html += '<div class="hc-galpon-wrap" role="group" aria-label="Galpones ' + escHtml(cod) + '"' + (collapsible ? ' style="display:none"' : '') + '>';
            galponList.forEach(function (gp) {
                var gpid = prefix + '-galp-' + codSlug + '-' + String(gp).replace(/[^a-z0-9_-]/gi, '_');
                html += '<label class="hc-galpon-line cursor-pointer" for="' + gpid + '">';
                html += '<input type="checkbox" class="hc-chk-galpon w-4 h-4" id="' + gpid + '" data-granja="' + escHtml(cod) + '" value="' + escHtml(gp) + '" checked>';
                html += '<span>' + escHtml(gp) + '</span></label>';
            });
            html += '</div></div>';
            return html;
        }

        function campsParaGranja(cod, byCamp) {
            var g3 = normGranja3(cod);
            var codTrim = String(cod || '').trim();
            if (mode === 'single') {
                return (campByGranjaSingle[g3] || campByGranjaSingle[codTrim] || []).slice();
            }
            byCamp = byCamp || campByGranjaExt;
            return (byCamp[g3] || byCamp[codTrim] || []).slice();
        }

        function getSelCodigo6() {
            if (typeof cfg.getCodigoSeis === 'function') {
                return String(cfg.getCodigoSeis() || '').trim();
            }
            return '';
        }

        function syncCampFromStateMulti(byCamp) {
            var st = getMultiState();
            var pick = {};
            Object.keys(st.campByGranja || {}).forEach(function (g) {
                (st.campByGranja[g] || []).forEach(function (c) {
                    pick[g + '|' + c] = true;
                });
            });
            var box = el(ids.lista);
            if (!box) {
                return;
            }
            /* Restaurar campanias */
            box.querySelectorAll('.fila-granja').forEach(function (row) {
                var chG = row.querySelector('.hc-chk-g');
                var g = String((chG && chG.getAttribute('data-granja')) || row.getAttribute('data-granja') || '').trim();
                row.querySelectorAll('.hc-chk-camp').forEach(function (cc) {
                    var v = String(cc.value || '').trim();
                    cc.checked = !!pick[g + '|' + v];
                });
                syncGranjaRowFromCamps(row);
            });
            /* Restaurar galpones */
            var stGalp = st.galponByGranja || {};
            box.querySelectorAll('.fila-granja').forEach(function (row) {
                var chG2 = row.querySelector('.hc-chk-g');
                var g2 = String((chG2 && chG2.getAttribute('data-granja')) || row.getAttribute('data-granja') || '').trim();
                var galpList = stGalp[g2];
                row.querySelectorAll('.hc-chk-galpon').forEach(function (gc) {
                    if (galpList && galpList.length) {
                        gc.checked = galpList.indexOf(String(gc.value || '').trim()) >= 0;
                    } else {
                        gc.checked = true; /* por defecto todos marcados */
                    }
                });
                var allGalp = row.querySelector('.hc-chk-galpon-all');
                if (allGalp) {
                    var gals = row.querySelectorAll('.hc-chk-galpon');
                    var nTot = 0;
                    var nOn = 0;
                    gals.forEach(function (gc) { nTot++; if (gc.checked) { nOn++; } });
                    allGalp.checked = nTot > 0 && nOn === nTot;
                }
            });
            box.querySelectorAll('.tree-subzona').forEach(function (s) { syncSubMulti(s); });
            box.querySelectorAll('.tree-zone').forEach(function (z) { syncZonaMulti(z); });
            syncChkTodasMulti();
        }

        /** Estado inicial del modal multi: ninguna campaña, todos los galpones. */
        function resetSelectionUiMulti() {
            var box = el(ids.lista);
            if (!box) {
                return;
            }
            box.querySelectorAll('.hc-chk-camp').forEach(function (cc) {
                cc.checked = false;
            });
            box.querySelectorAll('.hc-chk-galpon').forEach(function (gc) {
                gc.checked = true;
            });
            box.querySelectorAll('.hc-chk-galpon-all').forEach(function (gc) {
                gc.checked = true;
            });
            box.querySelectorAll('.fila-granja').forEach(function (row) {
                syncGranjaRowFromCamps(row);
            });
            box.querySelectorAll('.tree-subzona').forEach(function (s) { syncSubMulti(s); });
            box.querySelectorAll('.tree-zone').forEach(function (z) { syncZonaMulti(z); });
            syncChkTodasMulti();
        }

        function syncCampFromStateSingle() {
            var sel = getSelCodigo6();
            var box = el(ids.lista);
            if (!box) {
                return;
            }
            box.querySelectorAll('.fila-granja').forEach(function (row) {
                row.querySelectorAll('.hc-chk-camp').forEach(function (cc) {
                    cc.checked = sel !== '' && codigoCencoEquivalent(cc.value, sel);
                });
            });
        }

        function syncGranjaRowFromCamps(row) {
            if (!row) {
                return;
            }
            var chG = row.querySelector('.hc-chk-g');
            if (!chG) {
                return;
            }
            var camps = row.querySelectorAll('.hc-chk-camp');
            var nTot = camps.length;
            var nOn = 0;
            camps.forEach(function (cc) { if (cc.checked) { nOn++; } });
            chG.indeterminate = false;
            if (nTot === 0) {
                chG.checked = false;
                return;
            }
            chG.checked = nOn === nTot;
        }

        function syncZonaMulti(zEl) {
            if (!zEl) {
                return;
            }
            var chk = zEl.querySelector('.hc-chk-z');
            if (!chk) {
                return;
            }
            var rows = zEl.querySelectorAll('.hc-chk-g');
            var n = 0;
            var c = 0;
            rows.forEach(function (r) { n++; if (r.checked) { c++; } });
            chk.indeterminate = false;
            chk.checked = n > 0 && c === n;
        }

        function syncSubMulti(subEl) {
            if (!subEl) {
                return;
            }
            var chk = subEl.querySelector('.hc-chk-sz');
            if (!chk) {
                return;
            }
            var rows = subEl.querySelectorAll('.hc-chk-g');
            var n = 0;
            var c = 0;
            rows.forEach(function (r) { n++; if (r.checked) { c++; } });
            chk.indeterminate = false;
            chk.checked = n > 0 && c === n;
            syncZonaMulti(subEl.closest('.tree-zone'));
        }

        function syncChkTodasMulti() {
            var all = el(ids.chkTodas);
            if (!all) {
                return;
            }
            var nodes = document.querySelectorAll('#' + ids.lista + ' .hc-chk-g');
            var n = 0;
            var c = 0;
            nodes.forEach(function (ch) { n++; if (ch.checked) { c++; } });
            all.indeterminate = false;
            all.checked = n > 0 && c === n;
        }

        function hasModalPeriodoCampanias() {
            return !!el(prefix + '-periodo-camp-toggle');
        }

        function periodoOkMulti() {
            if (hasModalPeriodoCampanias() && global.GmcPeriodoCampanias) {
                return global.GmcPeriodoCampanias.isValid(prefix);
            }
            if (cfg.periodo && typeof cfg.periodo.isValid === 'function') {
                return cfg.periodo.isValid();
            }
            return true;
        }

        function getPeriodoCampaniasParams() {
            if (hasModalPeriodoCampanias() && global.GmcPeriodoCampanias) {
                return global.GmcPeriodoCampanias.getParams(prefix);
            }
            if (cfg.periodo && typeof cfg.periodo.getParams === 'function') {
                return cfg.periodo.getParams();
            }
            return { periodoTipo: 'TODOS' };
        }

        function getPeriodoCampaniasFechaRef() {
            if (hasModalPeriodoCampanias() && global.GmcPeriodoCampanias) {
                return global.GmcPeriodoCampanias.getFechaReferencia(prefix);
            }
            return null;
        }

        function renderTree(byCamp) {
            var oldBox = el(ids.lista);
            if (!oldBox) {
                return;
            }
            // Reemplazar el contenedor para eliminar event listeners antiguos
            var parent = oldBox.parentNode;
            var box = document.createElement('div');
            box.id = ids.lista;
            box.className = oldBox.className;
            var oldRole = oldBox.getAttribute('role');
            if (oldRole) { box.setAttribute('role', oldRole); }
            var oldLabel = oldBox.getAttribute('aria-label');
            if (oldLabel) { box.setAttribute('aria-label', oldLabel); }
            parent.replaceChild(box, oldBox);

            treeWired = false;
            syncAviso();
            var lista = ordenarGranjas(metaLista);
            if (!lista.length) {
                var aviso = cencosAviso || (mode === 'single'
                    ? 'Sin granjas en catálogo. Pruebe otra fecha de referencia.'
                    : 'Sin granjas en catálogo.');
                box.innerHTML = '<p class="text-gray-500 text-xs py-4 text-center">' + escHtml(aviso) + '</p>';
                return;
            }
            var map = agruparPorZonaSubzona(lista);
            treeOpenZ = new Set(Object.keys(map));
            Object.keys(map).forEach(function (z) {
                Object.keys(map[z]).forEach(function (sz) {
                    treeOpenS.add(z + '|' + sz);
                });
            });
            var selCod6 = getSelCodigo6();
            var periodoOk = periodoOkMulti();
            var html = '';
            Object.keys(map).sort().forEach(function (z) {
                var expZ = treeOpenZ.has(z);
                html += '<div class="tree-zone" data-gmc-z="' + escHtml(z) + '">';
                html += '<div class="tree-zone-head">';
                if (mode === 'multi') {
                    html += '<button type="button" class="hc-exp-z text-slate-500 p-0 border-0 bg-transparent cursor-pointer" data-z="' + escHtml(z) + '" aria-expanded="' + (expZ ? 'true' : 'false') + '"><i class="fas fa-chevron-right hc-chev' + (expZ ? ' hc-chev-open' : '') + '"></i></button>';
                    html += '<input type="checkbox" class="hc-chk-z w-4 h-4"><span>' + escHtml(z) + '</span>';
                } else {
                    html += '<button type="button" class="hc-exp-z text-slate-500 p-0 border-0 bg-transparent cursor-pointer" aria-expanded="' + (expZ ? 'true' : 'false') + '"><i class="fas fa-chevron-right hc-chev' + (expZ ? ' hc-chev-open' : '') + '"></i></button><span>' + escHtml(z) + '</span>';
                }
                html += '</div>';
                html += '<div class="tree-zone-body"' + (expZ ? '' : ' style="display:none"') + '>';
                Object.keys(map[z]).sort().forEach(function (sz) {
                    var key = z + '|' + sz;
                    var expS = treeOpenS.has(key);
                    html += '<div class="tree-subzona" data-gmc-sz="' + escHtml(key) + '">';
                    html += '<div class="tree-subzona-head">';
                    if (mode === 'multi') {
                        html += '<button type="button" class="hc-exp-sz text-slate-500 p-0 border-0 bg-transparent cursor-pointer" data-k="' + escHtml(key) + '" aria-expanded="' + (expS ? 'true' : 'false') + '"><i class="fas fa-chevron-right hc-chev' + (expS ? ' hc-chev-open' : '') + '"></i></button>';
                        html += '<input type="checkbox" class="hc-chk-sz w-4 h-4"><span>' + escHtml(sz) + '</span>';
                    } else {
                        html += '<button type="button" class="hc-exp-sz text-slate-500 p-0 border-0 bg-transparent cursor-pointer" aria-expanded="' + (expS ? 'true' : 'false') + '"><i class="fas fa-chevron-right hc-chev' + (expS ? ' hc-chev-open' : '') + '"></i></button><span>' + escHtml(sz) + '</span>';
                    }
                    html += '</div>';
                    html += '<div class="tree-subzona-granjas"' + (expS ? '' : ' style="display:none"') + '>';
                    ordenarGranjas(map[z][sz]).forEach(function (g) {
                        var cod = String(g.granja || '').trim();
                        var nom = String(g.nombre || cod);
                        var camps = campsParaGranja(cod, byCamp).sort(function (a, b) {
                            return String(a.campania || '').localeCompare(String(b.campania || ''), undefined, { numeric: true, sensitivity: 'base' });
                        });
                        var rowLayout = cfg.multiLayout === 'row';
                        var stackLayout = cfg.multiLayout === 'stack';
                        html += '<div class="fila-granja" data-granja="' + escHtml(cod) + '">';
                        if (mode === 'multi') {
                            var _galpones = galponesParaGranja(cod);
                            var _gridCls;
                            if (rowLayout) {
                                _gridCls = _galpones.length
                                    ? 'gmc-fila-granja-grid--multi gmc-fila-granja-grid--multi-galp gmc-fila-granja-grid--multi-row'
                                    : 'gmc-fila-granja-grid--multi gmc-fila-granja-grid--multi-row';
                            } else if (stackLayout) {
                                _gridCls = 'gmc-fila-granja-grid--multi-stack';
                            } else {
                                _gridCls = _galpones.length
                                    ? 'gmc-fila-granja-grid--multi gmc-fila-granja-grid--multi-galp'
                                    : 'gmc-fila-granja-grid--multi';
                            }
                            html += '<div class="gmc-fila-granja-grid ' + _gridCls + '">';
                            if (stackLayout) {
                                html += '<div class="gmc-fila-subzona">' + escHtml(sz) + '</div>';
                            }
                            if (rowLayout) {
                                html += '<span class="hc-granja-label gmc-row-lbl">Granja</span>';
                                html += '<span class="hc-camp-label gmc-row-lbl">Campañas</span>';
                                if (_galpones.length) {
                                    html += '<span class="hc-galpon-label gmc-row-lbl">Galpones</span>';
                                }
                                html += '<div class="hc-granja-body"><div class="hc-fila-granja-meta">';
                            } else {
                                html += '<div class="hc-fila-granja-meta">';
                            }
                            html += '<input type="checkbox" class="hc-chk-g w-4 h-4 flex-shrink-0" data-granja="' + escHtml(cod) + '" title="Marcar o desmarcar todas las campañas de esta granja">';
                            html += '<span class="gmc-granja-cod">' + escHtml(cod) + '</span>';
                            html += '<span class="hc-fila-granja-nombre" title="' + escHtml(nom) + '">' + escHtml(nom) + '</span>';
                            if (rowLayout) {
                                html += '</div></div>';
                                html += '<div class="hc-camp-body"><div class="hc-camp-wrap" role="group" aria-label="Campañas ' + escHtml(cod) + '">';
                            } else {
                                html += '</div>';
                                html += '<div class="hc-camp-col">';
                                html += '<span class="hc-camp-label">Campañas</span>';
                                html += '<div class="hc-camp-wrap" role="group" aria-label="Campañas ' + escHtml(cod) + '">';
                            }
                            if (!periodoOk) {
                                html += '<span class="text-xs text-gray-500">Periodo incompleto</span>';
                            } else if (!camps.length) {
                                html += '<span class="text-xs text-gray-500">Sin campañas en el periodo</span>';
                            } else {
                                camps.forEach(function (row) {
                                    var c = String(row.campania || '').trim();
                                    if (!c) {
                                        return;
                                    }
                                    var cid = prefix + '-camp-' + cod.replace(/[^a-z0-9_-]/gi, '_') + '-' + c.replace(/[^a-z0-9_-]/gi, '_');
                                    html += '<label class="hc-camp-line cursor-pointer" for="' + cid + '">';
                                    html += '<input type="checkbox" class="hc-chk-camp w-4 h-4" id="' + cid + '" data-granja="' + escHtml(cod) + '" value="' + escHtml(c) + '">';
                                    html += '<span>' + escHtml(c) + '</span></label>';
                                });
                            }
                            if (rowLayout) {
                                html += '</div></div>';
                            } else {
                                html += '</div></div>';
                            }
                            if (_galpones.length) {
                                html += renderGalponColHtml(cod, _galpones);
                            }
                            html += '</div>';
                        } else {
                            var _galponesSingle = galponesParaGranja(cod);
                            var _gridClsSingle = _galponesSingle.length
                                ? 'gmc-fila-granja-grid gmc-fila-granja-grid--single-galp'
                                : 'gmc-fila-granja-grid';
                            html += '<div class="' + _gridClsSingle + '">';
                            html += '<span class="gmc-lbl gmc-lbl-granja">Granja:</span>';
                            html += '<div class="hc-fila-granja-meta">';
                            html += '<span class="gmc-granja-cod">' + escHtml(cod) + '</span>';
                            html += '<span class="hc-fila-granja-nombre" title="' + escHtml(nom) + '">' + escHtml(nom) + '</span></div>';
                            html += '<span class="gmc-lbl gmc-lbl-camp">Campaña:</span>';
                            html += '<div class="hc-camp-wrap" role="group" aria-label="Campaña ' + escHtml(cod) + '">';
                            if (!camps.length) {
                                html += '<span class="text-xs text-gray-500">Sin campaña para esta fecha</span>';
                            } else {
                                camps.forEach(function (row) {
                                    var codCorrel = String(row.codigo || '').trim();
                                    var camp = String(row.campania || '').trim();
                                    if (!codCorrel || !codigoCencoValido(codCorrel)) {
                                        codCorrel = formatCodigoCorrelativo(cod, camp);
                                    }
                                    if (!camp) {
                                        return;
                                    }
                                    var on = codigoCencoEquivalent(codCorrel, selCod6);
                                    var cid = prefix + '-camp-' + cod.replace(/[^a-z0-9_-]/gi, '_') + '-' + camp.replace(/[^a-z0-9_-]/gi, '_');
                                    html += '<label class="hc-camp-line cursor-pointer" for="' + cid + '">';
                                    html += '<input type="checkbox" class="hc-chk-camp w-4 h-4" name="' + prefix + '-pick-cenco" id="' + cid + '" data-granja="' + escHtml(cod) + '" value="' + escHtml(codCorrel) + '"' + (on ? ' checked' : '') + '>';
                                    html += '<span>' + escHtml(camp);
                                    if (row.campania_fallback) {
                                        html += '<span class="gmc-camp-fallback" title="Campaña anterior más cercana"> *</span>';
                                    }
                                    html += '</span></label>';
                                });
                            }
                            html += '</div>';
                            if (_galponesSingle.length) {
                                html += renderGalponColHtml(cod, _galponesSingle);
                            }
                            html += '</div>';
                        }
                        html += '</div>';
                    });
                    html += '</div></div>';
                });
                html += '</div></div>';
            });
            box.innerHTML = html || '<p class="text-gray-500 text-sm py-4 text-center">Ningún resultado.</p>';
            if (mode === 'multi') {
                if (_freshUiOnRender) {
                    _freshUiOnRender = false;
                    resetSelectionUiMulti();
                } else {
                    syncCampFromStateMulti(byCamp);
                }
            }
            wireTree();
            if (mode === 'single') {
                syncCampFromStateSingle();
            }
            filtrarBusqueda();
        }

        function wireTree() {
            var box = el(ids.lista);
            if (!box || treeWired) {
                return;
            }
            treeWired = true;
            box.addEventListener('click', function (e) {
                var t = e.target;
                if (t && t.closest && t.closest('.hc-exp-z')) {
                    var b = t.closest('.hc-exp-z');
                    var zone = b.closest('.tree-zone');
                    if (!zone) {
                        return;
                    }
                    var body = zone.querySelector('.tree-zone-body');
                    var open = body && body.style.display === 'none';
                    if (body) {
                        body.style.display = open ? '' : 'none';
                    }
                    b.setAttribute('aria-expanded', open ? 'true' : 'false');
                    var ic = b.querySelector('.hc-chev');
                    if (ic) {
                        ic.classList.toggle('hc-chev-open', !!open);
                    }
                    return;
                }
                if (t && t.closest && t.closest('.hc-exp-sz')) {
                    var b2 = t.closest('.hc-exp-sz');
                    var sub = b2.closest('.tree-subzona');
                    if (!sub) {
                        return;
                    }
                    var gr = sub.querySelector('.tree-subzona-granjas');
                    var open2 = gr && gr.style.display === 'none';
                    if (gr) {
                        gr.style.display = open2 ? '' : 'none';
                    }
                    b2.setAttribute('aria-expanded', open2 ? 'true' : 'false');
                    var ic2 = b2.querySelector('.hc-chev');
                    if (ic2) {
                        ic2.classList.toggle('hc-chev-open', !!open2);
                    }
                    return;
                }
                if (t && t.closest && t.closest('.hc-exp-galp-btn')) {
                    var bg = t.closest('.hc-exp-galp-btn');
                    var colG = bg.closest('.hc-galpon-col');
                    if (!colG) {
                        return;
                    }
                    var wrapG = colG.querySelector('.hc-galpon-wrap');
                    var openG = bg.getAttribute('aria-expanded') !== 'true';
                    bg.setAttribute('aria-expanded', openG ? 'true' : 'false');
                    colG.classList.toggle('hc-galpon-col--expanded', !!openG);
                    var icG = bg.querySelector('.hc-chev');
                    if (icG) {
                        icG.classList.toggle('hc-chev-open', !!openG);
                    }
                    if (wrapG) {
                        wrapG.style.display = openG ? '' : 'none';
                    }
                    if (!openG) {
                        colG.querySelectorAll('.hc-chk-galpon').forEach(function (gc) { gc.checked = true; });
                        colG.querySelectorAll('.hc-chk-galpon-all').forEach(function (gc) { gc.checked = true; });
                    }
                    return;
                }
            });
            box.addEventListener('change', function (e) {
                var t = e.target;
                if (!t || !t.classList) {
                    return;
                }
                if (mode === 'single' && t.classList.contains('hc-chk-camp')) {
                    if (t.checked) {
                        box.querySelectorAll('.hc-chk-camp').forEach(function (cc) {
                            if (cc !== t) {
                                cc.checked = false;
                            }
                        });
                    }
                    return;
                }
                if (mode !== 'multi') {
                    return;
                }
                if (t.classList.contains('hc-chk-z')) {
                    var zone = t.closest('.tree-zone');
                    if (!zone) {
                        return;
                    }
                    var onZ = !!t.checked;
                    t.indeterminate = false;
                    zone.querySelectorAll('.hc-chk-g').forEach(function (ch) {
                        ch.checked = onZ;
                        ch.indeterminate = false;
                        var row = ch.closest('.fila-granja');
                        if (row) {
                            row.querySelectorAll('.hc-chk-camp').forEach(function (cc) { cc.checked = onZ; });
                            row.querySelectorAll('.hc-chk-galpon').forEach(function (gc) { gc.checked = onZ; });
                        }
                    });
                    zone.querySelectorAll('.tree-subzona').forEach(function (sub) { syncSubMulti(sub); });
                    syncChkTodasMulti();
                    notifySelectionChange();
                    return;
                }
                if (t.classList.contains('hc-chk-sz')) {
                    var subEl = t.closest('.tree-subzona');
                    if (!subEl) {
                        return;
                    }
                    var onS = !!t.checked;
                    t.indeterminate = false;
                    subEl.querySelectorAll('.hc-chk-g').forEach(function (ch) {
                        ch.checked = onS;
                        ch.indeterminate = false;
                        var row = ch.closest('.fila-granja');
                        if (row) {
                            row.querySelectorAll('.hc-chk-camp').forEach(function (cc) { cc.checked = onS; });
                            row.querySelectorAll('.hc-chk-galpon').forEach(function (gc) { gc.checked = onS; });
                        }
                    });
                    syncSubMulti(subEl);
                    syncChkTodasMulti();
                    notifySelectionChange();
                    return;
                }
                if (t.classList.contains('hc-chk-g')) {
                    var rowG = t.closest('.fila-granja');
                    if (rowG) {
                        var onG = !!t.checked;
                        t.indeterminate = false;
                        rowG.querySelectorAll('.hc-chk-camp').forEach(function (cc) { cc.checked = onG; });
                        rowG.querySelectorAll('.hc-chk-galpon').forEach(function (gc) { gc.checked = onG; });
                    }
                    syncSubMulti(t.closest('.tree-subzona'));
                    syncChkTodasMulti();
                    notifySelectionChange();
                    return;
                }
                if (t.classList.contains('hc-chk-camp')) {
                    syncGranjaRowFromCamps(t.closest('.fila-granja'));
                    syncSubMulti(t.closest('.tree-subzona'));
                    syncChkTodasMulti();
                    notifySelectionChange();
                }
                if (t.classList.contains('hc-chk-galpon')) {
                    /* Sincronizar el "Todos" de la misma granja: se desmarca si algun galpon se desmarco, se marca solo si todos estan marcados */
                    var rowGal2 = t.closest('.fila-granja');
                    if (rowGal2) {
                        var _allGalpChk = rowGal2.querySelector('.hc-chk-galpon-all');
                        if (_allGalpChk) {
                            var _gals = rowGal2.querySelectorAll('.hc-chk-galpon');
                            var _nTot = _gals.length;
                            var _nOn = 0;
                            _gals.forEach(function (gc) { if (gc.checked) _nOn++; });
                            _allGalpChk.checked = _nTot > 0 && _nOn === _nTot;
                            _allGalpChk.indeterminate = false;
                        }
                    }
                    notifySelectionChange();
                }
                if (t.classList.contains('hc-chk-galpon-all')) {
                    var onGalAll = !!t.checked;
                    t.indeterminate = false;
                    var rowGal = t.closest('.fila-granja');
                    if (rowGal) {
                        rowGal.querySelectorAll('.hc-chk-galpon').forEach(function (gc) { gc.checked = onGalAll; });
                    }
                    notifySelectionChange();
                }
            });
        }

        function notifySelectionChange() {
            if (typeof cfg.onSelectionChange === 'function') {
                cfg.onSelectionChange();
            }
        }

        function filtrarBusqueda() {
            var q = ((el(ids.buscar) || {}).value || '').trim().toLowerCase();
            var box = el(ids.lista);
            if (!box) {
                return;
            }
            box.querySelectorAll('.fila-granja').forEach(function (row) {
                var gj = (row.getAttribute('data-granja') || '').toLowerCase();
                var nom = ((row.querySelector('.hc-fila-granja-nombre') || {}).textContent || '').trim().toLowerCase();
                var show = !q || gj.indexOf(q) >= 0 || nom.indexOf(q) >= 0;
                if (!show && q) {
                    row.querySelectorAll('.hc-camp-line').forEach(function (lab) {
                        if ((lab.textContent || '').toLowerCase().indexOf(q) >= 0) {
                            show = true;
                        }
                    });
                }
                if (!show && q) {
                    row.querySelectorAll('.hc-galpon-line').forEach(function (lab) {
                        if ((lab.textContent || '').toLowerCase().indexOf(q) >= 0) {
                            show = true;
                        }
                    });
                }
                row.style.display = show ? '' : 'none';
            });
            box.querySelectorAll('.tree-subzona').forEach(function (sub) {
                var any = false;
                sub.querySelectorAll('.fila-granja').forEach(function (r) {
                    if (r.style.display !== 'none') {
                        any = true;
                    }
                });
                sub.style.display = any ? '' : 'none';
            });
            box.querySelectorAll('.tree-zone').forEach(function (z) {
                var any = false;
                z.querySelectorAll('.tree-subzona').forEach(function (s) {
                    if (s.style.display !== 'none') {
                        any = true;
                    }
                });
                z.style.display = any ? '' : 'none';
            });
        }

        function closeModal() {
            var bd = el(ids.backdrop);
            var pan = el(ids.panel);
            if (bd) {
                bd.classList.add('lay-hidden');
                bd.setAttribute('aria-hidden', 'true');
            }
            if (pan) {
                pan.classList.add('lay-hidden');
                pan.setAttribute('aria-hidden', 'true');
            }
            if (cfg.bodyOpenClass) {
                document.body.classList.remove(cfg.bodyOpenClass);
            }
            var res = el(cfg.resumenId);
            if (res) {
                res.blur();
            }
        }

        function openModalUi() {
            mountToBody();
            var bd = el(ids.backdrop);
            var pan = el(ids.panel);
            if (!bd || !pan) {
                console.error('GmcGranjasCampanias: faltan nodos modal para prefix ' + prefix);
                return false;
            }
            bd.classList.remove('lay-hidden');
            bd.setAttribute('aria-hidden', 'false');
            pan.classList.remove('lay-hidden');
            pan.setAttribute('aria-hidden', 'false');
            if (cfg.bodyOpenClass) {
                document.body.classList.add(cfg.bodyOpenClass);
            }
            justOpened = true;
            window.setTimeout(function () { justOpened = false; }, 400);
            return true;
        }

        function readMultiFromDom() {
            var nextCamp = {};
            var nextGalp = {};
            var box = el(ids.lista);
            if (!box) {
                return { selCodes: [], campByGranja: {}, galponByGranja: {} };
            }
            box.querySelectorAll('.fila-granja').forEach(function (row) {
                var chG = row.querySelector('.hc-chk-g');
                var g = String((chG && chG.getAttribute('data-granja')) || row.getAttribute('data-granja') || '').trim();
                if (!g) {
                    return;
                }
                var chosen = [];
                row.querySelectorAll('.hc-chk-camp:checked').forEach(function (cc) {
                    var v = String(cc.value || '').trim();
                    if (v) {
                        chosen.push(v);
                    }
                });
                if (chosen.length) {
                    nextCamp[g] = chosen;
                }
                var galpones = [];
                row.querySelectorAll('.hc-chk-galpon:checked').forEach(function (gc) {
                    var v = String(gc.value || '').trim();
                    if (v) galpones.push(v);
                });
                if (galpones.length) {
                    nextGalp[g] = galpones;
                }
            });
            return {
                selCodes: Object.keys(nextCamp).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); }),
                campByGranja: nextCamp,
                galponByGranja: nextGalp
            };
        }

        function applyMulti() {
            notifySelectionChange();
            var next = readMultiFromDom();
            if (!Object.keys(next.campByGranja).length) {
                window.alert('Marque al menos una campaña.');
                return;
            }
            if (typeof cfg.validateMultiApply === 'function') {
                var vMsg = cfg.validateMultiApply(next);
                if (vMsg) {
                    window.alert(vMsg);
                    return;
                }
            }
            /* Validar galpones solo si esa granja tiene UI de galpones (HC);
               pantallas solo granja/campaña (p. ej. consumo) no los exigen. */
            var requireGalpon = cfg.requireGalpon !== false;
            if (requireGalpon) {
                var faltanGalp = [];
                Object.keys(next.campByGranja).forEach(function (gk) {
                    var hasCamp = next.campByGranja[gk] && next.campByGranja[gk].length > 0;
                    if (!hasCamp) {
                        return;
                    }
                    var galpsUi = (galponesData && galponesData[gk]) || [];
                    if (!galpsUi.length) {
                        return;
                    }
                    var hasGalp = next.galponByGranja[gk] && next.galponByGranja[gk].length > 0;
                    if (!hasGalp) {
                        faltanGalp.push(gk);
                    }
                });
                if (faltanGalp.length > 0) {
                    window.alert('Granja(s) ' + faltanGalp.join(', ') + ': tiene campañas marcadas pero ningun galpon seleccionado.');
                    return;
                }
            }
            if (typeof cfg.setMultiState === 'function') {
                cfg.setMultiState(next);
            } else {
                multiState = next;
            }
            if (typeof cfg.onApply === 'function') {
                cfg.onApply(next);
            }
            closeModal();
        }

        function readGalponesFromRow(row, cod) {
            var galpones = [];
            if (!row) {
                return galpones;
            }
            row.querySelectorAll('.hc-chk-galpon:checked').forEach(function (gc) {
                var v = String(gc.value || '').trim();
                if (v) {
                    galpones.push(v);
                }
            });
            var todos = galponesParaGranja(cod);
            if (!galpones.length && todos.length) {
                return todos.slice();
            }
            return galpones;
        }

        function applySingle() {
            if (_opening) {
                return;
            }
            var box = el(ids.lista);
            var cod = '';
            var rowSel = null;
            var granjaSel = '';
            if (box) {
                box.querySelectorAll('.hc-chk-camp:checked').forEach(function (cc) {
                    cod = String(cc.value || '').trim();
                    rowSel = cc.closest('.fila-granja');
                    granjaSel = String(cc.getAttribute('data-granja') || '').trim();
                });
            }
            if (!cod || !codigoCencoValido(cod)) {
                window.alert('Seleccione una granja y campaña.');
                return;
            }
            var galponesSel = readGalponesFromRow(rowSel, granjaSel || granjaFromCodigo(cod));
            var todosGalp = galponesParaGranja(granjaSel || granjaFromCodigo(cod));
            var galponesTodos = !todosGalp.length || galponesSel.length >= todosGalp.length;
            if (typeof cfg.setCodigoSeis === 'function') {
                cfg.setCodigoSeis(cod);
            }
            if (typeof cfg.onApply === 'function') {
                cfg.onApply({
                    codigoSeis: cod,
                    galpones: galponesSel,
                    galponesTodos: galponesTodos
                });
            }
            closeModal();
        }

        function cancelModal() {
            if (justOpened) {
                return;
            }
            if (mode === 'multi') {
                if (typeof cfg.setMultiState === 'function') {
                    cfg.setMultiState(JSON.parse(JSON.stringify(snapMulti)));
                } else {
                    multiState = JSON.parse(JSON.stringify(snapMulti));
                }
            } else if (typeof cfg.setCodigoSeis === 'function') {
                cfg.setCodigoSeis(snapSingle);
            }
            closeModal();
        }

        function loadGranjasMulti() {
            var p = typeof cfg.granjasFetch === 'function' ? cfg.granjasFetch() : Promise.resolve([]);
            return Promise.resolve(p).then(function (lista) {
                lista = lista || [];
                if (typeof cfg.filterGranjas === 'function') {
                    lista = cfg.filterGranjas(lista);
                }
                metaLista = lista;
                if (typeof cfg.onMetaLoaded === 'function') {
                    cfg.onMetaLoaded(metaLista);
                }
                return metaLista;
            });
        }

        function loadCampaniasMulti() {
            /* Periodo incompleto a mitad de edición: no vaciar campañas ya cargadas. */
            if (!periodoOkMulti()) {
                return Promise.resolve(campByGranjaExt || {});
            }
            if (!metaLista.length) {
                campByGranjaExt = {};
                return Promise.resolve({});
            }
            var codes = [];
            var seen = {};
            metaLista.forEach(function (g) {
                var k = normGranja3(g.granja);
                if (k && !seen[k]) {
                    seen[k] = true;
                    codes.push(k);
                }
            });
            if (!codes.length) {
                return Promise.resolve({});
            }
            var p = typeof cfg.campaniasFetch === 'function' ? cfg.campaniasFetch(codes) : Promise.resolve({});
            return Promise.resolve(p).then(function (by) {
                campByGranjaExt = by || {};
                /* Extraer galpones_por_granja si vienen incluidos */
                if (by && by.galpones_por_granja) {
                    galponesData = by.galpones_por_granja;
                    delete by.galpones_por_granja;
                }
                return campByGranjaExt;
            }).catch(function () {
                campByGranjaExt = {};
                return {};
            });
        }

        function loadCencosSingle() {
            var p = typeof cfg.cencosFetch === 'function' ? cfg.cencosFetch() : Promise.resolve({ granjas: [], campanias_por_granja: {}, aviso: '' });
            return Promise.resolve(p).then(function (data) {
                data = data || {};
                metaLista = data.granjas || [];
                campByGranjaSingle = data.campanias_por_granja || {};
                if (data.galpones_por_granja) {
                    galponesData = data.galpones_por_granja;
                }
                cencosAviso = data.aviso || '';
                if (typeof cfg.onMetaLoaded === 'function') {
                    cfg.onMetaLoaded(metaLista);
                }
                return data;
            }).catch(function () {
                metaLista = [];
                campByGranjaSingle = {};
                cencosAviso = 'Error al cargar granjas/campañas.';
                return {};
            });
        }

        function open() {
            if (_opening) {
                return Promise.resolve();
            }
            _opening = true;
            var bus = el(ids.buscar);
            if (bus) {
                bus.value = '';
            }
            if (mode === 'multi') {
                var st = getMultiState();
                snapMulti = {
                    selCodes: (st.selCodes || []).slice(),
                    campByGranja: JSON.parse(JSON.stringify(st.campByGranja || {})),
                    galponByGranja: JSON.parse(JSON.stringify(st.galponByGranja || {}))
                };
                /* Cada apertura: UI como al inicio (galpones todos, campañas ninguna). */
                _freshUiOnRender = true;
            } else {
                snapSingle = getSelCodigo6();
            }
            if (!openModalUi()) {
                _opening = false;
                _freshUiOnRender = false;
                return Promise.resolve();
            }
            // Limpiar el DOM del arbol para evitar interaccion con contenido obsoleto
            // mientras se cargan los datos asincronos
            var oldBox = el(ids.lista);
            if (oldBox) {
                oldBox.innerHTML = '<p class="text-gray-500 text-sm py-4 text-center">Cargando...</p>';
            }
            if (mode === 'multi') {
                var chain = metaLista.length ? Promise.resolve(metaLista) : loadGranjasMulti();
                return chain.then(function () {
                    return loadCampaniasMulti();
                }).then(function (by) {
                    renderTree(by);
                    _opening = false;
                    if (_reloadQueued) {
                        _reloadQueued = false;
                        return reloadCampanias();
                    }
                    return by;
                }).catch(function (err) {
                    _opening = false;
                    _freshUiOnRender = false;
                    if (_reloadQueued) {
                        _reloadQueued = false;
                        return reloadCampanias();
                    }
                    throw err;
                });
            }
            return loadCencosSingle().then(function () {
                renderTree();
                _opening = false;
                if (_reloadQueued) {
                    _reloadQueued = false;
                    return reloadCampanias();
                }
            }).catch(function (err) {
                _opening = false;
                _freshUiOnRender = false;
                if (_reloadQueued) {
                    _reloadQueued = false;
                    return reloadCampanias();
                }
                throw err;
            });
        }

        function reloadCampanias() {
            if (_opening) {
                _reloadQueued = true;
                return Promise.resolve(campByGranjaExt || {});
            }
            if (mode !== 'multi') {
                return loadCencosSingle().then(function () {
                    if (isOpen()) {
                        renderTree();
                    }
                    return campByGranjaSingle || {};
                });
            }
            return loadCampaniasMulti().then(function (by) {
                if (isOpen()) {
                    renderTree(by);
                }
                return by;
            });
        }

        function bindControls() {
            mountToBody();
            if (hasModalPeriodoCampanias() && global.GmcPeriodoCampanias) {
                global.GmcPeriodoCampanias.init(prefix, {
                    onChange: function () {
                        reloadCampanias().catch(function () { /* ignore */ });
                    }
                });
            }
            var bd = el(ids.backdrop);
            if (bd) {
                bd.addEventListener('click', cancelModal);
            }
            [ids.close, ids.btnCancel].forEach(function (id) {
                var node = el(id);
                if (node) {
                    node.addEventListener('click', cancelModal);
                }
            });
            var ap = el(ids.btnApply);
            if (ap) {
                ap.addEventListener('click', mode === 'multi' ? applyMulti : applySingle);
            }
            var bus = el(ids.buscar);
            if (bus) {
                bus.addEventListener('input', filtrarBusqueda);
            }
            var chkAll = el(ids.chkTodas);
            if (chkAll && mode === 'multi') {
                chkAll.addEventListener('change', function () {
                    var on = !!this.checked;
                    this.indeterminate = false;
                    var box = el(ids.lista);
                    if (!box) {
                        return;
                    }
                    box.querySelectorAll('.hc-chk-g').forEach(function (ch) {
                        ch.checked = on;
                        ch.indeterminate = false;
                        var row = ch.closest('.fila-granja');
                        if (row) {
                            row.querySelectorAll('.hc-chk-camp').forEach(function (cc) { cc.checked = on; });
                            row.querySelectorAll('.hc-chk-galpon').forEach(function (gc) { gc.checked = on; });
                        }
                    });
                    box.querySelectorAll('.hc-chk-z').forEach(function (ch) {
                        ch.checked = on;
                        ch.indeterminate = false;
                    });
                    box.querySelectorAll('.hc-chk-sz').forEach(function (ch) {
                        ch.checked = on;
                        ch.indeterminate = false;
                    });
                    syncChkTodasMulti();
                    notifySelectionChange();
                });
            }
            if (cfg.openTriggerId) {
                var tr = el(cfg.openTriggerId);
                if (tr) {
                    tr.addEventListener('mousedown', function (e) { e.preventDefault(); open(); });
                    tr.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            open();
                        }
                    });
                }
            }
            if (cfg.openTriggerSelector) {
                document.querySelectorAll(cfg.openTriggerSelector).forEach(function (tr) {
                    tr.addEventListener('click', function (e) {
                        if (cfg.openTargetKey) {
                            cfg._openTarget = tr.getAttribute('data-gmc-target') || cfg.openTargetKey;
                        }
                        open(e);
                    });
                });
            }
        }

        bindControls();

        return {
            open: open,
            close: closeModal,
            cancel: cancelModal,
            reloadCampanias: reloadCampanias,
            reloadCencos: reloadCampanias,
            render: renderTree,
            isOpen: isOpen,
            getPeriodoCampaniasParams: getPeriodoCampaniasParams,
            getPeriodoCampaniasFechaRef: getPeriodoCampaniasFechaRef,
            getMetaLista: function () { return metaLista.slice(); },
            setMetaLista: function (lista) { metaLista = lista || []; },
            ids: ids
        };
    }

    global.GmcGranjasCampanias = { create: create };
    global.GmcCodigoCenco = {
        normGranja3: normGranja3,
        padCampania: padCampania,
        codigoCenco6: codigoCenco6,
        formatCorrelativo: formatCodigoCorrelativo,
        normalizar: normalizarCodigoCenco,
        equivalent: codigoCencoEquivalent,
        valido: codigoCencoValido,
        granjaFrom: granjaFromCodigo,
        campaniaFrom: campaniaFromCodigo
    };
}(typeof window !== 'undefined' ? window : this));
