/* Atalho "Todo dia" do formulario de pedido do diario: marca os 7 dias. Sem JS, o botao fica oculto e o usuario marca os dias. */
(function () {
  'use strict';
  var btn = document.getElementById('todo-dia');
  if (!btn) { return; }
  btn.hidden = false;
  btn.addEventListener('click', function () {
    var boxes = document.querySelectorAll('input[name="weekdays[]"]');
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = true; }
  });
})();
