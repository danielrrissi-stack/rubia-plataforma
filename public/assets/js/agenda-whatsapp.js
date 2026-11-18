/* Botao de WhatsApp da agenda: no clique copia o aviso (data-text) e deixa o link abrir o wa.me em nova aba.
   Falha na copia e silenciosa (o texto ja vai no link). Mostra "Mensagem copiada" ao lado por 3 s. */
(function () {
  'use strict';
  document.addEventListener('click', function (ev) {
    var link = ev.target && ev.target.closest ? ev.target.closest('a.btn-whatsapp') : null;
    if (!link) { return; }
    var text = link.getAttribute('data-text') || '';
    if (!navigator.clipboard || !navigator.clipboard.writeText) { return; }
    navigator.clipboard.writeText(text).then(function () {
      var old = link.nextElementSibling;
      if (old && old.classList.contains('agenda-copiado')) { old.parentNode.removeChild(old); }
      var note = document.createElement('span');
      note.className = 'ajuda agenda-copiado';
      note.setAttribute('role', 'status');
      note.textContent = 'Mensagem copiada';
      link.parentNode.insertBefore(note, link.nextSibling);
      window.setTimeout(function () { if (note.parentNode) { note.parentNode.removeChild(note); } }, 3000);
    }, function () { /* sem permissao: ignora */ });
  });
})();
