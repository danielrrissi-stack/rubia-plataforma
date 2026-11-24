'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { dayLabel, offTarget } = require('../../public/assets/js/plan-days.js');

test('rotulo do dia: dia da semana e dd/mm', () => {
  assert.strictEqual(dayLabel('2026-11-23', 0), 'Seg 23/11');
  assert.strictEqual(dayLabel('2026-11-23', 6), 'Dom 29/11');
});

test('rotulo do dia vira o ano sem erro de fuso', () => {
  assert.strictEqual(dayLabel('2026-12-28', 5), 'Sáb 02/01');
});

test('data invalida nao quebra', () => {
  assert.strictEqual(dayLabel('xx', 0), 'Dia 1');
});

test('fora da meta: mais de 10 %', () => {
  assert.strictEqual(offTarget(1650, 1500), false);
  assert.strictEqual(offTarget(1651, 1500), true);
  assert.strictEqual(offTarget(1350, 1500), false);
  assert.strictEqual(offTarget(1349, 1500), true);
});

test('sem meta nunca esta fora', () => {
  assert.strictEqual(offTarget(3000, null), false);
  assert.strictEqual(offTarget(3000, 0), false);
});
