const {test} = require('node:test');
const assert = require('node:assert/strict');
const {selectRows} = require('../../../Resources/Public/JavaScript/form-manager.js');
const collator = new Intl.Collator('de', {numeric: true, sensitivity: 'base'});
const row = (uid, name, group = '', references = 0) => ({dataset: {uid: String(uid), name, group, references: String(references), location: `1:/forms/${name}.form.yaml`}});
const select = (rows, term = '', group = '', field = 'name', direction = 1) => selectRows(rows, term, group, field, direction, collator);
test('search matches name, file UID, path and category regardless of case', () => {
    const rows = [row(12, 'Kontakt', 'Marketing'), row(3, 'Feedback')];
    for (const term of ['KONTAKT', '12', 'marketing', 'forms/Kontakt']) assert.deepEqual(select(rows, term), [rows[0]]);
});
test('all categories and uncategorized are distinct filters', () => {
    const rows = [row(12, 'Kontakt', 'Marketing'), row(3, 'Feedback')];
    assert.equal(select(rows).length, 2);
    assert.deepEqual(select(rows, '', 'group:'), [rows[1]]);
    assert.deepEqual(select(rows, '', 'group:Marketing'), [rows[0]]);
    assert.deepEqual(select(rows, '', 'group:Unknown'), []);
});
test('numeric sorts stay numeric while category groups stay contiguous', () => {
    const rows = [row(100, 'A', 'B', 12), row(2, 'B', 'B', 2), row(8, 'C', 'A', 3)];
    assert.deepEqual(select(rows, '', '', 'uid'), [rows[2], rows[1], rows[0]]);
    assert.deepEqual(select(rows, '', '', 'references', -1), [rows[2], rows[0], rows[1]]);
});
test('selection preserves DOM-node identity and does not mutate input ordering', () => {
    const rows = [row(1, 'Z'), row(2, 'A')];
    rows[0].coreEventHandler = () => 'kept';
    const result = select(rows);
    assert.equal(result[1], rows[0]);
    assert.equal(result[1].coreEventHandler(), 'kept');
    assert.equal(rows[0].dataset.name, 'Z');
});
test('empty and markup-looking labels are treated as literal searchable data', () => {
    const rows = [row(1, '<img src=x onerror=alert(1)>')];
    assert.deepEqual(select(rows, '<img'), rows);
    assert.deepEqual(select(rows, 'missing'), []);
    assert.deepEqual(select([]), []);
});
