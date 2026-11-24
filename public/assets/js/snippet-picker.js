// "Inserir trecho": carrega /trechos.json uma vez e insere o texto escolhido no cursor do campo alvo.
// Dispara "input" no campo, para que a pre-visualizacao / contagem de alteracoes do editor reajam.
(function () {
  var open = document.getElementById('trechos-abrir');
  var box = document.getElementById('trechos-box');
  var select = document.getElementById('trechos-select');
  var target = document.getElementById('trechos-alvo');
  var msg = document.getElementById('trechos-msg');
  var insertBtn = document.getElementById('trechos-inserir');
  if (!open || !box || !select || !insertBtn) return;
  var snippets = null;

  function load() {
    msg.textContent = 'Carregando...';
    fetch(box.getAttribute('data-snippets-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        snippets = j && Array.isArray(j.items) ? j.items : null;
        select.textContent = '';
        (snippets || []).forEach(function (s, i) {
          var o = document.createElement('option');
          o.value = String(i);
          o.textContent = s.title;
          select.appendChild(o);
        });
        var has = !!snippets && snippets.length > 0;
        select.hidden = !has;
        insertBtn.hidden = !has;
        msg.textContent = has ? '' : (snippets ? 'Nenhum trecho cadastrado ainda.' : 'Não foi possível carregar os trechos.');
      })
      .catch(function () { snippets = null; msg.textContent = 'Não foi possível carregar os trechos.'; });
  }

  open.addEventListener('click', function () {
    box.hidden = !box.hidden;
    open.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
    if (!box.hidden && snippets === null) load();
  });

  insertBtn.addEventListener('click', function () {
    var s = snippets && snippets[parseInt(select.value, 10)];
    var area = target ? document.getElementById(target.value) : null;
    if (!s || !area) return;
    var start = area.selectionStart;
    var lead = start > 0 && area.value.charAt(start - 1) !== '\n' ? '\n' : '';
    area.setRangeText(lead + s.body + '\n', start, area.selectionEnd, 'end');
    area.focus();
    area.dispatchEvent(new Event('input', { bubbles: true }));
  });
})();
