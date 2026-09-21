import {translate} from '@scheffer/form-manager-plus/translations.js';
import {request, element, errorText, proBadge} from '@scheffer/form-manager-plus/tools-client.js';

/** Native form editor extension; description data stays outside the form definition. */
export function bootstrap(app) {
    const config = window.TYPO3?.settings?.FormManagerPlus?.description;
    if (!config || !window.TYPO3.settings.FormManagerPlus.enabled || document.documentElement.dataset.fmpDescriptionReady === 'true') return;
    document.documentElement.dataset.fmpDescriptionReady = 'true';
    const de = config.language === 'de';
    const t = translate;
    const call = (op, values = {}, method = 'POST') => request(config.api, config.csrf, op, {identifier: config.identifier, ...values}, method);
    let selected = new URL(location.href).searchParams.get('fmpDescription') === '1' ? 'description' : 'form';
    let revision = 0, loaded = false, editable = false, saving = false, baseline = '';
    let categoryChoices = [], categoryIds = [], categoryBaseline = [], categoryVersion = '', categoriesEditable = false;
    const panel = element('section', undefined, 'fmp-editor-description');
    panel.id = 'fmp-description-panel'; panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', 'fmp-description-tab');
    const fields = {};
    for (const [key, label, limit] of [
        ['purpose', t('ui_d4e8830a71c7'), 255],
        ['responsible_user', t('ui_bc110a6d0722'), 0],
        ['responsible', t('ui_0d4de7fb8ca2'), 255],
        ['notes', t('ui_d8ecfc07659d'), 10000],
    ]) {
        const wrapper = element('label', label, 'fmp-description-field');
        if (key !== 'purpose') { const heading = element('span', label); heading.append(proBadge(de)); wrapper.replaceChildren(heading); }
        const input = element(key === 'responsible_user' ? 'select' : key === 'notes' ? 'textarea' : 'input');
        input.className = key === 'responsible_user' ? 'form-select' : 'form-control';
        input.name = 'fmp_' + key; input.disabled = true;
        if (limit) input.maxLength = limit;
        if (key === 'notes') input.rows = 4;
        wrapper.hidden = key !== 'purpose' && !config.pro; wrapper.append(input); panel.append(wrapper); fields[key] = input;
    }
    const categoryField = element('section', undefined, 'fmp-metadata-categories');
    const categoryLabel = element('div', t('ui_b8b1d894c683'), 'fmp-category-field-label'); categoryLabel.id = 'fmp-category-field-label'; categoryField.setAttribute('aria-labelledby', categoryLabel.id);
    const chipBox = element('div', undefined, 'fmp-category-chipbox');
    const chips = element('div', undefined, 'fmp-category-chips');
    const picker = element('details', undefined, 'fmp-category-picker'); picker.hidden = true;
    const add = element('summary', t('ui_cbff99fe3bad')); add.setAttribute('aria-label', t('ui_9dd213621004'));
    const options = element('div', undefined, 'fmp-category-options');
    const categorySearch = element('input', undefined, 'form-control'); categorySearch.type = 'search'; categorySearch.placeholder = t('ui_738baad4b453'); categorySearch.setAttribute('aria-label', categorySearch.placeholder);
    const choices = element('div', undefined, 'fmp-category-choices'); options.append(categorySearch, choices); picker.append(add, options);
    chipBox.append(chips, picker); categoryField.append(categoryLabel, chipBox); panel.append(categoryField);
    const categoryMessage = element('p', '', 'fmp-description-status'); categoryField.append(categoryMessage);
    function renderCategories() {
        chips.replaceChildren(); choices.replaceChildren();
        picker.hidden = !categoriesEditable;
        const selectedSet = new Set(categoryIds);
        for (const item of categoryChoices) {
            const id = Number(item.uid);
            if (selectedSet.has(id)) {
                const chip = element('span', undefined, 'fmp-category-chip'); chip.append(element('span', item.title));
                if (categoriesEditable) {
                    const remove = element('button'); remove.type = 'button'; remove.setAttribute('aria-label', t('ui_d4d7cda0a7c1') + item.title);
                    const icon = element('typo3-backend-icon'); icon.setAttribute('identifier', 'actions-close'); icon.setAttribute('size', 'small'); remove.append(icon);
                    remove.addEventListener('click', () => { categoryIds = categoryIds.filter(value => value !== id); renderCategories(); update(); add.focus(); }); chip.append(remove);
                }
                chips.append(chip);
            }
            if (!item.title.toLocaleLowerCase().includes(categorySearch.value.trim().toLocaleLowerCase())) continue;
            const label = element('label'); const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.checked = selectedSet.has(id); checkbox.disabled = !categoriesEditable;
            checkbox.addEventListener('change', () => {
                categoryIds = checkbox.checked ? [...new Set([...categoryIds, id])].sort((a, b) => a - b) : categoryIds.filter(value => value !== id);
                renderCategories(); update(); choices.querySelector('[data-category-id="' + id + '"]')?.focus();
            }); checkbox.dataset.categoryId = String(id); label.append(checkbox, element('span', item.title)); choices.append(label);
        }
        if (!choices.children.length) choices.append(element('p', t('ui_f326a6b0fd96')));
        categoryMessage.textContent = !categoriesEditable ? t('ui_560a44e224c0') : '';
    }
    categorySearch.addEventListener('input', renderCategories);
    picker.addEventListener('toggle', () => { if (picker.open) categorySearch.focus(); });
    document.addEventListener('click', event => { if (!picker.contains(event.target)) picker.open = false; });
    picker.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); picker.open = false; add.focus(); } });
    const status = element('p', '', 'fmp-description-status'); status.setAttribute('role', 'status');
    panel.append(status);
    const history = element('details', undefined, 'fmp-history');
    const historyTitle = element('summary', t('ui_964de7c92efe')); historyTitle.append(proBadge(de));
    const historyList = element('div');
    const historyMore = element('button', t('ui_ac8991ef0101'), 'btn btn-default fmp-history-actions'); historyMore.type = 'button'; historyMore.hidden = true;
    const historyStatus = element('p', '', 'fmp-description-status'); historyStatus.setAttribute('role', 'status');
    const historyRetry = element('button', t('ui_d8b8392e2c54'), 'btn btn-default'); historyRetry.type = 'button'; historyRetry.hidden = true;
    history.append(historyTitle, historyList, historyStatus, historyMore, historyRetry); panel.append(history); history.hidden = !config.pro;
    let historyLoaded = false, historyLoading = false, historyBefore = 0;
    const historyFields = {purpose: t('ui_d4e8830a71c7'), responsible_user: t('ui_bc110a6d0722'), responsible: t('ui_591d9011ca2f'), notes: t('ui_d8ecfc07659d'), categories: t('ui_b8b1d894c683')};
    const displayHistoryValue = value => (Array.isArray(value) ? value.join(', ') : String(value ?? '')).replaceAll('[restricted]', t('ui_6c0c4d6002c3')) || '—';
    async function loadHistory(reset = false) {
        if (historyLoading) return;
        historyLoading = true; historyMore.disabled = true; historyRetry.hidden = true; historyStatus.textContent = t('ui_ae10e8899978');
        try {
            const data = await call('history', {before: reset ? 0 : historyBefore}, 'GET');
            if (reset) historyList.replaceChildren();
            for (const item of data.items) {
                const entry = element('article', undefined, 'fmp-history-entry'); entry.append(element('strong', item.actor));
                const timestamp = element('time', new Date(item.at * 1000).toLocaleString(config.language)); timestamp.dateTime = new Date(item.at * 1000).toISOString(); entry.append(timestamp);
                const changes = element('dl');
                for (const [field, change] of Object.entries(item.changes)) {
                    changes.append(element('dt', historyFields[field] || field), element('dd', t('ui_a2bcd39a7851') + displayHistoryValue(change.before) + '\n' + t('ui_84507c4d2cec') + displayHistoryValue(change.after)));
                }
                entry.append(changes); historyList.append(entry);
            }
            historyBefore = data.next || 0; historyMore.hidden = !data.next; historyLoaded = true;
            historyStatus.textContent = historyList.children.length ? '' : t('ui_8a964cc02a35');
        } catch (error) { historyStatus.textContent = errorText(error, de); historyRetry.hidden = false; }
        finally { historyLoading = false; historyMore.disabled = false; }
    }
    history.addEventListener('toggle', () => { if (history.open && !historyLoaded) loadHistory(true); });
    historyMore.addEventListener('click', () => loadHistory()); historyRetry.addEventListener('click', () => loadHistory(!historyLoaded));
    const values = () => ({purpose: fields.purpose.value, responsible_user: Number(fields.responsible_user.value) || 0, responsible: fields.responsible.value, notes: fields.notes.value, categories: [...categoryIds]});
    const dirty = () => loaded && JSON.stringify(values()) !== baseline;
    const update = () => {
        if (dirty()) {
            app.setUnsavedContent(true);
            if (!saving && !status.classList.contains('text-danger')) status.textContent = t('ui_6153545856fc');
        }
    };
    panel.addEventListener('input', update); panel.addEventListener('change', update);
    window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
    const announceError = error => { status.textContent = errorText(error, de); status.classList.add('text-danger'); };
    async function load() {
        status.classList.remove('text-danger'); status.textContent = t('ui_fb5380196b8b');
        try {
            const [data, categoryData] = await Promise.all([call('details', {}, 'GET'), call('editor_categories', {}, 'GET')]);
            const responsible = Number(data.profile.responsible_user) || 0;
            fields.responsible_user.replaceChildren(new Option(t('ui_14d33bd014e6'), '0'));
            for (const user of data.responsibleUsers || []) fields.responsible_user.add(new Option(user.label, String(user.uid)));
            if (responsible && !(data.responsibleUsers || []).some(user => user.uid === responsible)) {
                const option = new Option(t('ui_055770320a7d'), String(responsible)); option.disabled = true; fields.responsible_user.add(option);
            }
            for (const [key, input] of Object.entries(fields)) { input.value = key === 'responsible_user' ? String(responsible) : data.profile[key] || ''; input.disabled = !data.editable; }
            categoryChoices = categoryData.categories; categoryIds = categoryData.selected.map(Number).sort((a, b) => a - b); categoryBaseline = [...categoryIds]; categoryVersion = categoryData.version; categoriesEditable = data.editable && categoryData.editable;
            renderCategories();
            editable = data.editable; revision = Number(data.profile.revision); loaded = true; baseline = JSON.stringify(values());
            status.textContent = editable ? '' : data.reason ? errorText(new Error(data.reason), de) : t('ui_b7f150cc24cc'); update();
        } catch (error) { announceError(error); }
    }
    async function saveDescription(submitted) {
        if (!editable || JSON.stringify(submitted) === baseline) return;
        saving = true; status.classList.remove('text-danger');
        status.textContent = t('ui_cab19ca43d53');
        try {
            if (categoriesEditable && JSON.stringify(submitted.categories) !== JSON.stringify(categoryBaseline)) {
                const result = await call('editor_categories_save', {selected: submitted.categories, revision, version: categoryVersion});
                revision = result.revision; categoryVersion = result.version; categoryBaseline = result.selected.map(Number).sort((a, b) => a - b);
            }
            const data = await call('profile_save', {revision, ...submitted}); revision = Number(data.profile.revision);
            const saved = {purpose: data.profile.purpose || '', responsible_user: Number(data.profile.responsible_user) || 0, responsible: data.profile.responsible || '', notes: data.profile.notes || '', categories: [...categoryBaseline]};
            for (const [key, input] of Object.entries(fields)) if (String(input.value) === String(submitted[key])) input.value = String(saved[key]);
            baseline = JSON.stringify(saved);
            historyLoaded = false; if (history.open) loadHistory(true);
            status.textContent = dirty() ? t('ui_73ce79353801') : t('ui_4f801f3d6fae');
        } catch (error) {
            announceError(error); app.setUnsavedContent(true);
            app.getViewModel().showErrorFlashMessage(t('ui_7070160fd97b'), errorText(error, de));
        }
        finally { saving = false; update(); }
    }
    let pendingDescription = null, saveQueue = Promise.resolve();
    app.getPublisherSubscriber().subscribe('view/header/button/save/clicked', () => {
        pendingDescription = loaded && editable && dirty() ? values() : null;
    });
    app.getPublisherSubscriber().subscribe('core/ajax/saveFormDefinition/success', () => {
        const submitted = pendingDescription; pendingDescription = null;
        if (submitted) saveQueue = saveQueue.then(() => saveDescription(submitted));
    });
    app.getPublisherSubscriber().subscribe('core/ajax/saveFormDefinition/error', () => { pendingDescription = null; });
    let queued = false;
    function mount() {
        const inspector = document.querySelector('[data-identifier="inspector"]');
        if (!inspector || app.getCurrentlySelectedFormElement() !== app.getRootFormElement() || inspector.querySelector('.fmp-description-tabs')) return;
        const children = Array.from(inspector.children); if (!children.length) return;
        const tabs = element('div', undefined, 'fmp-description-tabs'); tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', t('ui_cb1e1f4d5c69'));
        const native = element('div', undefined, 'fmp-native-properties'); native.id = 'fmp-form-panel'; native.setAttribute('role', 'tabpanel'); native.setAttribute('aria-labelledby', 'fmp-form-tab');
        const choose = key => {
            selected = key; native.hidden = key !== 'form'; panel.hidden = key !== 'description';
            for (const button of tabs.children) { const active = button.dataset.tab === key; button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1; }
        };
        for (const [key, text] of [['form', t('ui_2e0e960ab320')], ['description', t('ui_9eddf573cb50')]]) {
            const button = element('button', text); button.type = 'button'; button.id = 'fmp-' + key + '-tab'; button.dataset.tab = key; button.setAttribute('role', 'tab'); button.setAttribute('aria-controls', key === 'form' ? native.id : panel.id);
            button.addEventListener('click', () => choose(key));
            button.addEventListener('keydown', event => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const next = event.key === 'Home' ? 'form' : event.key === 'End' ? 'description' : selected === 'form' ? 'description' : 'form'; choose(next); tabs.querySelector('[data-tab="' + next + '"]').focus(); });
            tabs.append(button);
        }
        // Preserve Core's header and move its existing controls without cloning handlers.
        children[0].after(tabs); for (const child of children.slice(1)) native.append(child);
        inspector.append(native, panel); choose(selected);
    }
    const schedule = () => { if (queued) return; queued = true; queueMicrotask(() => { queued = false; mount(); }); };
    app.getPublisherSubscriber().subscribe('view/inspector/editor/insert/perform', schedule);
    app.getPublisherSubscriber().subscribe('view/ready', () => {
        if (new URL(location.href).searchParams.get('fmpDescription') === '1') {
            app.getPublisherSubscriber().publish('view/stage/element/clicked', [app.getRootFormElement().get('__identifierPath')]);
            app.getViewModel().showInspectorSidebar();
        }
        schedule();
    });
    load();
}
