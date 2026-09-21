import {translate} from '@scheffer/form-manager-plus/translations.js';
import {request, element, errorText, renderFindings, proBadge} from '@scheffer/form-manager-plus/tools-client.js';
const root = document.querySelector('[data-fmp-workbench]');
if (root) {
    const config = JSON.parse(root.dataset.config);
    const de = config.language === 'de';
    const t = translate;
    const brand = document.querySelector('[data-workbench-brand]');
    brand.querySelector('a').setAttribute('aria-label', t('ui_065ccba95e68'));
    const docheader = document.querySelector('.module-docheader');
    if (docheader) { docheader.classList.add('fmp-workbench-docheader'); docheader.prepend(brand); }
    const call = (op, values = {}, method = 'POST') => request(config.api, config.csrf, op, {identifier: config.identifier, ...values}, method);
    const notice = root.querySelector('[data-notice]');
    const notifyRevision = revision => root.dispatchEvent(new CustomEvent('fmp-revision', {detail: Number(revision)}));
    const announce = (text, fail = false) => { notice.hidden = false; notice.classList.toggle('fmp-error', fail); notice.textContent = text; };
    const run = async (button, callback) => {
        button.disabled = true;
        notice.hidden = true;
        try { await callback(); } catch (error) { announce(errorText(error, de), true); }
        finally { button.disabled = false; }
    };
    const back = root.querySelector('[data-back]');
    back.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 6-6 6 6 6"/></svg>';
    back.append(element('span', config.identifier ? t('ui_8d61a1d04fd6') : t('ui_ab2ddc803211')));
    root.querySelector('h1').textContent = config.name || t('ui_71aacd3ac702'); if (!config.identifier) root.querySelector('h1').append(proBadge(de));
    root.querySelector('[data-intro]').textContent = config.identifier ? t('ui_ffa51545a01a') : t('ui_2beb528ebbdd');
    const tabs = root.querySelector('[data-tabs]');
    const select = tab => {
        if (!Array.from(tabs.children).some(button => button.dataset.tab === tab)) tab = tabs.firstElementChild?.dataset.tab;
        for (const button of tabs.children) { const active = button.dataset.tab === tab; button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1; }
        for (const panel of root.querySelectorAll('[data-panel]')) panel.hidden = panel.dataset.panel !== tab;
    };
    const tabDefinitions = config.identifier ? [['profile', t('ui_9eddf573cb50')]] : [['transfer', t('ui_2cff9baabf56')]];
    if (config.databaseForm) tabDefinitions.unshift(['categories', t('ui_b8b1d894c683')]);
    for (const [tab, label] of tabDefinitions) {
        if (!config.identifier && tab !== 'transfer') continue;
        const button = element('button', label); button.type = 'button'; button.dataset.tab = tab; button.setAttribute('role', 'tab');
        button.id = 'fmp-tab-' + tab; button.setAttribute('aria-controls', 'fmp-panel-' + tab);
        const panel = root.querySelector('[data-panel="' + tab + '"]'); panel.id = 'fmp-panel-' + tab; panel.setAttribute('aria-labelledby', button.id);
        button.addEventListener('click', () => select(tab));
        button.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault(); const list = Array.from(tabs.children); const next = list[(list.indexOf(button) + (event.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length]; select(next.dataset.tab); next.focus();
        });
        tabs.append(button);
    }
    select(config.tab);
    const button = (text, className = 'btn btn-default') => { const node = element('button', text, className); node.type = 'button'; return node; };
    if (config.databaseForm) {
        const panel = root.querySelector('[data-panel="categories"]');
        panel.append(element('h2', t('ui_bc35b17b7546')));
        panel.append(element('p', t('ui_607cd1a075db')));
        const search = element('input', undefined, 'form-control'); search.type = 'search'; search.placeholder = t('ui_610200a390f7'); search.setAttribute('aria-label', search.placeholder);
        const list = element('div', undefined, 'fmp-category-list');
        const save = button(t('ui_ad11a2a0d070'), 'btn btn-primary');
        const newCategory = element('details', undefined, 'fmp-new-category'); newCategory.append(element('summary', t('ui_1ab1e121c0df')));
        panel.append(search, list, save, newCategory);
        let categoryRevision = 0, categoryDirty = false;
        root.addEventListener('fmp-revision', event => { categoryRevision = event.detail; });
        call('categories', {}, 'GET').then(data => {
            categoryRevision = data.revision;
            for (const item of data.categories) {
                const label = element('label'); label.dataset.title = item.title.toLocaleLowerCase();
                const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.value = String(item.uid); checkbox.checked = data.selected.includes(Number(item.uid)); checkbox.disabled = !data.editable;
                checkbox.addEventListener('change', () => { categoryDirty = true; });
                label.append(checkbox, document.createTextNode(item.title)); list.append(label);
            }
            save.disabled = !data.editable;
            for (const item of data.createTargets) { const link = element('a', t('ui_c4183639b784') + item.title); link.href = item.url; newCategory.append(link); }
            newCategory.hidden = !data.createTargets.length;
            if (!data.categories.length) list.append(element('p', t('ui_01c68c023ced')));
        }).catch(error => announce(errorText(error, de), true));
        search.addEventListener('input', () => { for (const label of list.querySelectorAll('label')) label.hidden = !label.dataset.title.includes(search.value.toLocaleLowerCase()); });
        save.addEventListener('click', () => run(save, async () => {
            const selected = Array.from(list.querySelectorAll('input:checked')).map(input => Number(input.value));
            const result = await call('categories_save', {selected, revision: categoryRevision});
            categoryRevision = result.revision; notifyRevision(result.revision); categoryDirty = false; announce(t('ui_921ccf77df84'));
        }));
        window.addEventListener('beforeunload', event => { if (categoryDirty) { event.preventDefault(); event.returnValue = ''; } });
    }
    if (config.identifier) {
        const panel = root.querySelector('[data-panel="profile"]');
        panel.append(element('h2', t('ui_0908fc93a613')));
        panel.append(element('p', t('ui_9204c82a5d2b')));
        const form = element('form', undefined, 'fmp-profile-form');
        const fields = {};
        for (const [key, label, limit, multiline] of [
            ['purpose', t('ui_ab8282bb6517'), 255, false],
            ['responsible', t('ui_ecd2c14e5cba'), 255, false],
            ['notes', t('ui_d8ecfc07659d'), 10000, true],
        ]) {
            const wrapper = element('label', label); if (key !== 'purpose') { const heading = element('span', label); heading.append(proBadge(de)); wrapper.replaceChildren(heading); }
            const input = element(multiline ? 'textarea' : 'input'); input.name = key; input.maxLength = limit; input.className = 'form-control';
            if (multiline) input.rows = 6;
            wrapper.hidden = key !== 'purpose' && !config.pro; wrapper.append(input); form.append(wrapper); fields[key] = input;
        }
        const userWrapper = element('label', t('ui_6d0db0ecf197'));
        const responsibleUser = element('select', undefined, 'form-select'); responsibleUser.name = 'responsible_user';
        responsibleUser.add(new Option(t('ui_14d33bd014e6'), '0')); responsibleUser.disabled = true;
        const userHeading = element('span', userWrapper.textContent); userHeading.append(proBadge(de)); userWrapper.replaceChildren(userHeading, responsibleUser);
        fields.responsible.closest('label').before(userWrapper);
        userWrapper.append(element('small', t('ui_3802c445c835')));
        fields.responsible_user = responsibleUser; userWrapper.hidden = !config.pro;
        let revision = 0, dirty = false;
        root.addEventListener('fmp-revision', event => { revision = event.detail; });
        const save = button(t('ui_06d1ae6bcb25'), 'btn btn-primary'); save.type = 'submit';
        const reload = button(t('ui_bdc090ec61e3')); const actions = element('div', undefined, 'fmp-actions-row'); actions.append(save, reload); form.append(actions); panel.append(form);
        const fill = async () => {
            const data = await call('details', {}, 'GET');
            responsibleUser.replaceChildren(new Option(t('ui_14d33bd014e6'), '0'));
            for (const user of data.responsibleUsers || []) responsibleUser.add(new Option(user.label, String(user.uid)));
            const currentUser = Number(data.profile.responsible_user) || 0;
            if (currentUser && !(data.responsibleUsers || []).some(user => user.uid === currentUser)) {
                const unavailable = new Option(t('ui_5b700343aea6'), String(currentUser)); unavailable.disabled = true; responsibleUser.add(unavailable);
            }
            for (const [key, field] of Object.entries(fields)) { field.value = key === 'responsible_user' ? String(currentUser) : data.profile[key] || ''; field.readOnly = !data.editable; }
            responsibleUser.disabled = !data.editable;
            revision = Number(data.profile.revision); dirty = false; save.disabled = !data.editable;
            if (!data.editable) panel.append(element('p', t('ui_b7f150cc24cc')));
        };
        form.addEventListener('input', () => { dirty = true; });
        responsibleUser.addEventListener('change', () => { dirty = true; });
        window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
        form.addEventListener('submit', event => {
            event.preventDefault();
            run(save, async () => {
                const submitted = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, field.value]));
                const data = await call('profile_save', {revision, ...submitted, responsible_user: Number(submitted.responsible_user)});
                revision = Number(data.profile.revision); notifyRevision(revision);
                const savedValue = key => key === 'responsible_user' ? String(Number(data.profile[key]) || 0) : data.profile[key] || '';
                for (const [key, field] of Object.entries(fields)) if (field.value === submitted[key]) field.value = savedValue(key);
                dirty = Object.entries(fields).some(([key, field]) => field.value !== savedValue(key));
                announce(dirty ? t('ui_79c86a1da92e') : t('ui_4f801f3d6fae'));
            });
        });
        reload.addEventListener('click', () => { if (!dirty || confirm(t('ui_470e7ee2a579'))) run(reload, fill); });
        fill().catch(error => announce(errorText(error, de), true));
    }
    if (!config.identifier && config.pro) (await import('@scheffer/form-manager-plus-pro/import-wizard.js')).mountImport(root, config);
}
