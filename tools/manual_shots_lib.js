'use strict';
/* Funcoes puras do roteiro de fotos do manual (tools/manual_shots.mjs); testadas em tests/js/manual-shots.test.js. */
const crypto = require('node:crypto');

function base32Decode(s) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const ch of String(s).toUpperCase().replace(/[^A-Z2-7]/g, '')) {
    bits += A.indexOf(ch).toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) { bytes.push(parseInt(bits.slice(i, i + 8), 2)); }
  return Buffer.from(bytes);
}

/** Codigo de 6 digitos (SHA-1, passo de 30 s), igual a App\Auth\Totp::code. */
function totp(secretB32, unixSeconds) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(unixSeconds / 30)));
  const h = crypto.createHmac('sha1', base32Decode(secretB32)).update(counter).digest();
  const o = h[h.length - 1] & 0x0f;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1000000).padStart(6, '0');
}

function viewport(width) {
  return width === 'mobile' ? { width: 390, height: 844 } : { width: 1280, height: 800 };
}

/** Map login -> lista de fotos, na ordem em que cada login aparece. */
function groupByLogin(list) {
  const g = new Map();
  for (const item of list) {
    if (!g.has(item.as)) { g.set(item.as, []); }
    g.get(item.as).push(item);
  }
  return g;
}

module.exports = { totp, viewport, groupByLogin, base32Decode };
