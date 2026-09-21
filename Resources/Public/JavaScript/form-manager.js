/* Form Manager Plus — keep Core action nodes and their event handlers intact. */
(() => {
    'use strict';
    function selectRows(rows, search, category, field, direction, collator) {
        const term = search.trim().toLocaleLowerCase();
        return rows.filter(row =>
            (!category || 'group:' + row.dataset.group === category) &&
            [row.dataset.uid, row.dataset.name, row.dataset.location, row.dataset.group].some(value => value.toLocaleLowerCase().includes(term))
        ).sort((a, b) => collator.compare(a.dataset.group, b.dataset.group) || direction * (
            ['uid', 'references'].includes(field)
                ? Number(a.dataset[field]) - Number(b.dataset[field])
                : collator.compare(a.dataset[field], b.dataset[field])
        ));
    }
    // Node's built-in runner can test the same selection logic without a browser.
    if (typeof module !== 'undefined' && module.exports) module.exports = {selectRows};
    if (typeof document === 'undefined') return;
    function initialize(root) {
        if (root.dataset.initialized) return;
        root.dataset.initialized = 'true';
        const body = root.querySelector('tbody');
        const rows = [...body.querySelectorAll('[data-form]')];
        const search = root.querySelector('[data-search]');
        const length = root.querySelector('[data-length]');
        const category = root.querySelector('[data-category]');
        const summary = root.querySelector('[data-summary]');
        const previous = root.querySelector('[data-previous]');
        const next = root.querySelector('[data-next]');
        const collator = new Intl.Collator(document.documentElement.lang || undefined, {numeric: true, sensitivity: 'base'});
        let field = 'name';
        let direction = 1;
        let page = 0;
        // Persist display preferences only; never store form titles or search queries.
        const key = 'form-manager-plus:ui:' + location.pathname;
        try {
            const saved = JSON.parse(sessionStorage.getItem(key) || '{}');
            if ([10, 25, 50, 100, 250].includes(saved.length)) length.value = String(saved.length);
            if (['uid', 'name', 'location', 'references'].includes(saved.field)) field = saved.field;
            direction = saved.direction === -1 ? -1 : 1;
        } catch { /* Storage can be disabled by browser policy. */ }
        const groups = [...new Set(rows.map(row => row.dataset.group))].sort(collator.compare);
        for (const group of groups) {
            const option = document.createElement('option');
            // An explicit sentinel distinguishes uncategorized forms from all categories.
            option.value = 'group:' + group;
            option.textContent = group || root.dataset.ungrouped;
            category.append(option);
        }
        function draw() {
            const filtered = selectRows(rows, search.value, category.value, field, direction, collator);
            const size = Number(length.value);
            const pages = Math.max(1, Math.ceil(filtered.length / size));
            page = Math.min(page, pages - 1);
            body.querySelectorAll('[data-generated]').forEach(row => row.remove());
            rows.forEach(row => { row.hidden = true; });
            let lastGroup = null;
            for (const row of filtered.slice(page * size, (page + 1) * size)) {
                if (row.dataset.group !== lastGroup) {
                    const groupRow = document.createElement('tr');
                    groupRow.dataset.generated = '';
                    groupRow.className = 'fmp-group';
                    const heading = document.createElement('th');
                    heading.colSpan = 5;
                    heading.textContent = row.dataset.group || root.dataset.ungrouped;
                    groupRow.append(heading);
                    body.append(groupRow);
                    lastGroup = row.dataset.group;
                }
                row.hidden = false;
                body.append(row);
            }
            if (!filtered.length) {
                const row = document.createElement('tr');
                row.dataset.generated = '';
                const cell = row.insertCell();
                cell.colSpan = 5;
                cell.textContent = root.dataset.empty;
                body.append(row);
            }
            summary.textContent = `${filtered.length} ${root.dataset.total} · ${root.dataset.page} ${page + 1} ${root.dataset.of} ${pages}`;
            previous.disabled = page === 0;
            next.disabled = page >= pages - 1;
            root.querySelectorAll('[data-sort]').forEach(button => {
                const active = button.dataset.sort === field;
                button.closest('th').setAttribute('aria-sort', active ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                button.querySelector('span').textContent = active ? (direction === 1 ? '↑' : '↓') : '↕';
            });
            try { sessionStorage.setItem(key, JSON.stringify({length: size, field, direction})); } catch { /* Optional persistence. */ }
        }
        search.addEventListener('input', () => { page = 0; draw(); });
        length.addEventListener('change', () => { page = 0; draw(); });
        category.addEventListener('change', () => { page = 0; draw(); });
        previous.addEventListener('click', () => { page = Math.max(0, page - 1); draw(); });
        next.addEventListener('click', () => { page++; draw(); });
        root.querySelector('[data-reset]').addEventListener('click', () => {
            search.value = ''; category.value = ''; length.value = '25'; field = 'name'; direction = 1; page = 0; draw();
        });
        root.querySelectorAll('[data-sort]').forEach(button => button.addEventListener('click', () => {
            direction = field === button.dataset.sort ? -direction : 1;
            field = button.dataset.sort; page = 0; draw();
        }));
        root.querySelector('[data-controls]').hidden = false;
        root.querySelector('[data-pagination]').hidden = false;
        draw();
    }
    const start = () => document.querySelectorAll('[data-fmp]').forEach(initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
