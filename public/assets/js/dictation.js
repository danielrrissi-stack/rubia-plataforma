/*
 * Ditado por voz (reconhecimento de voz do navegador): botao de microfone em todo <textarea> (exceto data-sem-ditado)
 * e nos <input type="text" data-ditado> de texto livre. Sem suporte (Firefox), nada aparece.
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
  function apply(current, spoken, max, singleLine) {
    current = current || '';
    var piece = punctuate(spoken || '');
    if (singleLine) { piece = piece.replace(/\n/g, ' ').replace(/ {2,}/g, ' ').replace(/^ +| +$/g, ''); }
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

  /** Recebe microfone? {tag, type, ditado, semDitado, readOnly, disabled} */
  function qualifies(o) {
    if (o.readOnly || o.disabled) { return false; }
    if (o.tag === 'TEXTAREA') { return !o.semDitado; }
    if (o.tag === 'INPUT') { return !!o.ditado && (o.type === 'text' || !o.type); }
    return false;
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { apply: apply, qualifies: qualifies };
  }
  if (typeof window === 'undefined' || typeof document === 'undefined') { return; }

  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) { return; }

  var NOTICE = 'O ditado usa o reconhecimento de voz do navegador: o áudio vai para o Google (Chrome), a Microsoft (Edge) ou a Apple (Safari). Revise o texto antes de salvar.';
  var fields = Array.prototype.filter.call(document.querySelectorAll('textarea, input[data-ditado]'), function (el) {
    return qualifies({
      tag: el.tagName, type: (el.getAttribute('type') || '').toLowerCase(), ditado: el.hasAttribute('data-ditado'),
      semDitado: el.hasAttribute('data-sem-ditado'), readOnly: el.readOnly, disabled: el.disabled
    });
  });
  if (fields.length === 0) { return; }

  var first = fields[0];
  var anchor = first.form || first;
  var notice = document.createElement('p');
  notice.className = 'ajuda ditado-aviso';
  notice.textContent = NOTICE;
  anchor.parentNode.insertBefore(notice, anchor);

  var active = null;

  function setIdle(btn) {
    btn.textContent = '🎤';
    btn.setAttribute('aria-label', 'Ditar por voz');
    btn.title = 'Ditar por voz';
    btn.setAttribute('aria-pressed', 'false');
  }

  function start(btn, field, status) {
    var rec = new SR();
    rec.lang = 'pt-BR';
    rec.continuous = true;
    rec.interimResults = true;
    var session = { btn: btn, rec: rec, error: false, done: {} };
    active = session;
    var max = parseInt(field.getAttribute('maxlength') || '0', 10);
    var single = field.tagName === 'INPUT';

    rec.onresult = function (ev) {
      var interim = '';
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        var r = ev.results[i];
        if (r.isFinal) {
          if (session.done[i]) { continue; } // alguns navegadores repetem resultados ja entregues
          session.done[i] = true;
          field.value = apply(field.value, r[0].transcript, max, single);
          field.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
          interim += r[0].transcript;
        }
      }
      status.textContent = interim ? 'ouvindo… ' + interim : 'ouvindo…';
    };
    rec.onerror = function (ev) {
      if (ev.error === 'no-speech' || ev.error === 'aborted') { return; }
      session.error = true;
      status.textContent = (ev.error === 'not-allowed' || ev.error === 'service-not-allowed')
        ? 'Microfone bloqueado — libere o acesso nas configurações do navegador.'
        : 'Não foi possível ditar agora.';
    };
    rec.onend = function () {
      setIdle(btn);
      if (!session.error) { status.textContent = ''; }
      if (active === session) { active = null; }
    };

    btn.textContent = '■';
    btn.setAttribute('aria-label', 'Parar ditado');
    btn.title = 'Parar ditado';
    btn.setAttribute('aria-pressed', 'true');
    status.textContent = 'ouvindo…';
    try {
      rec.start();
    } catch (e) {
      session.error = true;
      setIdle(btn);
      status.textContent = 'Não foi possível ditar agora.';
      active = null;
    }
  }

  fields.forEach(function (field) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-ditar';
    setIdle(btn);
    var status = document.createElement('span');
    status.className = 'ditado-status';
    status.setAttribute('aria-live', 'polite');
    field.insertAdjacentElement('afterend', btn);
    btn.insertAdjacentElement('afterend', status);
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
      start(btn, field, status);
    });
  });
})();
