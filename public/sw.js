/* Service worker do portal: guarda em cache SOMENTE arquivos estaticos de /assets/ (cache-first).
   Paginas HTML, JSON e qualquer rota do portal nunca sao cacheadas. */
var CACHE = 'rubia-assets-v1';

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
  if (url.origin !== self.location.origin || url.pathname.indexOf('/assets/') === -1) { return; }
  event.respondWith(
    caches.match(req).then(function (hit) {
      if (hit) { return hit; }
      return fetch(req).then(function (res) {
        if (res.ok && res.status === 200) {
          var copy = res.clone();
          caches.open(CACHE).then(function (c) { c.put(req, copy); });
        }
        return res;
      });
    })
  );
});
