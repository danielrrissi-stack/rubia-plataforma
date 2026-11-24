/* Registra o service worker do portal (arquivo externo: sem scripts inline). */
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) { return; }
  var base = document.documentElement.getAttribute('data-base') || '';
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () {});
  });
})();
