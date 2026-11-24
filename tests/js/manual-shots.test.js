'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { totp, viewport, groupByLogin } = require('../../tools/manual_shots_lib.js');

test('codigo de seguranca igual ao App\Auth\Totp::code (RFC 6238, T=59)', () => {
  assert.strictEqual(totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59), '287082');
});

test('larguras de computador e celular', () => {
  assert.deepStrictEqual(viewport('desktop'), { width: 1280, height: 800 });
  assert.deepStrictEqual(viewport('mobile'), { width: 390, height: 844 });
});

test('agrupa por login preservando a ordem', () => {
  const list = [{ file: 'a', as: 'medico' }, { file: 'b', as: null }, { file: 'c', as: 'medico' }];
  const g = groupByLogin(list);
  assert.deepStrictEqual([...g.keys()], ['medico', null]);
  assert.deepStrictEqual(g.get('medico').map((x) => x.file), ['a', 'c']);
});
