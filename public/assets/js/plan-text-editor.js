(function () {
  var form = document.getElementById('plano-form');
  if (!form) return;
  var area = document.getElementById('corpo');
  var preview = document.getElementById('previa');
  var timer = null;

  function insertAt(text, wrapSelection) {
    var start = area.selectionStart, end = area.selectionEnd;
    var chosen = area.value.slice(start, end);
    var out = wrapSelection ? '**' + (chosen || 'texto') + '**' : text;
    area.setRangeText(out, start, end, 'end');
    area.focus();
    schedule();
  }

  form.querySelectorAll('[data-insert]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kind = b.getAttribute('data-insert');
      if (kind === 'titulo') insertAt('\nTÍTULO:\n', false);
      else if (kind === 'item') insertAt('\n• ', false);
      else if (kind === 'negrito') insertAt('', true);
      else if (kind === 'trecho') toggleSnippets(b);
    });
  });

  // "Inserir trecho": carrega /trechos.json uma vez e insere o texto escolhido na posicao do cursor.
  var box = document.getElementById('trechos-box');
  var select = document.getElementById('trechos-select');
  var msg = document.getElementById('trechos-msg');
  var insertBtn = document.getElementById('trechos-inserir');
  var snippets = null;

  function loadSnippets() {
    var url = form.getAttribute('data-snippets-url');
    if (!url || !select) return;
    msg.textContent = 'Carregando...';
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        snippets = j && Array.isArray(j.items) ? j.items : [];
        select.textContent = '';
        snippets.forEach(function (s, i) {
          var o = document.createElement('option');
          o.value = String(i);
          o.textContent = s.title;
          select.appendChild(o);
        });
        var has = snippets.length > 0;
        select.hidden = !has;
        insertBtn.hidden = !has;
        msg.textContent = has ? '' : (j ? 'Nenhum trecho cadastrado ainda.' : 'Não foi possível carregar os trechos.');
        if (!j) snippets = null;
      })
      .catch(function () { snippets = null; msg.textContent = 'Não foi possível carregar os trechos.'; });
  }

  function toggleSnippets(btn) {
    if (!box) return;
    box.hidden = !box.hidden;
    btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
    if (!box.hidden && snippets === null) loadSnippets();
  }

  if (insertBtn) {
    insertBtn.addEventListener('click', function () {
      var s = snippets && snippets[parseInt(select.value, 10)];
      if (!s) return;
      var start = area.selectionStart;
      var lead = start > 0 && area.value.charAt(start - 1) !== '\n' ? '\n' : '';
      insertAt(lead + s.body + '\n', false);
    });
  }

  // Cola sempre como texto puro, sem formatacao do Word/PDF.
  area.addEventListener('paste', function (ev) {
    var cd = ev.clipboardData;
    if (!cd) return;
    ev.preventDefault();
    var text = cd.getData('text/plain');
    area.setRangeText(text, area.selectionStart, area.selectionEnd, 'end');
    schedule();
  });

  function render() {
    if (!preview) return;
    var body = new URLSearchParams();
    body.set('_csrf', form.getAttribute('data-csrf') || '');
    body.set('texto', area.value);
    fetch(form.getAttribute('data-previa-url'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && typeof j.html === 'string') preview.innerHTML = j.html; })
      .catch(function () {});
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(render, 400);
  }

  area.addEventListener('input', schedule);
  render();
})();
