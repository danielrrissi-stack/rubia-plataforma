// Marca/desmarca "fiz" sem recarregar a pagina. Sem JS, cada botao continua sendo um formulario normal.
document.addEventListener('submit', function (ev) {
  var form = ev.target.closest ? ev.target.closest('form[data-goal-check]') : null;
  if (!form || !window.fetch || !window.FormData) {
    return;
  }
  ev.preventDefault();
  var btn = form.querySelector('button');
  var err = document.querySelector('[data-goal-error]');
  var turningOn = /\/marcar$/.test(form.getAttribute('action'));
  btn.disabled = true;
  fetch(form.getAttribute('action'), {
    method: 'POST',
    body: new FormData(form),
    headers: { 'Accept': 'application/json' },
    credentials: 'same-origin'
  }).then(function (res) {
    return res.json().then(function (data) { return data; }, function () { return { ok: false }; });
  }).then(function (data) {
    if (!data.ok) {
      if (err) {
        err.textContent = data.error || 'Não foi possível salvar. Atualize a página e tente de novo.';
        err.hidden = false;
      }
      btn.disabled = false;
      return;
    }
    if (err) {
      err.hidden = true;
    }
    btn.setAttribute('aria-pressed', turningOn ? 'true' : 'false');
    btn.classList.toggle('metas-dia-feito', turningOn);
    var mark = btn.querySelector('.metas-dia-marca');
    if (mark) {
      mark.textContent = turningOn ? '✓' : '';
    }
    form.setAttribute('action', form.getAttribute('data-base') + (turningOn ? '/desmarcar' : '/marcar'));
    var stars = document.querySelector('[data-stars]');
    if (stars && typeof data.stars === 'number') {
      stars.textContent = String(data.stars);
    }
    btn.disabled = false;
  }).catch(function () {
    form.submit();
  });
});
