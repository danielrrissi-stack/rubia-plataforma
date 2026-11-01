/* Seleciona todo o conteudo ao focar elementos com data-select-on-focus (substitui onfocus inline, bloqueado pela CSP). */
(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-select-on-focus]'), function (el) {
    el.addEventListener('focus', function () { el.select(); });
  });
})();
