'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { bandRows, day } = require('../../public/assets/js/evolution-chart.js');

const meds = [
  { name: 'Tirzepatida', segments: [
    { from: '2026-01-01', to: '2026-01-31', dose: '2,5 mg' },
    { from: '2026-02-01', to: null, dose: '5 mg' }
  ] },
  { name: 'Antigo', segments: [{ from: '2025-01-01', to: '2025-02-01', dose: '1 cp' }] }
];

test('dia em numero UTC', () => {
  assert.strictEqual(day('1970-01-02'), 1);
});

test('recorta o trecho que comeca antes do periodo e leva o vigente ate o fim', () => {
  const rows = bandRows(meds, day('2026-01-15'), day('2026-03-01'));
  assert.strictEqual(rows.length, 1);
  assert.strictEqual(rows[0].name, 'Tirzepatida');
  assert.deepStrictEqual(rows[0].bars, [
    { x0: day('2026-01-15'), x1: day('2026-01-31') + 1, label: '2,5 mg' },
    { x0: day('2026-02-01'), x1: day('2026-03-01') + 1, label: '5 mg' }
  ]);
});

test('trecho inteiro fora do periodo some', () => {
  const rows = bandRows([{ name: 'X', segments: [{ from: '2026-05-01', to: '2026-05-10', dose: 'a' }] }], day('2026-01-01'), day('2026-03-01'));
  assert.deepStrictEqual(rows, []);
});
