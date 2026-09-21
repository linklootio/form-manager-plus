import {translate} from '@scheffer/form-manager-plus/translations.js';
export function proBadge(de = false) {
    const badge = element('span', 'PRO', 'fmp-pro-badge'); badge.title = translate('ui_7569c02cadc2');
    badge.setAttribute('aria-label', badge.title); return badge;
}
export const messages = {
    pro_required: 'ui_a3fc77617024',
    workspace_readonly: 'ui_ceb98fe0f63e',
    bulk_size: 'ui_dc37e617149e',
    no_identifier_match: 'ui_71d85fc11f51',
    invalid_form: 'ui_8702eaf12c84',
    access: 'ui_3808d8352aad',
    readonly: 'ui_b7f150cc24cc',
    csrf: 'ui_d26934e5a98a',
    input: 'ui_959bab56b1f0',
    conflict: 'ui_7b709e8ccb99',
    ambiguous_identifier: 'ui_bd6fa3a26248',
    view_exists: 'ui_a5a0ab823c4b',
    view_limit: 'ui_3bfc972e9d34',
    target: 'ui_204cc243c021',
    expired: 'ui_e2a2ac5e764b',
    dependencies: 'ui_55723eda7bd5',
    file_size: 'ui_c22fb9befbf5',
    package: 'ui_67262c3f4864',
    invalid_json: 'ui_99214b4cde0f',
    operation_failed: 'ui_605a73fff0fe',
};
export function errorText(error, de) {
    return translate(messages[error.message] || messages.operation_failed);
}
export async function request(api, csrf, op, values = {}, method = 'POST') {
    const url = new URL(api, location.origin);
    const options = {method, credentials: 'same-origin', cache: 'no-store'};
    if (method === 'GET') Object.entries({op, ...values}).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    else { options.headers = {'Content-Type': 'application/json'}; options.body = JSON.stringify({op, csrf, ...values}); }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); } catch { throw new Error('operation_failed'); }
    if (!response.ok || result.error) throw new Error(result.error || 'operation_failed');
    return result;
}
export function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
}
export const findingMessages = {
    identifier_conflict: 'ui_8be4459422e6',
    label_conflict: 'ui_ced4585867bb',
    load_invalid: 'ui_d901af79850f',
    unknown_prototype: 'ui_6e2fd7e74b3a',
    invalid_root: 'ui_8cbf73cd9f5d',
    definition_too_large: 'ui_0094fd79020c',
    missing_identifier: 'ui_7ee985f230aa',
    duplicate_identifier: 'ui_b951d98d451d',
    unknown_element: 'ui_99ac11a5705d',
    missing_label: 'ui_29cae1032956',
    unknown_validator: 'ui_a75197fe0dfd',
    invalid_element: 'ui_93e70268d678',
    empty_form: 'ui_7c29b2ec4ad2',
    no_finishers: 'ui_91f0d489c296',
    unknown_finisher: 'ui_8fc5046575ec',
    missing_recipients: 'ui_43e26fda12d9',
    missing_sender: 'ui_8e905fad1013',
    invalid_sender: 'ui_f96ad877aad1',
    missing_subject: 'ui_0fc155872c96',
    invalid_email: 'ui_8a85a235d9ac',
    missing_redirect: 'ui_40c414d50c39',
    database_finisher_review: 'ui_4551ca2a37cb',
    page_reference_review: 'ui_61951694e361',
    reference_review: 'ui_ebb8cc189391',
    unsafe_import_option: 'ui_e5a0f08c92c2',
    secret_review: 'ui_1cd2b4bc2090',
    project_reference_review: 'ui_2742276525ff',
    restricted_finisher: 'ui_770660f054e3',
    recipient_review: 'ui_5720b523333c',
    invalid_identifier: 'ui_64358b7a8780',
    html_sanitized: 'ui_6a5447a0bdbe',
};
export function renderFindings(container, findings, de, editor = '') {
    container.replaceChildren();
    if (!findings.length) { container.append(element('p', translate('ui_66d1aaf218aa'), 'fmp-success')); return; }
    const list = element('ul', undefined, 'fmp-findings');
    for (const finding of findings) {
        const item = element('li', undefined, 'fmp-finding fmp-finding-' + finding.severity);
        const severity = {error: ['Fehler', 'Error'], warning: ['Prüfen', 'Review'], info: ['Hinweis', 'Note']}[finding.severity] || ['Hinweis', 'Note'];
        item.append(element('span', severity[de ? 0 : 1], 'fmp-severity'));
        item.append(element('strong', translate(findingMessages[finding.code] || finding.code)));
        item.append(element('code', finding.path));
        if (finding.element) item.append(element('small', (translate('ui_c82a43fc033e')) + finding.element));
        if (editor) {
            const link = element('a', translate('ui_7c7623328341'));
            const url = new URL(editor, location.origin);
            if (finding.element) url.searchParams.set('fmpFocus', finding.element);
            link.href = url; item.append(link);
        }
        list.append(item);
    }
    container.append(list);
}
