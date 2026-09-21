import {translate} from '@scheffer/form-manager-plus/translations.js';
import {element, errorText, proBadge} from '@scheffer/form-manager-plus/tools-client.js';

export async function duplicateForm(root, row, de) {
    const t = translate;
    const {default: Modal} = await import('@typo3/backend/modal.js');
    const config = JSON.parse(root.dataset.coreConfig || '{}');
    if (!config.endpoints?.duplicate) throw new Error('operation_failed');
    const targets = root.dataset.major === '13'
        ? (config.accessibleFormStorageFolders || []).map(item => ({...item, storage: ''}))
        : (config.accessibleStorageAdapters || []).flatMap(adapter => (adapter.options?.allowedStorageLocations || []).map(item => ({...item, storage: adapter.typeIdentifier})));
    const body = element('form', undefined, 'fmp-operation-form d-grid gap-3');
    const field = (text, control) => { const label = element('label', text, 'form-label d-grid gap-2'); label.append(control); body.append(label); return control; };
    const name = field(t('ui_a037e5315ec8'), element('input', undefined, 'form-control')); name.required = true; name.maxLength = 255; name.value = row.formName + t('ui_d75612450689');
    const target = field(t('ui_a59e289477fe'), element('select', undefined, 'form-select'));
    targets.forEach((item, index) => target.add(new Option(item.label, String(index))));
    const check = (text) => { const label = element('label', undefined, 'fmp-operation-check d-flex align-items-center gap-2'); const input = element('input'); input.type = 'checkbox'; label.append(input, document.createTextNode(text)); body.append(label); return input; };
    const categories = check(t('ui_ed55a43f251c'));
    const metadata = check(root.dataset.pro === '1' ? t('ui_4fb6a31f6868') : t('ui_e31c440a4276'));
    body.append(element('p', t('ui_d67149ae506d')));
    const status = element('p'); status.setAttribute('role', 'status'); body.append(status);
    let busy = false, destination = '';
    const submit = async () => {
        if (destination) { Modal.currentModal.hideModal(); window.location.assign(destination); return; }
        if (busy || !body.reportValidity()) return;
        const chosen = targets[Number(target.value)]; if (!chosen) return;
        busy = true; body.querySelectorAll('input,select').forEach(node => node.disabled = true); status.textContent = t('ui_0db9232b0685');
        try {
            const values = {formName: name.value.trim(), formPersistenceIdentifier: row.identifier, copyCategories: categories.checked ? '1' : '0', copyMetadata: metadata.checked ? '1' : '0'};
            if (root.dataset.major === '13') values.savePath = chosen.value;
            else { values.storage = chosen.storage; values.storageLocation = chosen.value; }
            const response = await fetch(config.endpoints.duplicate, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams(values)});
            const payload = await response.json(); const result = payload.response || payload;
            if (!response.ok || result.status !== 'success' || !result.url) throw new Error('operation_failed');
            destination = result.url;
            if (result.copyWarning) {
                status.textContent = t('ui_546d10379d07');
                const open = element('a', t('ui_2c6d8d9c7563'), 'btn btn-primary'); open.href = destination; open.addEventListener('click', event => { event.preventDefault(); submit(); }); body.append(open);
            } else submit();
        } catch (error) { status.textContent = errorText(error, de); busy = false; body.querySelectorAll('input,select').forEach(node => node.disabled = false); }
    };
    body.addEventListener('submit', event => { event.preventDefault(); submit(); });
    Modal.show(t('ui_02cdaabfca80'), body, 0, [
        {text: t('ui_19766ed6ccb2'), btnClass: 'btn-default', trigger: (_event, modal) => { if (!busy || destination) modal.hideModal(); }},
        {text: t('ui_982a7984a6a4'), active: true, btnClass: 'btn-primary', trigger: submit},
    ]);
    if (!targets.length) status.textContent = t('ui_0782abe0310f');
}
