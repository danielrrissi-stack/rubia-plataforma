/* Grafico da meta de peso (Chart.js local). Sem scripts inline: URL vem de data-url. So equipe. */
(function () {
  'use strict';
  var root = document.getElementById('weight-goal-chart');
  if (!root || typeof Chart === 'undefined') { return; }
  var canvas = root.querySelector('canvas');
  var msg = root.querySelector('[data-role=msg]');
  var DAY = 86400000;

  function x(ymd) { var p = String(ymd).split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]) / DAY; }
  function br(dayNum) {
    var d = new Date(dayNum * DAY);
    function two(n) { return (n < 10 ? '0' : '') + n; }
    return two(d.getUTCDate()) + '/' + two(d.getUTCMonth() + 1) + '/' + d.getUTCFullYear();
  }
  function num(v, unit) {
    var s = (unit === 'kg' ? v.toFixed(1) : v.toFixed(2)).replace('.', ',');
    return unit === 'kg' ? s + ' kg' : (v > 0 ? '+' : '') + s;
  }

  fetch(root.getAttribute('data-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
    .then(function (j) {
      if (!j || j.goal === null) { msg.textContent = 'Nenhuma meta ativa.'; return; }
      var path = j.path.map(function (p) { return { x: x(p[0]), y: p[1] }; });
      var datasets = [
        { label: 'Medições', data: j.points.map(function (p) { return { x: x(p[0]), y: p[1], fam: p[2] === 'familia' }; }),
          showLine: true, borderColor: '#1c3a31', backgroundColor: '#1c3a31', borderWidth: 2, pointRadius: 5,
          pointStyle: function (c) { return c.raw && c.raw.fam ? 'rectRot' : 'circle'; }, order: 1 },
        { label: 'Caminho combinado', data: path, showLine: true, borderDash: [6, 4], borderColor: '#7a8a82',
          backgroundColor: '#7a8a82', pointRadius: [0, 6], pointStyle: ['circle', 'star'], borderWidth: 2, order: 3 },
        { label: 'Alvo', data: [{ x: path[0].x, y: j.target }, { x: path[1].x, y: j.target }], showLine: true,
          borderColor: '#c9a227', backgroundColor: '#c9a227', borderWidth: 1, pointRadius: 0, order: 4 }
      ];
      if (j.trend) {
        datasets.push({ label: 'Tendência', data: j.trend.map(function (p) { return { x: x(p[0]), y: p[1] }; }), showLine: true,
          borderColor: '#b5562d', backgroundColor: '#b5562d', borderWidth: 2, pointRadius: 0, order: 2 });
      }
      msg.textContent = 'Situação: ' + j.statusLabel + '.';
      new Chart(canvas, {
        type: 'scatter',
        data: { datasets: datasets },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          scales: {
            x: { type: 'linear', ticks: { callback: function (v) { return br(v); } } },
            y: { title: { display: true, text: j.unit === 'kg' ? 'Peso (kg)' : 'Escore-z do IMC' } }
          },
          plugins: {
            tooltip: { callbacks: { label: function (c) {
              return c.dataset.label + ': ' + br(c.raw.x) + ' — ' + num(c.raw.y, j.unit) + (c.raw.fam ? ' (família)' : '');
            } } }
          }
        }
      });
    })
    .catch(function () { msg.textContent = 'Não foi possível carregar o gráfico.'; });
})();
