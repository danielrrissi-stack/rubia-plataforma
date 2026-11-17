/* Confirmacao antes de enviar formularios com data-confirm="mensagem". Sem JS, o formulario envia normalmente. */
(function () {
  'use strict';
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var msg = form && form.getAttribute ? form.getAttribute('data-confirm') : null;
    if (msg && !window.confirm(msg)) { ev.preventDefault(); }
  });
})();
