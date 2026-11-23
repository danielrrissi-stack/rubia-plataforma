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

const { fitRange } = require('../../public/assets/js/evolution-chart.js');

test('eixo se ajusta aos dados quando o periodo e maior que o historico', () => {
  const to = day('2026-11-22');
  const r = fitRange(day('2026-05-22'), to, [day('2026-11-01'), day('2026-11-15')]);
  assert.strictEqual(r.max, to);
  assert.ok(r.min < day('2026-11-01'), 'folga antes do primeiro dado');
  assert.ok(r.min >= day('2026-10-25'), 'sem vazio grande a esquerda: ' + r.min);
});

test('nunca comeca antes do inicio do periodo', () => {
  const r = fitRange(day('2026-05-22'), day('2026-11-22'), [day('2026-01-01')]);
  assert.strictEqual(r.min, day('2026-05-22'));
});

test('sem periodo usa o primeiro dado com folga', () => {
  const r = fitRange(null, day('2026-11-22'), [day('2025-11-22')]);
  assert.ok(r.min < day('2025-11-22') && r.min > day('2025-10-01'));
});

test('dados de um so dia ganham janela minima de 14 dias', () => {
  const to = day('2026-11-22');
  const r = fitRange(day('2026-05-22'), to, [to]);
  assert.strictEqual(r.max - r.min, 14);
});

test('sem dados mostra os ultimos 30 dias', () => {
  const to = day('2026-11-22');
  assert.deepStrictEqual(fitRange(null, to, []), { min: to - 30, max: to });
});
