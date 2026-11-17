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

  // Titulo de refeicao: insere "Nome da refeição:" (linha terminada em ":" vira titulo) com o nome ja selecionado,
  // para a digitacao substituir.
  function insertTitle() {
    var placeholder = 'Nome da refeição';
    var start = area.selectionStart, end = area.selectionEnd;
    var before = start > 0 && area.value.charAt(start - 1) !== '\n' ? '\n' : '';
    area.setRangeText(before + placeholder + ':\n', start, end, 'end');
    var from = start + before.length;
    area.focus();
    area.setSelectionRange(from, from + placeholder.length);
    schedule();
  }

  form.querySelectorAll('[data-insert]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kind = b.getAttribute('data-insert');
      if (kind === 'titulo') insertTitle();
      else if (kind === 'item') insertAt('\n• ', false);
      else if (kind === 'negrito') insertAt('', true);
    });
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
