// Editor do plano alimentar estruturado: refeicoes > opcoes > itens (+ substitutos), busca na TACO e totais ao vivo.
// Calculo identico ao MealPlanCalculator (PHP); depois de cada salvamento valem os totais devolvidos pelo servidor.
(function () {
  'use strict';
  var root = document.getElementById('plano-editor');
  if (!root) return;

  var KEYS = ['kcal', 'protein', 'carb', 'fat', 'fiber'];
  var MAX_MEALS = 12, MAX_OPTIONS = 5, MAX_ITEMS = 30, MAX_SUBS = 10;
  var data = {};
  try { data = JSON.parse(root.getAttribute('data-plano') || '{}') || {}; } catch (e) { data = {}; }
  var metaRaw = root.getAttribute('data-meta-kcal') || '';
  var meta = /^\d+(\.\d+)?$/.test(metaRaw) ? parseFloat(metaRaw) : null;
  var searchUrl = root.getAttribute('data-busca-url') || '';
  var saveUrl = root.getAttribute('data-salvar-url') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var revision = root.getAttribute('data-revisao') || '';

  var foods = {}; // catalogo id -> {id, name, per100, measures}
  (Array.isArray(data.foods) ? data.foods : []).forEach(addFood);

  function addFood(f) {
    if (f && typeof f.id === 'number') {
      foods[f.id] = { id: f.id, name: String(f.name || ''), per100: f.per100 || {}, measures: Array.isArray(f.measures) ? f.measures : [] };
    }
  }

  function fmtQty(n) { return typeof n === 'number' && isFinite(n) && n > 0 ? String(n).replace('.', ',') : ''; }
  function line(l) {
    l = l || {};
    return { food_id: l.food_id || null, food_name: String(l.food_name || ''), qty: fmtQty(l.qty), measure_id: l.measure_id || null };
  }
  var meals = (Array.isArray(data.meals) ? data.meals : []).map(function (m) {
    var options = (Array.isArray(m.options) ? m.options : []).map(function (o) {
      return { items: (Array.isArray(o.items) ? o.items : []).map(function (it) {
        var x = line(it);
        x.note = String(it.note || '');
        x.subs = (Array.isArray(it.subs) ? it.subs : []).map(line);
        return x;
      }) };
    });
    if (options.length === 0) options.push({ items: [] });
    return { name: String(m.name || ''), time: String(m.time || ''), note: String(m.note || ''), options: options };
  });

  var list = document.getElementById('pe-refeicoes');
  var titleEl = document.getElementById('pe-titulo');
  var guidanceEl = document.getElementById('pe-orientacoes');
  var statusEl = document.getElementById('pe-status');
  var errorsBox = document.getElementById('pe-erros');
  var conflictBox = document.getElementById('pe-conflito');
  var changes = 0, savedChanges = 0, saving = false;

  // ---------- calculo (mesmas formulas do MealPlanCalculator) ----------
  function parseQty(s) {
    s = String(s == null ? '' : s).trim();
    if (/^\d+([.,]\d+)?$/.test(s)) return parseFloat(s.replace(',', '.'));
    if (/^\d{1,3}(\.\d{3})*,\d+$/.test(s)) return parseFloat(s.replace(/\./g, '').replace(',', '.'));
    return NaN;
  }
  function qtyOk(v) { return isFinite(v) && v > 0 && v <= 5000; }
  function measureOf(l) {
    var f = foods[l.food_id];
    if (!f || !l.measure_id) return null;
    for (var i = 0; i < f.measures.length; i++) if (f.measures[i].id === l.measure_id) return f.measures[i];
    return null;
  }
  function lineTotals(l) {
    var out = { kcal: 0, protein: 0, carb: 0, fat: 0, fiber: 0 };
    var f = foods[l.food_id];
    var q = parseQty(l.qty);
    if (!f || !qtyOk(q)) return out;
    var m = measureOf(l);
    var grams = Math.round(q * (m ? Number(m.grams) : 1) * 10000) / 10000; // PHP: round(qty * grams, 4)
    KEYS.forEach(function (k) {
      var v = f.per100[k];
      out[k] = v == null ? 0 : Number(v) * grams / 100;
    });
    return out;
  }
  function optionTotals(o) {
    var sum = { kcal: 0, protein: 0, carb: 0, fat: 0, fiber: 0 };
    o.items.forEach(function (it) {
      var t = lineTotals(it);
      KEYS.forEach(function (k) { sum[k] += t[k]; });
    });
    return sum;
  }
  function dayTotals() {
    var sum = { kcal: 0, protein: 0, carb: 0, fat: 0, fiber: 0 };
    meals.forEach(function (m) {
      if (!m.options.length) return;
      var t = optionTotals(m.options[0]);
      KEYS.forEach(function (k) { sum[k] += t[k]; });
    });
    return sum;
  }
  function round1(x) { return Math.round(x * 10) / 10; }
  function percents(t) {
    var p = t.protein * 4, c = t.carb * 4, f = t.fat * 9, base = p + c + f;
    if (base <= 0) return { protein: 0, carb: 0, fat: 0 };
    return { protein: round1(p / base * 100), carb: round1(c / base * 100), fat: round1(f / base * 100) };
  }
  function fmt(n, dec) {
    n = Number(n);
    if (!isFinite(n)) n = 0;
    return n.toFixed(dec).replace('.', ',');
  }

  // ---------- totais na tela ----------
  function showDay(t, pct) {
    root.querySelectorAll('[data-total]').forEach(function (s) {
      var k = s.getAttribute('data-total');
      s.textContent = fmt(t[k], k === 'kcal' ? 0 : 1);
    });
    root.querySelectorAll('[data-pct]').forEach(function (s) { s.textContent = fmt(pct[s.getAttribute('data-pct')], 1); });
    var diffEl = document.getElementById('pe-diferenca');
    var bar = document.getElementById('pe-barra');
    if (meta && meta > 0) {
      var diff = t.kcal - meta;
      var ratio = t.kcal / meta * 100;
      if (diffEl) diffEl.textContent = 'diferença ' + (diff > 0 ? '+' : '') + fmt(diff, 0) + ' kcal (' + fmt(ratio, 0) + '% da meta)';
      if (bar) {
        bar.style.width = Math.max(0, Math.min(100, ratio)) + '%';
        bar.classList.toggle('pe-barra-acima', ratio > 110);
      }
    }
  }
  function optionLabel(t) {
    return fmt(t.kcal, 0) + ' kcal · P ' + fmt(t.protein, 1) + ' g · C ' + fmt(t.carb, 1) + ' g · G ' + fmt(t.fat, 1) + ' g';
  }
  function showOption(mi, oi, t) {
    var s = list.querySelector('[data-opt-total="' + mi + '.' + oi + '"]');
    if (s) s.textContent = optionLabel(t);
  }
  function refreshTotals() {
    var day = dayTotals();
    showDay(day, percents(day));
    meals.forEach(function (m, mi) {
      m.options.forEach(function (o, oi) {
        showOption(mi, oi, optionTotals(o));
        o.items.forEach(function (it, ii) {
          var itemKcal = lineTotals(it).kcal;
          var s = list.querySelector('[data-line-kcal="' + mi + '.' + oi + '.' + ii + '"]');
          if (s) s.textContent = it.food_id ? fmt(itemKcal, 0) + ' kcal' : '';
          it.subs.forEach(function (sub, si) {
            var ss = list.querySelector('[data-line-kcal="' + mi + '.' + oi + '.' + ii + '.' + si + '"]');
            if (ss) ss.textContent = sub.food_id ? fmt(lineTotals(sub).kcal, 0) + ' kcal (item: ' + fmt(itemKcal, 0) + ' kcal)' : '';
          });
        });
      });
    });
  }
  function changed() {
    changes++;
    refreshTotals();
  }

  // ---------- montagem do DOM (sempre textContent, nunca HTML do usuario) ----------
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (attrs[k] === null || attrs[k] === undefined || attrs[k] === false) return;
      if (k === 'text') n.textContent = attrs[k];
      else n.setAttribute(k, attrs[k] === true ? '' : String(attrs[k]));
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function labeled(text, control, cls) {
    return el('label', { 'class': cls || 'pe-campo' }, [el('span', { text: text }), control]);
  }
  function btn(text, act, path, extra) {
    var a = { type: 'button', 'class': 'btn btn-quiet pe-btn', 'data-act': act, 'data-path': path, text: text };
    Object.keys(extra || {}).forEach(function (k) { a[k] = extra[k]; });
    return el('button', a);
  }
  function input(field, path, value, extra) {
    var a = { 'data-field': field, 'data-path': path, value: value };
    Object.keys(extra || {}).forEach(function (k) { a[k] = extra[k]; });
    var n = el('input', a);
    n.value = value;
    return n;
  }

  function lineRow(l, path, isSub, mealName) {
    var f = foods[l.food_id];
    var what = isSub ? 'Substituto' : 'Alimento';
    var search = input('busca', path, l.food_name || (f ? f.name : ''), {
      type: 'search', autocomplete: 'off', placeholder: 'Digite 2 letras ou mais', 'aria-autocomplete': 'list',
      'aria-describedby': 'pe-ajuda-busca', 'class': 'pe-busca'
    });
    var results = el('ul', { 'class': 'pe-resultados', 'data-results': path, hidden: true });
    var sel = el('select', { 'data-field': 'measure_id', 'data-path': path });
    sel.appendChild(el('option', { value: '', text: 'g (gramas)' }));
    (f ? f.measures : []).forEach(function (m) {
      var o = el('option', { value: m.id, text: m.name + ' (' + fmt(m.grams, 1) + ' g)' });
      if (m.id === l.measure_id) o.selected = true;
      sel.appendChild(o);
    });
    var qty = input('qty', path, l.qty, { inputmode: 'decimal', 'class': 'pe-qtd', autocomplete: 'off' });
    var kids = [
      el('div', { 'class': 'pe-busca-caixa' }, [labeled(what, search), results]),
      labeled('Quantidade', qty),
      labeled('Unidade', sel)
    ];
    if (!isSub) kids.push(labeled('Observação', input('note', path, l.note, { maxlength: 200, placeholder: 'ex.: sem açúcar' })));
    kids.push(el('span', { 'class': 'pe-kcal', 'data-line-kcal': path }));
    if (!isSub) kids.push(btn('+ substituto', 'add-sub', path));
    kids.push(btn(isSub ? 'Remover substituto' : 'Remover item', isSub ? 'del-sub' : 'del-item', path,
      { 'aria-label': (isSub ? 'Remover substituto de ' : 'Remover item de ') + mealName }));
    return el('div', { 'class': isSub ? 'pe-linha pe-sub' : 'pe-linha' }, kids);
  }

  function render() {
    list.textContent = '';
    if (meals.length === 0) list.appendChild(el('p', { 'class': 'ajuda', text: 'Nenhuma refeição. Use "Adicionar refeição".' }));
    meals.forEach(function (m, mi) {
      var label = m.name || ('Refeição ' + (mi + 1));
      var head = el('div', { 'class': 'pe-refeicao-topo' }, [
        labeled('Nome da refeição', input('name', String(mi), m.name, { maxlength: 60, required: true })),
        labeled('Horário', input('time', String(mi), m.time, { type: 'time' })),
        btn('↑', 'up', String(mi), { 'aria-label': 'Mover ' + label + ' para cima', disabled: mi === 0 }),
        btn('↓', 'down', String(mi), { 'aria-label': 'Mover ' + label + ' para baixo', disabled: mi === meals.length - 1 }),
        btn('Remover refeição', 'del-meal', String(mi), { 'aria-label': 'Remover ' + label })
      ]);
      var sec = el('section', { 'class': 'cartao pe-refeicao', 'aria-label': label }, [head,
        labeled('Observação da refeição', input('note', String(mi), m.note, { maxlength: 500, placeholder: 'ex.: fruta à vontade' }), 'pe-campo pe-campo-largo')]);
      m.options.forEach(function (o, oi) {
        var op = mi + '.' + oi;
        var box = el('div', { 'class': 'pe-opcao' }, [
          el('h3', {}, ['Opção ' + (oi + 1) + ' ', el('span', { 'class': 'ajuda', 'data-opt-total': op })])
        ]);
        if (m.options.length > 1) box.appendChild(btn('Remover opção ' + (oi + 1), 'del-opt', op));
        o.items.forEach(function (it, ii) {
          var ip = op + '.' + ii;
          var itemBox = el('div', { 'class': 'pe-item' }, [lineRow(it, ip, false, label)]);
          it.subs.forEach(function (sub, si) { itemBox.appendChild(lineRow(sub, ip + '.' + si, true, label)); });
          box.appendChild(itemBox);
        });
        if (o.items.length < MAX_ITEMS) box.appendChild(btn('+ Adicionar item', 'add-item', op));
        sec.appendChild(box);
      });
      if (m.options.length < MAX_OPTIONS) sec.appendChild(btn('Adicionar opção', 'add-opt', String(mi)));
      list.appendChild(sec);
    });
    var add = document.getElementById('pe-add-refeicao');
    if (add) add.disabled = meals.length >= MAX_MEALS;
    refreshTotals();
  }

  function rerender(focusSel) {
    render();
    if (focusSel) {
      var f = list.querySelector(focusSel);
      if (f) f.focus();
    }
  }

  // ---------- caminho "m.o.i.s" -> objeto ----------
  function parts(path) { return String(path || '').split('.').map(Number); }
  function target(path) {
    var p = parts(path);
    var m = meals[p[0]];
    if (!m) return null;
    if (p.length === 1) return m;
    var o = m.options[p[1]];
    if (!o || p.length === 2) return o || null;
    var it = o.items[p[2]];
    if (!it || p.length === 3) return it || null;
    return it.subs[p[3]] || null;
  }

  // ---------- busca na TACO ----------
  var searchTimer = null, searchSeq = 0;
  function closeResults(except) {
    list.querySelectorAll('.pe-resultados').forEach(function (u) { if (u !== except) u.hidden = true; });
  }
  function runSearch(inp) {
    var path = inp.getAttribute('data-path');
    var box = list.querySelector('[data-results="' + path + '"]');
    if (!box) return;
    var q = inp.value.trim();
    if (q.replace(/\s/g, '').length < 2) { box.hidden = true; return; }
    var seq = ++searchSeq;
    fetch(searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (seq !== searchSeq) return;
        showResults(box, j && Array.isArray(j.items) ? j.items : null);
      })
      .catch(function () { if (seq === searchSeq) showResults(box, null); });
  }
  function showResults(box, items) {
    box.textContent = '';
    if (items === null) box.appendChild(el('li', { 'class': 'ajuda', text: 'Busca indisponível no momento.' }));
    else if (items.length === 0) box.appendChild(el('li', { 'class': 'ajuda', text: 'Nenhum alimento encontrado.' }));
    (items || []).forEach(function (f) {
      addFood(f);
      var kcal = f.per100 && f.per100.kcal != null ? fmt(f.per100.kcal, 0) + ' kcal/100 g' : 'sem kcal na tabela';
      box.appendChild(el('li', {}, [el('button', {
        type: 'button', 'class': 'pe-resultado', 'data-act': 'pick', 'data-path': box.getAttribute('data-results'), 'data-food': f.id
      }, [f.name + ' ', el('span', { 'class': 'ajuda', text: kcal + (f.source === 'proprio' ? ' · próprio' : '') })])]));
    });
    closeResults(box);
    box.hidden = false;
  }
  function pick(path, foodId) {
    var l = target(path), f = foods[foodId];
    if (!l || !f) return;
    l.food_id = f.id;
    l.food_name = f.name;
    if (f.measures.length) { l.measure_id = f.measures[0].id; if (!l.qty) l.qty = '1'; }
    else { l.measure_id = null; if (!l.qty) l.qty = '100'; }
    changes++;
    rerender('[data-field="qty"][data-path="' + path + '"]');
  }

  // ---------- eventos ----------
  list.addEventListener('input', function (ev) {
    var t = ev.target, field = t.getAttribute('data-field');
    if (!field) return;
    if (field === 'busca') {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { runSearch(t); }, 300);
      return;
    }
    var obj = target(t.getAttribute('data-path'));
    if (!obj) return;
    if (field === 'measure_id') obj.measure_id = t.value === '' ? null : Number(t.value);
    else obj[field] = t.value;
    if (field === 'qty') t.classList.toggle('pe-invalido', t.value.trim() !== '' && !qtyOk(parseQty(t.value)));
    changed();
  });
  list.addEventListener('change', function (ev) {
    if (ev.target.tagName === 'SELECT') {
      var obj = target(ev.target.getAttribute('data-path'));
      if (obj) { obj.measure_id = ev.target.value === '' ? null : Number(ev.target.value); changed(); }
    }
  });
  list.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (ev.key === 'Escape') { closeResults(null); return; }
    var box = t.closest ? t.closest('.pe-busca-caixa') : null;
    if (!box || (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp')) return;
    var btns = Array.prototype.slice.call(box.querySelectorAll('.pe-resultados:not([hidden]) .pe-resultado'));
    if (!btns.length) return;
    ev.preventDefault();
    var i = btns.indexOf(t);
    if (i === -1) btns[ev.key === 'ArrowDown' ? 0 : btns.length - 1].focus();
    else if (ev.key === 'ArrowDown') btns[Math.min(btns.length - 1, i + 1)].focus();
    else if (i === 0) box.querySelector('[data-field="busca"]').focus();
    else btns[i - 1].focus();
  });
  list.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-act]') : null;
    if (!b) return;
    var act = b.getAttribute('data-act'), path = b.getAttribute('data-path'), p = parts(path);
    var m = meals[p[0]];
    if (act === 'pick') { pick(path, Number(b.getAttribute('data-food'))); return; }
    if (!m) return;
    if (act === 'up' && p[0] > 0) { meals.splice(p[0] - 1, 0, meals.splice(p[0], 1)[0]); changes++; rerender('[data-act="up"][data-path="' + (p[0] - 1) + '"]:not([disabled]), [data-act="down"][data-path="' + (p[0] - 1) + '"]'); return; }
    if (act === 'down' && p[0] < meals.length - 1) { meals.splice(p[0] + 1, 0, meals.splice(p[0], 1)[0]); changes++; rerender('[data-act="down"][data-path="' + (p[0] + 1) + '"]:not([disabled]), [data-act="up"][data-path="' + (p[0] + 1) + '"]'); return; }
    if (act === 'del-meal') {
      if (!window.confirm('Remover a refeição "' + (m.name || 'sem nome') + '"?')) return;
      meals.splice(p[0], 1); changes++; rerender('#pe-add-refeicao'); return;
    }
    if (act === 'add-opt' && m.options.length < MAX_OPTIONS) {
      m.options.push({ items: [] }); changes++;
      rerender('[data-act="add-item"][data-path="' + p[0] + '.' + (m.options.length - 1) + '"]'); return;
    }
    var o = m.options[p[1]];
    if (!o) return;
    if (act === 'del-opt' && m.options.length > 1) { m.options.splice(p[1], 1); changes++; rerender('[data-act="add-opt"][data-path="' + p[0] + '"]'); return; }
    if (act === 'add-item' && o.items.length < MAX_ITEMS) {
      o.items.push({ food_id: null, food_name: '', qty: '', measure_id: null, note: '', subs: [] }); changes++;
      rerender('[data-field="busca"][data-path="' + p[0] + '.' + p[1] + '.' + (o.items.length - 1) + '"]'); return;
    }
    var it = o.items[p[2]];
    if (!it) return;
    if (act === 'del-item') { o.items.splice(p[2], 1); changes++; rerender('[data-act="add-item"][data-path="' + p[0] + '.' + p[1] + '"]'); return; }
    if (act === 'add-sub' && it.subs.length < MAX_SUBS) {
      it.subs.push({ food_id: null, food_name: '', qty: '', measure_id: null }); changes++;
      rerender('[data-field="busca"][data-path="' + path + '.' + (it.subs.length - 1) + '"]'); return;
    }
    if (act === 'del-sub') { it.subs.splice(p[3], 1); changes++; rerender('[data-act="add-sub"][data-path="' + p.slice(0, 3).join('.') + '"]'); }
  });
  document.addEventListener('click', function (ev) {
    if (!ev.target.closest || !ev.target.closest('.pe-busca-caixa')) closeResults(null);
  });

  var addMeal = document.getElementById('pe-add-refeicao');
  if (addMeal) addMeal.addEventListener('click', function () {
    if (meals.length >= MAX_MEALS) return;
    meals.push({ name: '', time: '', note: '', options: [{ items: [] }] }); changes++;
    rerender('[data-field="name"][data-path="' + (meals.length - 1) + '"]');
  });
  [titleEl, guidanceEl].forEach(function (n) { if (n) n.addEventListener('input', function () { changes++; }); });

  // ---------- salvar ----------
  function payload() {
    function out(l) { return { food_id: l.food_id, qty: l.qty, measure_id: l.measure_id }; }
    return { meals: meals.map(function (m) {
      return { name: m.name, time: m.time, note: m.note, options: m.options.map(function (o) {
        return { items: o.items.filter(function (it) { return it.food_id; }).map(function (it) {
          var x = out(it);
          x.note = it.note;
          x.subs = it.subs.filter(function (s) { return s.food_id; }).map(out);
          return x;
        }) };
      }) };
    }) };
  }
  function showErrors(errs) {
    if (!errorsBox) return;
    var ul = errorsBox.querySelector('ul');
    if (ul) {
      ul.textContent = '';
      errs.forEach(function (e) { ul.appendChild(el('li', { text: String(e) })); });
    }
    errorsBox.hidden = errs.length === 0;
  }
  function setStatus(t) { if (statusEl) statusEl.textContent = t; }

  function save(auto) {
    if (saving) return Promise.resolve(null);
    saving = true;
    var at = changes;
    var body = new FormData();
    body.append('_csrf', csrf);
    body.append('titulo', titleEl ? titleEl.value : '');
    body.append('orientacoes', guidanceEl ? guidanceEl.value : '');
    body.append('estrutura', JSON.stringify(payload()));
    body.append('revisao', revision);
    body.append('auto', auto ? '1' : '0');
    setStatus(auto ? 'Salvando automaticamente…' : 'Salvando…');
    return fetch(saveUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: body })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (j) { return { status: r.status, j: j }; });
      })
      .then(function (res) {
        saving = false;
        var j = res.j;
        if (!j || !j.ok) {
          showErrors(j && Array.isArray(j.errors) ? j.errors : ['Não foi possível salvar (erro ' + res.status + '). Tente de novo.']);
          setStatus('Não salvo.');
          return null;
        }
        revision = String(j.revision);
        savedChanges = at;
        showErrors([]);
        if (conflictBox && j.conflict) conflictBox.hidden = false;
        if (at === changes && j.totals && j.totals.day) { // servidor e a referencia
          showDay(j.totals.day, j.percents || percents(j.totals.day));
          (j.totals.options || []).forEach(function (opts, mi) {
            (opts || []).forEach(function (t, oi) { showOption(mi, oi, t); });
          });
        }
        setStatus('Rascunho salvo às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) + '.');
        return j;
      })
      .catch(function () {
        saving = false;
        setStatus('Sem conexão: não foi possível salvar. Tentaremos de novo.');
        return null;
      });
  }

  var saveBtn = document.getElementById('pe-salvar');
  if (saveBtn) saveBtn.addEventListener('click', function () { save(false); });
  var pubBtn = document.getElementById('pe-publicar');
  var pubForm = document.getElementById('pe-form-publicar');
  if (pubBtn && pubForm) pubBtn.addEventListener('click', function () {
    save(false).then(function (j) {
      if (!j) return;
      if (j.conflict) { setStatus('Não publicado: confira o rascunho (alterado em outra janela) e publique de novo.'); return; }
      window.removeEventListener('beforeunload', guard);
      pubForm.submit();
    });
  });
  setInterval(function () { if (changes !== savedChanges && !saving) save(true); }, 30000);
  function guard(ev) {
    if (changes !== savedChanges) { ev.preventDefault(); ev.returnValue = ''; }
  }
  window.addEventListener('beforeunload', guard);

  var help = el('p', { id: 'pe-ajuda-busca', 'class': 'ajuda', text: 'Digite o nome do alimento; use as setas para escolher na lista e Enter para confirmar.' });
  list.parentNode.insertBefore(help, list);
  render();
})();
