/* Service worker do portal: rede primeiro para arquivos estaticos de <escopo>assets/, com cache so como
   reserva offline. Paginas HTML, JSON e rotas do portal nunca sao cacheadas. */
var CACHE = 'rubia-assets-v2';
var SCOPE_PATH = new URL(self.registration.scope).pathname;
var ASSETS = SCOPE_PATH + 'assets/';

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.indexOf(ASSETS) !== 0) { return; }
  event.respondWith(
    fetch(req).then(function (res) {
      if (res.ok && res.type === 'basic' && !res.redirected) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (hit) { return hit || Response.error(); });
    })
  );
});
