/* Tabela de faixas do catalogo de exames: "+ faixa" clona a ultima linha (limpa) e "Remover" tira a linha. */
(function () {
  'use strict';
  var table = document.getElementById('faixas');
  var add = document.getElementById('add-faixa');
  if (!table || !add) { return; }
  var body = table.tBodies[0];

  function renumber() {
    Array.prototype.forEach.call(body.rows, function (row, i) {
      Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (el) {
        el.name = el.name.replace(/^faixa\[\d+\]/, 'faixa[' + i + ']');
      });
    });
  }

  function bindRemove(row) {
    var btn = row.querySelector('[data-remover-faixa]');
    if (!btn) { return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
      row.parentNode.removeChild(row);
      renumber();
    });
  }

  Array.prototype.forEach.call(body.rows, bindRemove);
  add.hidden = false;
  add.addEventListener('click', function () {
    var last = body.rows[body.rows.length - 1];
    if (!last) { return; }
    var row = last.cloneNode(true);
    Array.prototype.forEach.call(row.querySelectorAll('input'), function (el) { el.value = ''; });
    Array.prototype.forEach.call(row.querySelectorAll('select'), function (el) { el.selectedIndex = 0; });
    body.appendChild(row);
    renumber();
    bindRemove(row);
  });
})();
