'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { tickStep, tickLabel, ageText, spread, wrap } = require('../../public/assets/js/growth-bands.js');

test('marcas da idade como no modelo (5, 4, 8, 6, ...)', () => {
  assert.strictEqual(tickStep(60, 120), 4);
  assert.deepStrictEqual([60, 64, 68, 72].map(tickLabel), ['5', '4', '8', '6']);
  assert.strictEqual(tickStep(0, 24), 2);
  assert.strictEqual(tickStep(24, 60), 4);
  assert.strictEqual(tickStep(120, 228), 6);
});

test('todas as marcas terminam no fim da faixa', () => {
  [[0, 24], [24, 60], [60, 120], [120, 228]].forEach(([a, b]) => assert.strictEqual((b - a) % tickStep(a, b), 0));
});

test('idade por extenso', () => {
  assert.strictEqual(ageText(64.5), '5 anos e 4 meses');
  assert.strictEqual(ageText(12), '1 ano');
  assert.strictEqual(ageText(13.2), '1 ano e 1 mês');
  assert.strictEqual(ageText(0.4), '0 meses');
});

test('rotulos nao se sobrepoem e ficam dentro do grafico', () => {
  const p = spread([100, 102, 104, 300], [32, 18, 18, 32], 0, 310);
  for (let i = 1; i < p.length; i++) { assert.ok(p[i] - p[i - 1] >= ([32, 18, 18, 32][i - 1] + [32, 18, 18, 32][i]) / 2 - 1e-9); }
  assert.ok(p[0] - 16 >= 0 && p[3] + 16 <= 310);
});

test('rotulos espremidos no fundo sobem', () => {
  const p = spread([50, 190, 195], [18, 18, 32], 0, 200);
  assert.deepStrictEqual(p, [50, 159, 184]);
});

test('rotulo longo quebra em duas linhas', () => {
  assert.deepStrictEqual(wrap('Magreza acentuada'), ['Magreza', 'acentuada']);
  assert.deepStrictEqual(wrap('Risco de sobrepeso'), ['Risco de', 'sobrepeso']);
  assert.deepStrictEqual(wrap('Adequado'), ['Adequado']);
});
