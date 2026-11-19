'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { apply, qualifies } = require('../../public/assets/js/dictation.js');

test('pontuacao falada e maiuscula no inicio', () => {
  assert.strictEqual(apply('', 'paciente refere dor vírgula melhora ponto'), 'Paciente refere dor, melhora.');
});

test('nova linha e maiuscula depois dela', () => {
  assert.strictEqual(apply('Texto.', 'nova linha conduta mantida'), 'Texto.\nConduta mantida');
});

test('acrescenta com espaco sem mudar o texto existente', () => {
  assert.strictEqual(apply('abc', 'def'), 'abc def');
});

test('ponto e virgula e interrogacao', () => {
  assert.strictEqual(apply('a', 'ponto e vírgula b interrogação'), 'a; b?');
});

test('dois pontos', () => {
  assert.strictEqual(apply('x', 'dois pontos y'), 'x: y');
});

test('maiuscula depois de ponto ja existente', () => {
  assert.strictEqual(apply('Peso estável.', 'mantém dieta'), 'Peso estável. Mantém dieta');
});

test('ponto final e paragrafo', () => {
  assert.strictEqual(apply('', 'sem queixas ponto final parágrafo retorno em 30 dias'), 'Sem queixas.\nRetorno em 30 dias');
});

test('respeita o limite de caracteres', () => {
  assert.strictEqual(apply('abc', 'defgh', 6), 'abc de');
});

test('campo de uma linha troca nova linha por espaco', () => {
  assert.strictEqual(apply('Retorno', 'nova linha em 30 dias', 0, true), 'Retorno em 30 dias');
});

test('quais campos recebem o microfone', () => {
  assert.strictEqual(qualifies({ tag: 'TEXTAREA' }), true);
  assert.strictEqual(qualifies({ tag: 'TEXTAREA', semDitado: true }), false);
  assert.strictEqual(qualifies({ tag: 'TEXTAREA', readOnly: true }), false);
  assert.strictEqual(qualifies({ tag: 'TEXTAREA', disabled: true }), false);
  assert.strictEqual(qualifies({ tag: 'INPUT', type: 'text' }), false);
  assert.strictEqual(qualifies({ tag: 'INPUT', type: 'text', ditado: true }), true);
  assert.strictEqual(qualifies({ tag: 'INPUT', type: 'email', ditado: true }), false);
});
