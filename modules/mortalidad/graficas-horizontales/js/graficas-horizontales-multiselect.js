/**
 * Multiselect jerárquico granja/campaña/galpón — gráficas horizontales.
 */
class CustomMultiSelect {
    constructor({ btnId, labelId, dropdownId, searchId, optionsContainerId, options = [], title, onChange }) {
        this.btn = document.getElementById(btnId);
        this.label = document.getElementById(labelId);
        this.dropdown = document.getElementById(dropdownId);
        this.search = document.getElementById(searchId);
        this.container = document.getElementById(optionsContainerId);
        this.title = title;
        this.searchQuery = '';
        this.onChange = onChange;
        this.initialized = false;

        this.options = options.map((item, idx) => {
            const id = typeof item === 'object' ? String(item.id) : String(item);
            const label = typeof item === 'object' ? String(item.label || item.id) : String(item);
            const isSelected = (typeof item === 'object' && item.selected !== undefined) ? Boolean(item.selected) : (idx === 0);
            return { id, label, selected: isSelected };
        });

        if (this.btn && this.dropdown && this.container) {
            this.init();
        }
    }

    init() {
        // Evento Click Botón principal
        this.btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.custom-multiselect-dropdown').forEach(d => {
                if (d !== this.dropdown) d.classList.add('hidden');
            });

            const isHidden = this.dropdown.classList.contains('hidden');
            if (isHidden) {
                this.dropdown.classList.remove('hidden');
                if (this.search) this.search.focus();
            } else {
                this.dropdown.classList.add('hidden');
            }
        });

        this.dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        if (this.search) {
            this.search.addEventListener('input', (e) => {
                this.searchQuery = e.target.value;
                this.render();
            });
        }

        this.updateLabel();
        this.render();
        this.initialized = true;
    }

    updateLabel() {
        if (!this.label) return;
        const seleccionados = this.options.filter(o => o.selected);
        const total = this.options.length;

        if (seleccionados.length === 0) {
            this.label.textContent = 'Ninguno seleccionado';
            this.label.className = 'truncate text-slate-400 font-normal';
        } else if (seleccionados.length === total && total > 1) {
            this.label.textContent = 'Todos seleccionados';
            this.label.className = 'truncate text-slate-800 font-semibold';
        } else if (seleccionados.length === 1) {
            this.label.textContent = seleccionados[0].label;
            this.label.className = 'truncate text-slate-800 font-semibold';
        } else {
            this.label.textContent = `${seleccionados.length} seleccionados`;
            this.label.className = 'truncate text-slate-800 font-semibold';
        }

        if (this.initialized && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    render() {
        if (!this.container) return;
        const query = this.searchQuery.toLowerCase().trim();
        const filtrados = this.options.filter(op => op.label.toLowerCase().includes(query));
        const todosSeleccionados = this.options.length > 0 && this.options.every(o => o.selected);

        let html = '';

        if (!query || 'todas'.includes(query) || 'todos'.includes(query)) {
            html += `
                <label class="flex items-center gap-3 px-3 py-2 hover:bg-blue-50/70 rounded-lg cursor-pointer group transition-colors">
                    <input type="checkbox" class="chk-todos w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500 cursor-pointer" ${todosSeleccionados ? 'checked' : ''}>
                    <span class="text-xs font-bold text-blue-700 group-hover:text-blue-800 uppercase tracking-wider">TODOS</span>
                </label>
                <div class="my-1 border-t border-slate-100"></div>
            `;
        }

        if (filtrados.length === 0) {
            html += `
                <div class="px-3 py-3 text-xs text-slate-400 text-center">
                    No se encontraron opciones
                </div>
            `;
        } else {
            filtrados.forEach(op => {
                html += `
                    <label class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 rounded-lg cursor-pointer group transition-colors">
                        <input type="checkbox" data-id="${op.id}" ${op.selected ? 'checked' : ''} class="chk-item w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500 cursor-pointer">
                        <span class="text-xs text-slate-700 group-hover:text-slate-900 font-medium">${op.label}</span>
                    </label>
                `;
            });
        }

        this.container.innerHTML = html;

        const chkTodos = this.container.querySelector('.chk-todos');
        if (chkTodos) {
            chkTodos.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            chkTodos.addEventListener('change', (e) => {
                const isChecked = e.target.checked;
                this.options.forEach(o => o.selected = isChecked);
                this.updateLabel();
                this.render();
            });
        }

        const chksItem = this.container.querySelectorAll('.chk-item');
        chksItem.forEach(chk => {
            chk.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            chk.addEventListener('change', (e) => {
                const id = e.target.getAttribute('data-id');
                const opcion = this.options.find(o => o.id === id);
                if (opcion) {
                    opcion.selected = e.target.checked;
                }
                this.updateLabel();
                this.render();
            });
        });
    }

    getSelectedValues() {
        return this.options.filter(o => o.selected).map(o => o.id);
    }

    selectAll(triggerChange = true) {
        this.options.forEach(o => o.selected = true);
        this.updateLabel();
        this.render();
        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    clearSelection() {
        this.options.forEach(o => o.selected = false);
        this.updateLabel();
        this.render();
    }

    selectOnlyIds(ids, triggerChange = true) {
        const set = new Set((ids || []).map(String));
        this.options.forEach(o => {
            o.selected = set.has(String(o.id));
        });
        this.updateLabel();
        this.render();
        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    setOptions(newOptions, defaultSelectFirst = true) {
        const previousSelected = this.getSelectedValues();

        this.options = newOptions.map((item, idx) => {
            const id = typeof item === 'object' ? String(item.id) : String(item);
            const label = typeof item === 'object' ? String(item.label || item.id) : String(item);

            let isSelected = false;
            if (previousSelected.length > 0) {
                isSelected = previousSelected.includes(id);
            } else if (typeof item === 'object' && item.selected !== undefined) {
                isSelected = Boolean(item.selected);
            } else if (defaultSelectFirst && idx === 0) {
                isSelected = true;
            }

            return { id, label, selected: isSelected };
        });

        this.updateLabel();
        this.render();
    }
}

/**
 * Componente Jerárquico Integrado para Granjas, Campañas y Galpones
 */
class CustomHierarchicalMultiSelect {
    constructor({ btnId, labelId, dropdownId, searchId, optionsContainerId, btnTodosId, btnLimpiarId, onChange }) {
        this.btn = document.getElementById(btnId);
        this.label = document.getElementById(labelId);
        this.dropdown = document.getElementById(dropdownId);
        this.search = document.getElementById(searchId);
        this.container = document.getElementById(optionsContainerId);
        this.btnTodos = document.getElementById(btnTodosId);
        this.btnLimpiar = document.getElementById(btnLimpiarId);
        this.onChange = onChange;

        this.searchQuery = '';
        this.granjasData = [];
        this.expandedMap = {};
        this.selectedGranjas = new Set();
        this.selectedCampanias = new Set();
        this.selectedGalpones = new Set();
        this.initialized = false;

        if (this.dropdown && this.container) {
            this.init();
        }
    }

    init() {
        if (this.btn) {
            this.btn.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.custom-multiselect-dropdown').forEach(d => {
                    if (d !== this.dropdown) d.classList.add('hidden');
                });

                const isHidden = this.dropdown.classList.contains('hidden');
                if (isHidden) {
                    this.dropdown.classList.remove('hidden');
                    if (this.search) this.search.focus();
                } else {
                    this.dropdown.classList.add('hidden');
                }
            });
        }

        this.dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        if (this.search) {
            this.search.addEventListener('input', (e) => {
                this.searchQuery = e.target.value;
                this.render();
            });
        }

        if (this.btnTodos) {
            this.btnTodos.addEventListener('click', () => {
                this.selectAll();
            });
        }

        if (this.btnLimpiar) {
            this.btnLimpiar.addEventListener('click', () => {
                this.clearSelection();
            });
        }

        this.initialized = true;
    }

    setData(granjasData, defaultSelectAll = false) {
        this.granjasData = Array.isArray(granjasData) ? granjasData : [];
        if (defaultSelectAll) {
            this.selectAll(false);
        } else {
            this.clearSelection(false);
        }
    }

    selectAll(triggerChange = true) {
        this.selectedGranjas.clear();
        this.selectedCampanias.clear();
        this.selectedGalpones.clear();

        this.granjasData.forEach(g => {
            const gId = String(g.granja || g.codigo || g.nombre);
            this.selectedGranjas.add(gId);
            if (Array.isArray(g.campanias)) {
                g.campanias.forEach(c => this.selectedCampanias.add(`${gId}__${c}`));
            }
            if (Array.isArray(g.galpones)) {
                g.galpones.forEach(gp => this.selectedGalpones.add(`${gId}__${gp}`));
            }
        });

        this.updateLabel();
        this.render();
        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    clearSelection(triggerChange = true) {
        this.selectedGranjas.clear();
        this.selectedCampanias.clear();
        this.selectedGalpones.clear();

        this.updateLabel();
        this.render();
        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    _cmpCampania(a, b) {
        const na = parseFloat(String(a).replace(/[^\d.-]/g, ''));
        const nb = parseFloat(String(b).replace(/[^\d.-]/g, ''));
        if (!isNaN(na) && !isNaN(nb)) return na - nb;
        return String(a).localeCompare(String(b));
    }

    selectMuestraInicial(triggerChange = true) {
        this.selectedGranjas.clear();
        this.selectedCampanias.clear();
        this.selectedGalpones.clear();

        const primera = this.granjasData[0];
        if (!primera) {
            this.updateLabel();
            this.render();
            if (triggerChange && typeof this.onChange === 'function') {
                this.onChange();
            }
            return;
        }

        const gId = String(primera.granja || primera.codigo || primera.nombre);
        const campanias = Array.isArray(primera.campanias) ? primera.campanias.slice() : [];
        const galpones = Array.isArray(primera.galpones) ? primera.galpones.slice() : [];

        if (campanias.length > 0 && galpones.length > 0) {
            campanias.sort((a, b) => this._cmpCampania(a, b));
            this.selectedGranjas.add(gId);
            this.selectedCampanias.add(`${gId}__${campanias[0]}`);
            this.selectedGalpones.add(`${gId}__${galpones[0]}`);
        }

        this.updateLabel();
        this.render();
        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    selectUltimaCampania(triggerChange = true) {
        this.selectedGranjas.clear();
        this.selectedCampanias.clear();
        this.selectedGalpones.clear();

        this.granjasData.forEach(g => {
            const gId = String(g.granja || g.codigo || g.nombre);
            const campanias = Array.isArray(g.campanias) ? g.campanias.slice() : [];
            if (campanias.length === 0) return;
            campanias.sort((a, b) => this._cmpCampania(a, b));
            const ultima = campanias[campanias.length - 1];
            this.selectedGranjas.add(gId);
            this.selectedCampanias.add(`${gId}__${ultima}`);
            if (Array.isArray(g.galpones)) {
                g.galpones.forEach(gp => this.selectedGalpones.add(`${gId}__${gp}`));
            }
        });

        this.updateLabel();
        this.render();
        if (triggerChange && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    updateLabel() {
        if (!this.label) return;
        const totalGranjas = this.granjasData.length;
        const totalGalpones = this.granjasData.reduce((acc, g) => acc + (Array.isArray(g.galpones) ? g.galpones.length : 0), 0);
        const selGalponesCount = this.selectedGalpones.size;
        const selGranjasCount = this.selectedGranjas.size;

        if (selGalponesCount === 0 && selGranjasCount === 0) {
            this.label.textContent = 'Ninguno seleccionado';
            this.label.className = 'truncate text-slate-400 font-normal';
        } else if (selGranjasCount === totalGranjas && selGalponesCount === totalGalpones && totalGranjas > 0) {
            this.label.textContent = 'Todos seleccionados';
            this.label.className = 'truncate text-slate-800 font-semibold';
        } else {
            this.label.textContent = `${selGranjasCount} granjas (${selGalponesCount} galpones)`;
            this.label.className = 'truncate text-slate-800 font-semibold';
        }

        if (this.initialized && typeof this.onChange === 'function') {
            this.onChange();
        }
    }

    getSelectedValues() {
        return this.getSelectedCombinations();
    }

    getSelectedCombinations() {
        const combinations = [];

        this.granjasData.forEach(gObj => {
            const gId = String(gObj.granja || gObj.codigo || gObj.nombre);

            let camps = [];
            if (Array.isArray(gObj.campanias)) {
                camps = gObj.campanias.filter(c => this.selectedCampanias.has(`${gId}__${c}`));
            }

            let galps = [];
            if (Array.isArray(gObj.galpones)) {
                galps = gObj.galpones.filter(gp => this.selectedGalpones.has(`${gId}__${gp}`));
            }

            if (camps.length === 0 && this.selectedGranjas.has(gId) && Array.isArray(gObj.campanias)) {
                camps = [...gObj.campanias];
            }
            if (galps.length === 0 && this.selectedGranjas.has(gId) && Array.isArray(gObj.galpones)) {
                galps = [...gObj.galpones];
            }

            if (camps.length > 0 && galps.length > 0) {
                camps.forEach(c => {
                    galps.forEach(gp => {
                        combinations.push({
                            granja: gId,
                            campania: String(c),
                            galpon: String(gp)
                        });
                    });
                });
            }
        });

        return combinations;
    }

    render() {
        if (!this.container) return;
        const q = this.searchQuery.toLowerCase().trim();

        let html = '';

        if (this.granjasData.length === 0) {
            this.container.innerHTML = `<div class="px-3 py-4 text-xs text-slate-400 text-center">No hay datos de ubicación disponibles</div>`;
            return;
        }

        this.granjasData.forEach(g => {
            const gId = String(g.granja || g.codigo || g.nombre);
            const gName = (g.granja || '') + (g.nombre && g.nombre !== g.granja ? ' — ' + g.nombre : '');
            const campanias = Array.isArray(g.campanias) ? g.campanias : [];
            const galpones = Array.isArray(g.galpones) ? g.galpones : [];

            const matchGranja = gName.toLowerCase().includes(q) || gId.toLowerCase().includes(q);
            const matchingCampanias = campanias.filter(c => String(c).toLowerCase().includes(q));
            const matchingGalpones = galpones.filter(gp => String(gp).toLowerCase().includes(q));

            if (q && !matchGranja && matchingCampanias.length === 0 && matchingGalpones.length === 0) {
                return;
            }

            const isGranjaSelected = this.selectedGranjas.has(gId);
            const isExpanded = (this.expandedMap[gId] !== undefined) ? this.expandedMap[gId] : (q ? true : false);

            html += `
                <div class="border border-slate-200/80 rounded-xl p-2.5 bg-slate-50/60 mb-2 transition-all">
                    <!-- CABECERA DE GRANJA (Click en la fila abre/cierra; click en el check selecciona) -->
                    <div class="flex items-center justify-between gap-2 cursor-pointer row-granja-header hover:bg-slate-100/70 p-1.5 rounded-lg transition" data-granja="${gId}">
                        <div class="flex items-center gap-2.5 flex-1 min-w-0">
                            <input type="checkbox" data-type="granja" data-granja="${gId}" class="chk-hier-granja w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500 cursor-pointer flex-shrink-0" ${isGranjaSelected ? 'checked' : ''}>
                            <span class="text-xs font-bold text-slate-800 truncate select-none">${gName}</span>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <span class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-blue-100/80 text-blue-700 border border-blue-200/60 select-none">Granja</span>
                            <span class="text-slate-400 p-0.5">
                                <svg class="w-3.5 h-3.5 transform transition-transform duration-200 ${isExpanded ? 'rotate-180' : ''}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </span>
                        </div>
                    </div>

                    <!-- SUB-NIVELES: CAMPAÑAS Y GALPONES -->
                    <div class="mt-2.5 pl-3 pt-2 border-t border-slate-200/60 space-y-2.5 ${isExpanded ? '' : 'hidden'}">
                        <!-- CAMPAÑAS -->
                        ${campanias.length > 0 ? `
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-amber-600 mb-1 block">Campañas</span>
                                <div class="flex flex-wrap gap-1.5">
                                    ${campanias.map(c => {
                                        const cKey = `${gId}__${c}`;
                                        const isCampSelected = this.selectedCampanias.has(cKey);
                                        return `
                                            <label class="flex items-center gap-1.5 px-2 py-1 bg-white hover:bg-amber-50/50 rounded-lg border border-slate-200/80 hover:border-amber-300 cursor-pointer select-none transition-colors">
                                                <input type="checkbox" data-type="campania" data-granja="${gId}" data-campania="${c}" class="chk-hier-campania w-3.5 h-3.5 rounded text-amber-600 border-slate-300 focus:ring-amber-500 cursor-pointer" ${isCampSelected ? 'checked' : ''}>
                                                <span class="text-xs font-semibold text-slate-700">Camp. ${c}</span>
                                            </label>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        ` : ''}

                        <!-- GALPONES -->
                        ${galpones.length > 0 ? `
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-600 mb-1 block">Galpones</span>
                                <div class="grid grid-cols-4 sm:grid-cols-6 gap-1.5">
                                    ${(() => {
                                        const todosGalponesSel = galpones.every(gp => this.selectedGalpones.has(`${gId}__${gp}`));
                                        return `
                                            <label class="flex items-center justify-center gap-1.5 px-2 py-1.5 bg-indigo-50/80 hover:bg-indigo-100/80 rounded-lg border border-indigo-200/80 hover:border-indigo-400 cursor-pointer select-none transition-colors col-span-2 sm:col-span-3">
                                                <input type="checkbox" data-type="galpon-todos" data-granja="${gId}" class="chk-hier-galpon-todos w-3.5 h-3.5 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer flex-shrink-0" ${todosGalponesSel ? 'checked' : ''}>
                                                <span class="text-xs font-bold text-indigo-800">Todos</span>
                                            </label>
                                        `;
                                    })()}
                                    ${galpones.map(gp => {
                                        const gpKey = `${gId}__${gp}`;
                                        const isGalpSelected = this.selectedGalpones.has(gpKey);
                                        return `
                                            <label class="flex items-center justify-center gap-1.5 px-2 py-1.5 bg-white hover:bg-indigo-50/60 rounded-lg border border-slate-200/80 hover:border-indigo-300 cursor-pointer select-none transition-colors">
                                                <input type="checkbox" data-type="galpon" data-granja="${gId}" data-galpon="${gp}" class="chk-hier-galpon w-3.5 h-3.5 rounded text-indigo-600 border-slate-300 focus:ring-indigo-500 cursor-pointer flex-shrink-0" ${isGalpSelected ? 'checked' : ''}>
                                                <span class="text-xs font-bold text-slate-700">${gp}</span>
                                            </label>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });

        if (!html) {
            html = `<div class="px-3 py-4 text-xs text-slate-400 text-center">No se encontraron resultados para "${q}"</div>`;
        }

        this.container.innerHTML = html;
        this.bindDynamicEvents();
    }

    bindDynamicEvents() {
        this.container.querySelectorAll('.row-granja-header').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('.chk-hier-granja')) return;
                e.preventDefault();
                e.stopPropagation();
                const gId = row.getAttribute('data-granja');
                this.expandedMap[gId] = !this.expandedMap[gId];
                this.render();
            });
        });

        this.container.querySelectorAll('.chk-hier-granja').forEach(chk => {
            chk.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            chk.addEventListener('change', (e) => {
                e.stopPropagation();
                const gId = e.target.getAttribute('data-granja');
                const isChecked = e.target.checked;
                const gObj = this.granjasData.find(g => String(g.granja || g.codigo || g.nombre) === gId);

                if (isChecked) {
                    this.selectedGranjas.add(gId);
                    if (gObj) {
                        if (Array.isArray(gObj.campanias)) gObj.campanias.forEach(c => this.selectedCampanias.add(`${gId}__${c}`));
                        if (Array.isArray(gObj.galpones)) gObj.galpones.forEach(gp => this.selectedGalpones.add(`${gId}__${gp}`));
                    }
                } else {
                    this.selectedGranjas.delete(gId);
                    if (gObj) {
                        if (Array.isArray(gObj.campanias)) gObj.campanias.forEach(c => this.selectedCampanias.delete(`${gId}__${c}`));
                        if (Array.isArray(gObj.galpones)) gObj.galpones.forEach(gp => this.selectedGalpones.delete(`${gId}__${gp}`));
                    }
                }

                this.updateLabel();
                this.render();
            });
        });

        this.container.querySelectorAll('.chk-hier-campania').forEach(chk => {
            chk.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            chk.addEventListener('change', (e) => {
                const gId = e.target.getAttribute('data-granja');
                const c = e.target.getAttribute('data-campania');
                const cKey = `${gId}__${c}`;

                if (e.target.checked) {
                    this.selectedCampanias.add(cKey);
                    this.selectedGranjas.add(gId);
                } else {
                    this.selectedCampanias.delete(cKey);
                }

                this.updateLabel();
                this.render();
            });
        });

        this.container.querySelectorAll('.chk-hier-galpon-todos').forEach(chk => {
            chk.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            chk.addEventListener('change', (e) => {
                const gId = e.target.getAttribute('data-granja');
                const gObj = this.granjasData.find(g => String(g.granja || g.codigo || g.nombre) === gId);
                if (!gObj || !Array.isArray(gObj.galpones)) return;

                if (e.target.checked) {
                    this.selectedGranjas.add(gId);
                    gObj.galpones.forEach(gp => this.selectedGalpones.add(`${gId}__${gp}`));
                } else {
                    gObj.galpones.forEach(gp => this.selectedGalpones.delete(`${gId}__${gp}`));
                }

                this.updateLabel();
                this.render();
            });
        });

        this.container.querySelectorAll('.chk-hier-galpon').forEach(chk => {
            chk.addEventListener('click', (e) => {
                e.stopPropagation();
            });
            chk.addEventListener('change', (e) => {
                const gId = e.target.getAttribute('data-granja');
                const gp = e.target.getAttribute('data-galpon');
                const gpKey = `${gId}__${gp}`;

                if (e.target.checked) {
                    this.selectedGalpones.add(gpKey);
                    this.selectedGranjas.add(gId);
                } else {
                    this.selectedGalpones.delete(gpKey);
                }

                this.updateLabel();
                this.render();
            });
        });
    }
}

// --- CERRAR DROPDOWNS AL HACER CLIC AFUERA ---
document.addEventListener('click', (e) => {
    document.querySelectorAll('.custom-multiselect-dropdown').forEach(d => {
        if (d.contains(e.target)) return;
        const wrap = d.parentElement;
        if (wrap && wrap.contains(e.target)) return;
        d.classList.add('hidden');
    });
});
