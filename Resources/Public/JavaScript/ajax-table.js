import {translate} from '@scheffer/form-manager-plus/translations.js';
import {duplicateForm} from '@scheffer/form-manager-plus/list-operations.js';
import DataTable from '../Vendor/DataTables/dataTables.min.mjs';
import {request as toolsRequest, element, errorText, proBadge, proPreview, proFieldPreview, showProFeature} from '@scheffer/form-manager-plus/tools-client.js';

const MAX_AGE = 30 * 24 * 60 * 60 * 1000;
for (const root of document.querySelectorAll('[data-fmp-ajax]')) {
    const de = root.dataset.language === 'de';
    const pro = root.dataset.pro === '1';
    const t = translate;
    const icon = name => root.querySelector('[data-fmp-icon="' + name + '"]')?.content.cloneNode(true) || document.createDocumentFragment();
    const tool = (op, values = {}, method = 'POST') => toolsRequest(root.dataset.toolsApi, root.dataset.csrf, op, values, method);
    const sidebar = root.querySelector('[data-fmp-sidebar]');
    // A tall sidebar scrolls until its last item is visible, then sticks there.
    // ResizeObserver also covers loaded categories, saved views and folded groups.
    let sidebarScrollport = sidebar.parentElement;
    while (sidebarScrollport && !/(auto|scroll|hidden|overlay)/.test(getComputedStyle(sidebarScrollport).overflowY)) sidebarScrollport = sidebarScrollport.parentElement;
    const updateSidebarOffset = () => {
        const height = sidebarScrollport?.clientHeight || window.innerHeight;
        sidebar.style.setProperty('--fmp-sidebar-top', Math.min(0, height - sidebar.getBoundingClientRect().height) + 'px');
    };
    const sidebarObserver = new ResizeObserver(updateSidebarOffset);
    sidebarObserver.observe(sidebar);
    if (sidebarScrollport) sidebarObserver.observe(sidebarScrollport);
    window.addEventListener('resize', updateSidebarOffset);
    const header = root.querySelector('[data-fmp-header]');
    const toolbar = root.querySelector('[data-fmp-toolbar]');
    const error = root.querySelector('[data-fmp-error]');
    const retry = element('button', t('ui_d8b8392e2c54'), 'fmp-secondary');
    retry.type = 'button'; retry.hidden = true; error.after(retry);
    const note = root.querySelector('[data-fmp-note]');
    const transferStatus = element('p', '', 'fmp-transfer-status'); transferStatus.hidden = true;
    transferStatus.setAttribute('role', 'status'); note.after(transferStatus);
    const tableElement = root.querySelector('table');
    const fields = [null, 'name', 'purpose', 'group', 'referenceCount', 'modifiedAt', null];
    let responsible = '', team = '', storage = '';
    const storageNames = new Map();
    let category = '', mode = '', selectedView = 0, savedViews = [], grouped = false;
    const categoryNames = new Map();
    let teamTimer;
    let table, pending, failed = false, forceRefresh = false, customSort = null;
    let facetSignature = '', editingCategory = null, pendingCategoryFocus = null;
    retry.addEventListener('click', () => { forceRefresh = true; table.ajax.reload(null, false); });
    const stateKey = root.dataset.stateKey + ':library:1:' + (pro ? 'pro' : 'lite');
    function rememberDisclosure(details, name, defaultOpen) {
        const key = stateKey + ':disclosure:' + name;
        let open = defaultOpen;
        try { const saved = localStorage.getItem(key); if (saved === 'true' || saved === 'false') open = saved === 'true'; } catch { /* Optional browser storage. */ }
        details.open = open;
        details.addEventListener('toggle', () => {
            try { localStorage.setItem(key, String(details.open)); } catch { /* Optional browser storage. */ }
        });
    }
    const modeLabels = {'': t('ui_7a96160fc1db'), mine: t('ui_4f3484a983c5'), favorites: t('ui_d97d51d37092'), recent: t('ui_acc0626438c4'),
        unreferenced: t('ui_5a574d47bb6a'), stale: t('ui_c073b3fc721e'),
        invalid: t('ui_4615ca67340c')};
    function button(text, name, className = 'fmp-nav-item') {
        const control = element('button', undefined, className); control.type = 'button';
        if (name) control.append(icon(name)); control.append(element('span', text, 'fmp-nav-label')); return control;
    }
    function closePopovers(except) { root.querySelectorAll('details[data-fmp-popup][open],details.fmp-row-menu[open]').forEach(item => { if (item !== except) item.open = false; }); }
    function popup(label, name, className = '') {
        const details = element('details', undefined, 'fmp-popup ' + className); details.dataset.fmpPopup = '';
        const summary = element('summary'); summary.title = label; summary.setAttribute('aria-label', label); summary.append(icon(name));
        const body = element('div', undefined, 'fmp-popover'); details.append(summary, body);
        details.addEventListener('toggle', () => {
            if (!details.open) return;
            closePopovers(details);
            const rect = summary.getBoundingClientRect();
            body.style.left = Math.max(12, Math.min(window.innerWidth - body.offsetWidth - 12, className ? rect.left : rect.right - body.offsetWidth)) + 'px';
            body.style.right = 'auto'; body.style.top = 'auto'; body.style.bottom = 'auto';
            if (rect.bottom + body.offsetHeight + 8 > window.innerHeight) body.style.bottom = Math.max(12, window.innerHeight - rect.top + 6) + 'px';
            else body.style.top = (rect.bottom + 8) + 'px';
        });
        return {details, body, summary};
    }
    const brand = element('a', undefined, 'fmp-brand');
    brand.href = 'https://typo3.linkloot.io/';
    brand.target = '_blank';
    brand.rel = 'noopener noreferrer';
    brand.setAttribute('aria-label', t('ui_065ccba95e68'));
    brand.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg><span>Link<span class="fmp-brand-loot">Loot</span></span>';
    const eyebrow = element('div', 'FORM MANAGER PLUS', 'fmp-eyebrow');
    const sideTitle = element('h2', t('ui_dc20b3d5d2cd'));
    sidebar.append(brand, eyebrow, sideTitle);
    const navigation = element('nav'); navigation.setAttribute('aria-label', t('ui_6fc2a6312846'));
    const mainButtons = new Map();
    for (const [key, symbol] of [['', 'list'], ['mine', 'user'], ['favorites', 'star'], ['recent', 'clock']]) {
        const item = button(modeLabels[key], symbol); item.dataset.mode = key; if (key === 'mine') item.append(proBadge(de));
        const count = element('span', '', 'fmp-count'); item.append(count); mainButtons.set(key, item);
        item.addEventListener('click', () => { category = ''; selectedView = 0; changeMode(key); }); navigation.append(item);
        if (key === 'mine' && !pro) proPreview(item);
    }
    sidebar.append(navigation);
    function section(title, key) {
        const section = element('details', undefined, 'fmp-sidebar-section'); rememberDisclosure(section, key, true);
        section.append(element('summary', title)); const content = element('div'); section.append(content); sidebar.append(section); return content;
    }
    const categories = section(t('ui_b8b1d894c683'), 'categories');
    const views = section(t('ui_81184cb517ed'), 'views');
    views.parentElement.querySelector('summary').append(proBadge(de));
    const viewList = element('div'); views.append(viewList);
    const viewsPopup = popup(t('ui_cc0d1135a312'), 'plus', 'fmp-view-manager');
    const viewsSection = views.parentElement;
    const viewsSectionWrapper = element('div', undefined, 'fmp-views-section');
    viewsSection.before(viewsSectionWrapper);
    viewsSectionWrapper.append(viewsSection, viewsPopup.details);
    if (!pro) { proPreview(viewsPopup.summary); views.append(element('p', t('ui_a3fc77617024'), 'fmp-sidebar-hint')); }
    const viewSelect = element('select', undefined, 'form-select'); viewSelect.setAttribute('aria-label', t('ui_d1da5e075c56'));
    const viewName = element('input', undefined, 'form-control'); viewName.maxLength = 80; viewName.placeholder = t('ui_572ffd186209'); viewName.setAttribute('aria-label', viewName.placeholder);
    const viewGroup = element('select', undefined, 'form-select'); viewGroup.setAttribute('aria-label', t('ui_0676784f45f1')); viewGroup.add(new Option(t('ui_bdc0857b99a9'), '0'));
    const saveView = button(t('ui_2115387503f6'), null, 'fmp-primary');
    const deleteView = button(t('ui_0767ef858aa8'), null, 'fmp-secondary'); deleteView.disabled = true;
    viewsPopup.body.append(element('h3', t('ui_2115387503f6')), element('p', t('ui_49027dc6ed7e')), viewSelect, viewName, saveView, deleteView);
    const version = element('div', 'v' + root.dataset.extensionVersion, 'fmp-extension-version');
    version.setAttribute('aria-label', 'Form Manager Plus ' + root.dataset.extensionVersion);
    sidebar.append(version);
    saveView.before(element('label', t('ui_0676784f45f1')), viewGroup, element('p', t('ui_f74707b332be')));
    const titleRow = element('div', undefined, 'fmp-title-row');
    const title = element('h1', t('ui_858451263b1b'));
    const createSlot = element('div', undefined, 'fmp-create-slot'); titleRow.append(title, createSlot); header.append(titleRow);
    const create = document.querySelector('[data-identifier="newForm"]');
    if (create) {
        create.classList.add('fmp-primary'); create.replaceChildren(icon('plus'), document.createTextNode(t('ui_a3f530fd1cb1'))); createSlot.append(create);
        document.documentElement.classList.add('fmp-library-page');
    }
    const sidebarToggle = button(t('ui_dc20b3d5d2cd'), 'menu', 'fmp-sidebar-toggle'); sidebarToggle.setAttribute('aria-expanded', 'false');
    sidebarToggle.addEventListener('click', () => { const expanded = root.classList.toggle('fmp-sidebar-open'); sidebarToggle.setAttribute('aria-expanded', String(expanded)); }); toolbar.append(sidebarToggle);
    const closeSidebar = button(t('ui_7d9eb7acb13e'), null, 'fmp-sidebar-close');
    const hideSidebar = () => { root.classList.remove('fmp-sidebar-open'); sidebarToggle.setAttribute('aria-expanded', 'false'); };
    closeSidebar.addEventListener('click', () => { hideSidebar(); sidebarToggle.focus(); }); sidebar.prepend(closeSidebar);
    sidebar.addEventListener('click', event => { if (event.target.closest('.fmp-nav-item') && window.innerWidth <= 800) { hideSidebar(); sidebarToggle.focus(); } });
    const context = element('div', '', 'fmp-filter-context'); toolbar.append(context);
    const searchLabel = element('label', undefined, 'fmp-search-field'); searchLabel.append(icon('search'));
    const search = element('input'); search.type = 'search'; search.placeholder = t('ui_204d6612d949'); search.setAttribute('aria-label', search.placeholder);
    searchLabel.append(search); toolbar.append(searchLabel);
    const filters = popup(t('ui_9ab7fb6fea31'), 'filter'); toolbar.append(filters.details);
    const modeLabel = element('label', t('ui_87bb59ba2f92')); const modeSelect = element('select', undefined, 'form-select');
    for (const [key, label] of Object.entries(modeLabels)) modeSelect.add(new Option(label + (['mine', 'stale', 'unreferenced'].includes(key) ? ' · PRO' : ''), key)); modeLabel.append(modeSelect);
    const responsibleLabel = element('label', t('ui_bc110a6d0722')); responsibleLabel.append(proBadge(de));
    const responsibleSelect = element('select', undefined, 'form-select'); responsibleSelect.add(new Option(t('ui_5f198db25a77'), '')); responsibleSelect.add(new Option(t('ui_14d33bd014e6'), 'unassigned')); responsibleLabel.append(responsibleSelect);
    const teamLabel = element('label', t('ui_804beb8dde3b')); teamLabel.append(proBadge(de)); const teamInput = element('input', undefined, 'form-control'); teamInput.maxLength = 255; teamLabel.append(teamInput);
    responsibleSelect.addEventListener('change', () => { responsible = responsibleSelect.value; selectedView = 0; sync(); table.page('first').draw(); });
    teamInput.addEventListener('input', () => { team = teamInput.value; selectedView = 0; clearTimeout(teamTimer); teamTimer = setTimeout(() => { sync(); table.page('first').draw(); }, 300); });
    const groupLabel = element('label', undefined, 'fmp-checkbox-label'); const groupCheckbox = element('input'); groupCheckbox.type = 'checkbox'; groupLabel.append(groupCheckbox, document.createTextNode(t('ui_e989d6094b67')));
    const reset = button(t('ui_10afa98480f2'), null, 'fmp-secondary');
    const refresh = button(t('ui_bdc090ec61e3'), 'refresh', 'fmp-secondary');
    const importLink = element('a', t('ui_71aacd3ac702'), 'fmp-secondary'); importLink.href = root.dataset.toolsPage;
    importLink.append(proBadge(de)); createSlot.append(importLink);
    if (!pro) { proPreview(importLink); proFieldPreview(responsibleLabel, responsibleSelect); proFieldPreview(teamLabel, teamInput); }
    const storageLabel = element('label', t('ui_a59e289477fe'));
    const storageSelect = element('select', undefined, 'form-select'); storageLabel.append(storageSelect);
    storageSelect.addEventListener('change', () => { storage = storageSelect.value; selectedView = 0; sync(); table.page('first').draw(); });
    const bulk = pro ? (await import('@scheffer/form-manager-plus-pro/bulk-selection.js')).bulkSelection(root, tableElement, toolbar, tool, de, () => { forceRefresh = true; table.ajax.reload(null, false); }) : {scope() {}, draw() {}};
    if (!pro) { const bulkPreview = button(t('ui_19f0dd9ac406'), 'list', 'fmp-secondary'); bulkPreview.append(proBadge(de)); toolbar.append(proPreview(bulkPreview)); }
    filters.body.append(element('h3', t('ui_9ab7fb6fea31')), modeLabel, storageLabel, responsibleLabel, teamLabel, groupLabel, reset, refresh);
    rememberDisclosure(filters.details, 'filters', false);
    note.textContent = t('ui_ae661efc63d6');
    function sync() {
        if (responsible && !Array.from(responsibleSelect.options).some(o => o.value === responsible)) responsibleSelect.add(new Option(t('ui_f0478c1a142c') + responsible, responsible));
        storageSelect.value = storage; modeSelect.value = mode; groupCheckbox.checked = grouped; responsibleSelect.value = responsible; teamInput.value = team;
        for (const [key, item] of mainButtons) item.setAttribute('aria-current', String(!category && !selectedView && mode === key));
        categories.querySelectorAll('button[data-category]').forEach(item => {
            const active = item.dataset.category === category || item.dataset.legacyCategory === category;
            item.setAttribute('aria-current', String(active)); item.closest('.fmp-category-nav-row')?.classList.toggle('is-active', active);
        });
        viewList.querySelectorAll('button').forEach(item => item.setAttribute('aria-current', String(Number(item.dataset.view) === selectedView)));
        context.replaceChildren();
        const chip = (label, clear) => {
            const remove = button(label + ' ×', null, 'fmp-filter-chip');
            remove.setAttribute('aria-label', t('ui_e8fe96a2bdd3') + label);
            remove.addEventListener('click', () => {
                clearTimeout(searchTimer); selectedView = 0; clear();
                sync(); table.search(search.value).page('first').draw(); search.focus();
            });
            context.append(remove);
        };
        if (selectedView) context.append(element('span', savedViews.find(view => view.id === selectedView)?.name || '', 'fmp-view-label'));
        if (category) chip(t('ui_2da1823415ec') + (categoryNames.get(category) || (category.startsWith('group:') ? category.slice(6) || t('ui_92114e3e22d5') : t('ui_292c06f0045a'))), () => { category = ''; });
        if (mode) chip(modeLabels[mode], () => { mode = ''; customSort = null; });
        if (search.value) chip(t('ui_3363e5f143b7') + search.value, () => { search.value = ''; });
        if (responsible) chip(t('ui_003fd8e5671e') + (Array.from(responsibleSelect.options).find(o => o.value === responsible)?.text || responsible), () => { responsible = ''; });
        if (storage) chip(t('ui_062cf59a5be4') + (storageNames.get(storage) || storage), () => { storage = ''; });
        if (team) chip(t('ui_91080b50922e') + team, () => { team = ''; });
        if (grouped) chip(t('ui_11df411b19dd'), () => { grouped = false; });
        if (category || mode || responsible || team || storage || search.value || grouped || selectedView) {
            const clearAll = button(t('ui_58cefb489b1a'), null, 'fmp-filter-clear');
            clearAll.addEventListener('click', () => { reset.click(); search.focus(); }); context.append(clearAll);
        }
        note.hidden = !['unreferenced', 'stale', 'invalid'].includes(mode);
        viewSelect.value = String(selectedView || ''); deleteView.disabled = !selectedView || !savedViews.find(v => v.id === selectedView)?.editable;
        filters.summary.classList.toggle('fmp-filter-active', Boolean(mode || category || responsible || team || storage || search.value || grouped));
    }
    function changeMode(next) { mode = next; customSort = null; sync(); if (mode === 'recent') table.order([]); table.page('first').draw(); }
    modeSelect.addEventListener('change', () => { if (!pro && ['mine', 'stale', 'unreferenced'].includes(modeSelect.value)) { modeSelect.value = mode; showProFeature(); return; } selectedView = 0; changeMode(modeSelect.value); });
    groupCheckbox.addEventListener('change', () => { grouped = groupCheckbox.checked; selectedView = 0; sync(); table.page('first').draw(); });
    function populateViews(items) {
        savedViews = items; viewList.replaceChildren(); viewSelect.replaceChildren(new Option(t('ui_667e1f8903d8'), ''));
        for (const view of items) {
            const displayName = view.group ? view.name + ' · ' + view.groupLabel : view.name;
            const item = button(displayName, 'document-info'); item.dataset.view = String(view.id); item.addEventListener('click', () => applyView(view.id)); viewList.append(item);
            viewSelect.add(new Option(displayName, String(view.id)));
        }
        if (!items.length) viewList.append(element('p', t('ui_0a4ac8b39328'), 'fmp-sidebar-hint'));
        if (!items.some(view => view.id === selectedView)) selectedView = 0;
        sync();
    }
    function applyView(id) {
        selectedView = id; const view = savedViews.find(item => item.id === id); viewName.value = view?.name || ''; viewGroup.value = String(view?.editable ? view.group : 0);
        if (!view) { sync(); table.state.save(); return; }
        const state = view.state; storage = state.storage || ''; responsible = state.responsible || ''; team = state.team || ''; category = state.category || ''; mode = Object.hasOwn(modeLabels, state.mode) ? state.mode : ''; grouped = Boolean(state.grouped);
        search.value = state.search || ''; const index = fields.indexOf(state.sort);
        customSort = index < 0 ? {sort: state.sort, direction: state.direction} : null;
        sync(); table.search(search.value).order(index >= 0 ? [[index, state.direction]] : []).page.len(state.length || 25).page(Math.floor((state.start || 0) / (state.length || 25))).draw(false);
    }
    viewSelect.addEventListener('change', () => applyView(Number(viewSelect.value) || 0));
    const showError = exception => { error.hidden = false; error.textContent = errorText(exception, de); };
    saveView.addEventListener('click', async () => {
        saveView.disabled = true;
        try {
            const order = table.order()[0];
            const state = {search: search.value, category, mode, responsible, team, storage, grouped: grouped ? '1' : '0', start: table.page.info().start, length: table.page.len(),
                sort: order ? fields[order[0]] : customSort?.sort || (mode === 'recent' ? 'lastEdited' : 'name'), direction: order ? order[1] : customSort?.direction || (mode === 'recent' ? 'desc' : 'asc')};
            const result = await tool('view_save', {id: savedViews.find(v => v.id === selectedView)?.editable ? selectedView : 0, group: Number(viewGroup.value), name: viewName.value, state});
            selectedView = result.views.find(view => view.name === viewName.value.trim() && view.group === Number(viewGroup.value) && view.editable)?.id || selectedView;
            populateViews(result.views); table.state.save(); error.hidden = true; viewsPopup.details.open = false;
        } catch (exception) { showError(exception); } finally { saveView.disabled = false; }
    });
    deleteView.addEventListener('click', async () => {
        if (!selectedView || !confirm(t('ui_f3d189fe581d'))) return;
        deleteView.disabled = true;
        try { const result = await tool('view_delete', {id: selectedView}); selectedView = 0; viewName.value = ''; populateViews(result.views); table.state.save(); error.hidden = true; }
        catch (exception) { showError(exception); deleteView.disabled = false; }
    });
    [t('ui_d97d51d37092'), t('ui_dcd1d5223f73'), t('ui_d4e8830a71c7'), t('ui_292c06f0045a'), t('ui_69824d3b0e70'), t('ui_0fe1058feb9f'), t('ui_ff8059dc6752')].forEach((label, index) => {
        const heading = tableElement.tHead.rows[0].cells[index]; heading.textContent = label;
        if (index === 0 || index === 6) heading.classList.add('fmp-sr-only-label');
    });
    const language = {info: t('ui_04c20d31ebde'), infoEmpty: t('ui_890953ed89b0'),
        infoFiltered: t('ui_a86c3b3062e2'), lengthMenu: t('ui_943ca1a76959'),
        zeroRecords: t('ui_2f799be3086f'), emptyTable: t('ui_7a7ac3e3fd7e'),
        processing: t('ui_1185ff3323eb'), loadingRecords: t('ui_1185ff3323eb'),
        paginate: {first: t('ui_a151ceb1711a'), previous: t('ui_a57b08a480b8'), next: t('ui_1ff57a29d7c9'), last: t('ui_eb970eb0951c')},
        aria: {orderable: t('ui_4154c9515f3c'), orderableReverse: t('ui_2a5cd31bc9e8'), orderableRemove: t('ui_b70bdc2bd531'),
            paginate: {first: t('ui_0bdbb750609c'), previous: t('ui_1208ec01f223'), next: t('ui_c08ac736a5e2'), last: t('ui_4543708d1196'), number: t('ui_6076934f99bd')}}};
    table = new DataTable(tableElement, {
        serverSide: true, processing: true, pageLength: 25, lengthMenu: [10, 25, 50, 100, 250], order: [[1, 'asc']], orderMulti: false,
        layout: {topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: ['pageLength', 'paging']}, stateSave: true, stateDuration: MAX_AGE / 1000, language,
        stateSaveParams: (_settings, state) => { Object.assign(state, {fmpStorage: storage, fmpResponsible: responsible, fmpTeam: team, fmpCategory: category, fmpMode: mode, fmpView: selectedView, fmpGrouped: grouped, fmpCustomSort: customSort}); },
        stateSaveCallback: (_settings, state) => { if (!failed) { try { localStorage.setItem(stateKey, JSON.stringify(state)); } catch { /* Optional browser storage. */ } } },
        stateLoadCallback: () => {
            try {
                const state = JSON.parse(localStorage.getItem(stateKey) || 'null');
                if (!state || !Number.isFinite(state.time) || Date.now() - state.time > MAX_AGE || ![6, 7].includes(state.columns?.length)) return null;
                if (state.columns.length === 6) {
                    state.columns.splice(4, 0, {name: 'referenceCount', visible: true, search: {search: '', smart: true, regex: false, caseInsensitive: true}});
                    if (Array.isArray(state.order)) state.order = state.order.map(([column, direction]) => [column >= 4 ? column + 1 : column, direction]);
                }
                state.length = [10, 25, 50, 100, 250].includes(state.length) ? state.length : 25;
                state.start = Number.isInteger(state.start) && state.start >= 0 && state.start <= 10000000 ? state.start : 0;
                storage = typeof state.fmpStorage === 'string' ? state.fmpStorage.slice(0, 1024) : '';
                responsible = typeof state.fmpResponsible === 'string' ? state.fmpResponsible : ''; team = typeof state.fmpTeam === 'string' ? state.fmpTeam.slice(0, 255) : '';
                category = typeof state.fmpCategory === 'string' ? state.fmpCategory.slice(0, 500) : '';
                mode = Object.hasOwn(modeLabels, state.fmpMode) ? state.fmpMode : ''; selectedView = Number.isInteger(state.fmpView) ? state.fmpView : 0;
                grouped = state.fmpGrouped === true; customSort = state.fmpCustomSort; search.value = state.search?.search || ''; sync(); return state;
            } catch { return null; }
        },
        columns: [{data: 'star', orderable: false, searchable: false, className: 'fmp-favorite-cell'}, {data: 'name', name: 'name', className: 'fmp-name-cell'},
            {data: 'purpose', name: 'purpose', className: 'fmp-purpose-cell'}, {data: 'category', name: 'group', className: 'fmp-category-cell'},
            {data: 'references', name: 'referenceCount', className: 'fmp-references-cell'},
            {data: 'modified', name: 'modifiedAt', className: 'fmp-modified-cell'}, {data: 'actions', orderable: false, searchable: false, className: 'fmp-actions'}],
        ajax: async (request, callback) => {
            bulk.scope(JSON.stringify([request.search.value, category, mode, responsible, team, storage]));
            pending?.abort(); const controller = new AbortController(); pending = controller;
            const url = new URL(root.dataset.url, location.origin); const order = request.order[0];
            Object.entries({draw: request.draw, start: request.start, length: request.length, search: request.search.value,
                sort: order ? fields[order.column] || 'name' : customSort?.sort || (mode === 'recent' ? 'lastEdited' : 'name'), direction: order ? order.dir : customSort?.direction || (mode === 'recent' ? 'desc' : 'asc'),
                category, mode, responsible, team, storage, grouped: grouped ? '1' : '0', refresh: forceRefresh ? '1' : '0'}).forEach(([key, value]) => url.searchParams.set(key, String(value)));
            forceRefresh = false;
            try {
                const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
                if (!response.ok) {
                    let code = [401, 403].includes(response.status) ? 'session_expired' : 'request_failed';
                    if (response.headers.get('content-type')?.includes('application/json')) {
                        const failure = await response.json(); if (failure.error === 'schema_setup_required') code = failure.error;
                    }
                    const failure = new Error(code); failure.httpStatus = response.status; throw failure;
                }
                const result = await response.json();
                if (!Array.isArray(result.data) || !Array.isArray(result.categories)) throw new Error('invalid_response');
                if (controller.signal.aborted) return; failed = false; error.hidden = true; retry.hidden = true;
                root.classList.remove('fmp-load-failed');
                storageSelect.replaceChildren(new Option(t('ui_9dfe01782bf9'), '')); storageNames.clear();
                for (const facet of result.storageFacets || []) { const label = facet.key === 'database' ? t('ui_fa7fe67124e9') : facet.key; storageNames.set(facet.key, label); storageSelect.add(new Option(label + ' (' + facet.count + ')', facet.key)); }
                if (storage && !storageNames.has(storage)) storageSelect.add(new Option(storage, storage));
                const previousFocus = categories.contains(document.activeElement) ? document.activeElement.dataset.category : null;
                const facets = result.categoryFacets || result.categories.map(group => ({key: 'group:' + group, title: group, count: result.categoryCounts?.[group] || 0, icon: 'folder'}));
                const nextSignature = JSON.stringify(facets);
                // Keep nodes stable across filtering so the first click cannot
                // destroy the name before a native double-click reaches it.
                if (!editingCategory && nextSignature !== facetSignature) {
                facetSignature = nextSignature; categories.replaceChildren(); categoryNames.clear();
                for (const facet of facets) {
                    const group = facet.title;
                    const title = group || t('ui_92114e3e22d5');
                    categoryNames.set(facet.key, title);
                    const item = button(title, null); item.dataset.category = facet.key; item.dataset.legacyCategory = 'group:' + group;
                    const row = element('div', undefined, 'fmp-category-nav-row');
                    const styles = [facet];
                    const marks = element('span', undefined, 'fmp-category-marks'); marks.setAttribute('aria-hidden', 'true');
                    for (const style of styles.length ? styles : [{icon: 'folder'}]) {
                        const mark = element('span', undefined, 'fmp-category-mark'); mark.append(icon(style.icon)); mark.title = style.title || '';
                        if (/^#[0-9a-f]{6}$/i.test(style.color || '') && /^#(?:000000|ffffff)$/.test(style.ink || '')) {
                            mark.style.setProperty('--fmp-category-color', style.color); mark.style.setProperty('--fmp-category-ink', style.ink);
                        }
                        marks.append(mark);
                    }
                    if (facet.editUrl || (!pro && facet.uid > 0)) {
                        const picker = popup(t('ui_5ef73c4f2d6e') + title, facet.icon, 'fmp-category-style-popup');
                        if (!pro) proPreview(picker.summary);
                        picker.summary.replaceChildren(marks); row.append(picker.details);
                        const styleHeading = element('h3', title); styleHeading.append(proBadge(de)); picker.body.append(styleHeading);
                        const icons = element('div', undefined, 'fmp-category-icon-grid');
                        const names = {folder: t('ui_74ccd4330384'), star: t('ui_e357d396871d'), calendar: t('ui_d5d0a30b517e'), envelope: t('ui_47a1436c7090'), user: t('ui_6007db63e18e'), users: t('ui_5985039f106d'), tag: t('ui_1503916a2ab2'), heart: t('ui_770af9d18037'), briefcase: t('ui_bb489680ebc1'), bell: t('ui_8e80b2b4b22a'), check: t('ui_523cfcb59b78')};
                        const status = element('p'); status.setAttribute('role', 'status');
                        const saveAppearance = async (nextIcon, nextColor) => {
                            picker.body.querySelectorAll('button,input').forEach(control => control.disabled = true);
                            status.textContent = t('ui_eb0236aaf673');
                            try {
                                await tool('category_appearance', {uid: facet.uid, icon: nextIcon, color: nextColor});
                                picker.details.open = false; facetSignature = ''; forceRefresh = true; pendingCategoryFocus = facet.key;
                                table.ajax.reload(null, false);
                            } catch (exception) { status.textContent = errorText(exception, de); }
                            finally { picker.body.querySelectorAll('button,input').forEach(control => control.disabled = false); }
                        };
                        for (const [key, name] of Object.entries(names)) {
                            const choice = button('', key, 'fmp-icon-choice'); choice.title = name; choice.setAttribute('aria-label', name); choice.setAttribute('aria-pressed', String(key === facet.icon));
                            choice.addEventListener('click', () => saveAppearance(key, facet.color || '')); icons.append(choice);
                        }
                        picker.body.append(icons);
                        const colorLabel = element('label', t('ui_c31a3f15ca97'));
                        const colorInput = element('input'); colorInput.type = 'color'; colorInput.value = facet.color || '#ff8700'; colorLabel.append(colorInput);
                        colorInput.addEventListener('change', () => saveAppearance(facet.icon, colorInput.value));
                        const colors = element('div', undefined, 'fmp-category-color-grid');
                        for (const [hex, name] of [['#ff8700', t('ui_78e7771b8b46')], ['#2563eb', t('ui_ec7d56a01607')], ['#15803d', t('ui_d486dfbd5fb5')], ['#7c3aed', t('ui_7d465fb9b931')], ['#dc2626', t('ui_ba19e9c3d5f4')], ['#0f766e', t('ui_fa15a5c1d610')], ['#64748b', t('ui_d4ac58091b02')]]) {
                            const choice = button('', null, 'fmp-color-choice'); choice.style.backgroundColor = hex; choice.title = name; choice.setAttribute('aria-label', name); choice.setAttribute('aria-pressed', String(hex === facet.color));
                            choice.addEventListener('click', () => saveAppearance(facet.icon, hex)); colors.append(choice);
                        }
                        const clear = button(t('ui_074c88b68c47'), null, 'fmp-secondary'); clear.addEventListener('click', () => saveAppearance(facet.icon, ''));
                        picker.body.append(colors, colorLabel, clear, status);
                    } else {
                        const appearance = element('span', undefined, 'fmp-category-icon'); appearance.append(marks); row.append(appearance);
                    }
                    item.append(element('span', String(facet.count), 'fmp-count'));
                    item.addEventListener('click', () => { category = item.dataset.category; mode = ''; selectedView = 0; sync(); table.page('first').draw(); }); row.append(item);
                    if (facet.canRename) {
                        item.title = t('ui_b8f096922100');
                        const beginRename = () => {
                            if (editingCategory) return;
                            const input = element('input', undefined, 'fmp-category-rename');
                            input.type = 'text'; input.maxLength = 255; input.value = title;
                            input.setAttribute('aria-label', t('ui_73ddd937c98b'));
                            editingCategory = input; item.hidden = true; row.append(input); input.focus(); input.select();
                            let saving = false;
                            const cancel = () => { if (saving || editingCategory !== input) return; editingCategory = null; input.remove(); item.hidden = false; item.focus(); };
                            const save = async () => {
                                if (saving) return;
                                const value = input.value.trim();
                                if (value === title) { cancel(); return; }
                                if (!value) { input.setAttribute('aria-invalid', 'true'); input.focus(); return; }
                                saving = true; input.disabled = true;
                                try {
                                    await tool('category_rename', {uid: facet.uid, previous: title, title: value});
                                    if (category === 'group:' + title) category = facet.key;
                                    editingCategory = null; input.remove(); item.hidden = false;
                                    pendingCategoryFocus = facet.key;
                                    facetSignature = ''; forceRefresh = true; error.hidden = true;
                                    table.ajax.reload(null, false);
                                } catch (exception) { showError(exception); input.disabled = false; input.focus(); }
                                finally { saving = false; }
                            };
                            input.addEventListener('keydown', event => {
                                if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); save(); }
                                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); cancel(); }
                            });
                            input.addEventListener('blur', () => { if (!saving) cancel(); });
                        };
                        item.addEventListener('dblclick', event => { event.preventDefault(); beginRename(); });
                        item.addEventListener('keydown', event => { if (event.key === 'F2') { event.preventDefault(); beginRename(); } });
                    }
                    categories.append(row);
                    if (previousFocus === item.dataset.category || pendingCategoryFocus === item.dataset.category) item.focus();
                }
                pendingCategoryFocus = null;
                }
                for (const [key, item] of mainButtons) item.querySelector('.fmp-count').textContent = !pro && key === 'mine' ? '' : String(result.summary?.[key || 'all'] ?? 0);
                sync(); callback(result);
                if (result.start !== request.start && result.recordsFiltered > 0) table.page(Math.floor(result.start / request.length)).draw('page');
            } catch (exception) {
                if (controller.signal.aborted) return; failed = true; error.hidden = false;
                root.classList.add('fmp-load-failed'); retry.hidden = false;
                error.textContent = exception.message === 'schema_setup_required' ? t('schema_setup_required') : exception.message === 'session_expired'
                    ? t('ui_a248c5608cc4')
                    : t('ui_c36acab62a56');
                if (exception.httpStatus) error.append(document.createTextNode(' (HTTP ' + exception.httpStatus + ')'));
                callback({draw: request.draw, recordsTotal: 0, recordsFiltered: 0, data: []});
                root.querySelector('.dt-empty')?.replaceChildren(document.createTextNode(t('ui_4a55b0207c56')));
                // Demo infrastructure may safely renew its overview. No Core auth
                // is bypassed, and regular installations retain their own login flow.
                window.parent.postMessage({type: 'fmp-list-load-error'}, location.origin);
            }
        },
        drawCallback: function () {
            tableElement.querySelectorAll('[data-fmp-pro-preview]:not([data-fmp-preview-bound])').forEach(control => { control.dataset.fmpPreviewBound = '1'; proPreview(control); });
            tableElement.querySelectorAll('[data-fmp-color]').forEach(mark => {
                if (/^#[0-9a-f]{6}$/i.test(mark.dataset.fmpColor) && /^#(?:000000|ffffff)$/.test(mark.dataset.fmpInk)) {
                    mark.style.setProperty('--fmp-category-color', mark.dataset.fmpColor);
                    mark.style.setProperty('--fmp-category-ink', mark.dataset.fmpInk);
                }
            });
            tableElement.querySelectorAll('th:not(.dt-orderable-none) .dt-column-order').forEach(indicator => {
                const heading = indicator.closest('th');
                indicator.replaceChildren(icon(heading.classList.contains('dt-ordering-desc') ? 'arrow-down-alt' : heading.classList.contains('dt-ordering-asc') ? 'arrow-up-alt' : 'exchange'));
            });
            const api = this.api(); bulk.draw(api); root.querySelectorAll('.fmp-ajax-group').forEach(row => row.remove()); let previous = null;
            if (!grouped || mode === 'recent') return;
            api.rows({page: 'current'}).every(function () { const item = this.data(); if (item.group === previous) return; previous = item.group;
                const row = element('tr', undefined, 'fmp-ajax-group'); const heading = element('th', item.group || t('ui_92114e3e22d5')); heading.colSpan = 7; heading.scope = 'rowgroup'; row.append(heading); this.node().before(row); });
        },
    });
    let searchTimer;
    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { selectedView = 0; sync(); table.search(search.value).page('first').draw(); }, 300); });
    reset.addEventListener('click', () => { clearTimeout(searchTimer); clearTimeout(teamTimer); category = ''; mode = ''; responsible = ''; team = ''; storage = ''; selectedView = 0; grouped = false; customSort = null; search.value = ''; viewName.value = ''; sync(); table.state.clear(); table.search('').order([[1, 'asc']]).page.len(25).page('first').draw(); filters.details.open = false; });
    refresh.addEventListener('click', () => { forceRefresh = true; table.ajax.reload(null, false); filters.details.open = false; });
    // Native handlers keep their persistent proxies and authorization dialogs.
    tableElement.addEventListener('click', async event => {
        const categoryFilter = event.target.closest('[data-fmp-category]');
        if (categoryFilter) {
            category = categoryFilter.dataset.fmpCategory; mode = ''; selectedView = 0;
            sync(); table.page('first').draw(); return;
        }
        const favorite = event.target.closest('[data-fmp-favorite]');
        if (favorite) {
            event.preventDefault(); const data = table.row(favorite.closest('tr')).data(); if (!data) return; favorite.disabled = true;
            try { await tool('favorite', {identifier: data.identifier, favorite: !data.favorite}); table.ajax.reload(null, false); }
            catch (exception) { showError(exception); } finally { favorite.disabled = false; } return;
        }
        const action = event.target.closest('[data-fmp-action]'); if (!action) return; event.preventDefault();
        if (action.dataset.fmpAction === 'export') {
            if (action.getAttribute('aria-busy') === 'true') return;
            const data = table.row(action.closest('tr')).data(); if (!data) return;
            action.setAttribute('aria-busy', 'true'); closePopovers(); error.hidden = true;
            transferStatus.hidden = false; transferStatus.textContent = t('ui_8753e7693e86');
            try {
                const exported = await tool('export', {identifier: data.identifier}, 'GET');
                const blobUrl = URL.createObjectURL(new Blob([JSON.stringify(exported, null, 2)], {type: 'application/json'}));
                const download = element('a'); download.href = blobUrl;
                download.download = String(exported.definition?.identifier || 'form').replace(/[^a-z0-9_-]/gi, '-') + '.fmp.json';
                download.hidden = true; document.body.append(download); download.click(); download.remove();
                setTimeout(() => URL.revokeObjectURL(blobUrl), 60000);
                transferStatus.textContent = t('ui_d796c38aa385') + download.download;
            } catch (exception) { transferStatus.hidden = true; showError(exception); }
            finally { action.removeAttribute('aria-busy'); }
            return;
        }
        if (action.dataset.fmpAction === 'duplicateForm') {
            const data = table.row(action.closest('tr')).data(); if (!data) return; closePopovers();
            try { await duplicateForm(root, data, de); } catch (exception) { showError(exception); } return;
        }
        if (action.dataset.fmpAction === 'showReferences') {
            const data = table.row(action.closest('tr')).data(); if (!data) return;
            closePopovers();
            try {
                const [{default: Modal}, result] = await Promise.all([import('@typo3/backend/modal.js'), tool('references', {identifier: data.identifier}, 'GET')]);
                const content = element('div');
                content.append(element('h2', t('ui_fe5cb76507c8'), 'h3'));
                const referencesTable = element('table', undefined, 'table table-striped table-hover');
                const head = element('thead'), headings = element('tr'), body = element('tbody');
                for (const label of [t('ui_0a30a815d67d'), t('ui_ec08ff86c3f5')]) { const th = element('th', label); th.scope = 'col'; headings.append(th); }
                head.append(headings); referencesTable.append(head, body);
                for (const reference of result.references) {
                    const row = element('tr');
                    for (const [title, uid, url] of [[reference.pageTitle, reference.pageUid, reference.pageUrl], [reference.contentTitle, reference.contentUid, reference.contentUrl]]) {
                        const cell = element('td');
                        const label = title || '#' + uid;
                        const link = element(url ? 'a' : 'span', label);
                        if (url) { link.href = url; link.addEventListener('click', event => { event.preventDefault(); Modal.currentModal.hideModal(); window.location.assign(link.href); }); }
                        cell.append(link); row.append(cell);
                    }
                    body.append(row);
                }
                if (result.references.length) content.append(referencesTable);
                else content.append(element('p', t('ui_23fa6af3780a')));
                Modal.show(t('ui_364ec23bbacf') + data.formName, content, 0, [{text: t('ui_7d9eb7acb13e'), active: true, btnClass: 'btn-default', trigger: (_event, modal) => modal.hideModal()}]);
            } catch (exception) { showError(exception); }
            return;
        }
        const data = table.row(action.closest('tr')).data(); const proxy = root.querySelector('[data-fmp-core-proxies] [data-identifier="' + action.dataset.fmpAction + '"]');
        if (!data || !proxy) return; closePopovers(); proxy.dataset.formName = data.formName; proxy.dataset.formPersistenceIdentifier = data.identifier;
        if (root.dataset.major === '13') { const {default: jQuery} = await import('jquery'); jQuery(proxy).data('formName', data.formName).data('formPersistenceIdentifier', data.identifier); }
        proxy.click();
    });
    tableElement.addEventListener('toggle', event => {
        const details = event.target; if (!details.matches('.fmp-row-menu') || !details.open) return; closePopovers(details);
        const panel = details.querySelector('.fmp-row-popover');
        panel.querySelectorAll('a.btn').forEach(link => { if (!link.querySelector('.fmp-action-label')) link.append(element('span', link.getAttribute('aria-label'), 'fmp-action-label')); });
        const rect = details.getBoundingClientRect(); panel.style.right = Math.max(12, window.innerWidth - rect.right) + 'px';
        panel.style.top = ''; panel.style.bottom = '';
        if (rect.bottom + panel.offsetHeight + 8 > window.innerHeight) panel.style.bottom = (window.innerHeight - rect.top + 6) + 'px'; else panel.style.top = (rect.bottom + 6) + 'px';
    }, true);
    document.addEventListener('click', event => { if (!event.target.closest('details[data-fmp-popup],details.fmp-row-menu')) closePopovers(); if (!sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) hideSidebar(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { const open = root.querySelector('details[data-fmp-popup][open],details.fmp-row-menu[open]'); closePopovers(); open?.querySelector('summary')?.focus(); if (root.classList.contains('fmp-sidebar-open')) { hideSidebar(); sidebarToggle.focus(); } } });
    window.addEventListener('pagehide', () => { pending?.abort(); clearTimeout(searchTimer); clearTimeout(teamTimer); }, {once: true});
    if (pro) tool('views', {}, 'GET').then(result => {
        for (const group of result.groups || []) viewGroup.add(new Option(group.title, String(group.uid)));
        for (const user of result.responsibleUsers || []) { const existing = Array.from(responsibleSelect.options).find(o => o.value === String(user.uid)); if (existing) existing.text = user.label; else responsibleSelect.add(new Option(user.label, String(user.uid))); }
        populateViews(result.views);
    }).catch(showError);
}
