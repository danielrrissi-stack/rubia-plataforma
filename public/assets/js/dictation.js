/*
 * Ditado por voz nos campos com botao [data-dictate="<id do textarea>"] (reconhecimento de voz do navegador).
 * Sem suporte (Firefox), botoes e aviso continuam ocultos e o formulario funciona como antes.
 * Nada e enviado ao servidor: o texto so entra no campo e e salvo pelo envio normal do formulario.
 */
(function () {
  'use strict';

  // Pontuacao falada (mais longas primeiro, para "ponto e virgula" nao virar "." + "e virgula").
  var COMMANDS = [
    ['ponto de interrogação', '?'], ['ponto e vírgula', ';'], ['ponto final', '.'], ['dois pontos', ':'],
    ['nova linha', '\n'], ['próxima linha', '\n'], ['parágrafo', '\n'],
    ['interrogação', '?'], ['vírgula', ','], ['ponto', '.']
  ];

  function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function punctuate(spoken) {
    var t = ' ' + spoken + ' ';
    COMMANDS.forEach(function (c) {
      var re = new RegExp('(\\s)' + escapeRe(c[0]) + '(?=\\s)', 'giu');
      t = t.replace(re, c[1] === '\n' ? '\n' : ' ' + c[1]);
    });
    return t
      .replace(/[ \t]+([,.;:?])/g, '$1')
      .replace(/[ \t]*\n[ \t]*/g, '\n')
      .replace(/[ \t]{2,}/g, ' ')
      .replace(/^[ \t]+|[ \t]+$/g, '');
  }

  /** Texto do campo depois de acrescentar o trecho ditado. */
  function apply(current, spoken, max) {
    current = current || '';
    var piece = punctuate(spoken || '');
    if (piece === '') { return current; }
    var sep = '';
    if (current !== '' && !/\s$/.test(current) && !/^[\n,.;:?]/.test(piece)) { sep = ' '; }
    var out = current + sep + piece;
    var chars = out.split('');
    for (var i = current.length; i < chars.length; i++) {
      if (!/\p{L}/u.test(chars[i])) { continue; }
      var j = i - 1;
      while (j >= 0 && (chars[j] === ' ' || chars[j] === '\t')) { j--; }
      if (j < 0 || /[.?!\n]/.test(chars[j])) { chars[i] = chars[i].toLocaleUpperCase('pt-BR'); }
    }
    out = chars.join('');
    if (typeof max === 'number' && max > 0 && out.length > max) { out = out.slice(0, max); }
    return out;
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { apply: apply };
  }
  if (typeof window === 'undefined' || typeof document === 'undefined') { return; }

  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) { return; }

  var buttons = document.querySelectorAll('[data-dictate]');
  if (buttons.length === 0) { return; }
  var notice = document.getElementById('ditado-aviso');
  if (notice) { notice.hidden = false; }

  var active = null;

  function setIdle(btn) {
    btn.textContent = '🎤 Ditar';
    btn.setAttribute('aria-pressed', 'false');
  }

  function start(btn) {
    var field = document.getElementById(btn.getAttribute('data-dictate'));
    var status = document.getElementById('ditado-' + btn.getAttribute('data-dictate'));
    if (!field) { return; }
    var rec = new SR();
    rec.lang = 'pt-BR';
    rec.continuous = true;
    rec.interimResults = true;
    var session = { btn: btn, rec: rec, error: false, done: {} };
    active = session;
    var max = parseInt(field.getAttribute('maxlength') || '0', 10);

    rec.onresult = function (ev) {
      var interim = '';
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        var r = ev.results[i];
        if (r.isFinal) {
          if (session.done[i]) { continue; } // alguns navegadores repetem resultados ja entregues
          session.done[i] = true;
          field.value = apply(field.value, r[0].transcript, max);
          field.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          interim += r[0].transcript;
        }
      }
      if (status) { status.textContent = interim ? 'ouvindo… ' + interim : 'ouvindo…'; }
    };
    rec.onerror = function (ev) {
      if (ev.error === 'no-speech' || ev.error === 'aborted') { return; }
      session.error = true;
      if (status) {
        status.textContent = (ev.error === 'not-allowed' || ev.error === 'service-not-allowed')
          ? 'Microfone bloqueado — libere o acesso nas configurações do navegador.'
          : 'Não foi possível ditar agora.';
      }
    };
    rec.onend = function () {
      setIdle(btn);
      if (status && !session.error) { status.textContent = ''; }
      if (active === session) { active = null; }
    };

    btn.textContent = '■ Parar';
    btn.setAttribute('aria-pressed', 'true');
    if (status) { status.textContent = 'ouvindo…'; }
    try {
      rec.start();
    } catch (e) {
      session.error = true;
      setIdle(btn);
      if (status) { status.textContent = 'Não foi possível ditar agora.'; }
      active = null;
    }
  }

  Array.prototype.forEach.call(buttons, function (btn) {
    btn.hidden = false;
    btn.addEventListener('click', function () {
      if (active && active.btn === btn) {
        active.rec.stop();
        return;
      }
      if (active) {
        var prev = active;
        active = null;
        prev.rec.stop(); // o anterior termina de entregar o que ja ouviu
      }
      start(btn);
    });
  });
})();
