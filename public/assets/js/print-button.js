document.addEventListener('click', function (ev) {
  var btn = ev.target.closest ? ev.target.closest('[data-print]') : null;
  if (btn) {
    ev.preventDefault();
    window.print();
  }
});
