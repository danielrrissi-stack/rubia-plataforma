/* Grafico historico por exame (Chart.js local). Sem scripts inline: URL vem de data-url; exame, dos botoes data-exame. */
(function () {
  'use strict';
  var root = document.getElementById('lab-chart');
  if (!root || typeof Chart === 'undefined') { return; }
  var url = root.getAttribute('data-url');
  var canvas = root.querySelector('canvas');
  var msg = root.querySelector('[data-role=msg]');
  var details = root.querySelector('[data-role=lista]');
  var list = details.querySelector('ul');
  var buttons = document.querySelectorAll('button[data-exame]');
  var chart = null;
  var ALERT = '#b3261e';
  var BORDER = '#b06000';
  var NORMAL = '#2f5d50';
  var BAND = 'rgba(47,107,95,0.16)';
  var NO_RANGE = '#8a948f';
  var NO_RANGE_TEXT = 'sem faixa para esta idade';

  function destroy() { if (chart) { chart.destroy(); chart = null; } }
  function say(text) { msg.textContent = text || ''; }
  function brDate(ymd) { var p = String(ymd).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ymd; }
  function num(v, dec) {
    v = Number(v);
    while (dec < 4 && Number(v.toFixed(dec)) !== v) { dec++; } // nunca menos casas do que o valor precisa
    return v.toLocaleString('pt-BR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }
  function colorOf(cls) {
    if (cls === 'alterado_alto' || cls === 'alterado_baixo') { return ALERT; }
    if (cls === 'limitrofe_alto' || cls === 'limitrofe_baixo') { return BORDER; }
    return NORMAL;
  }

  function fillList(j) {
    var dec = j.test.decimals;
    list.textContent = '';
    j.points.forEach(function (p) {
      var li = document.createElement('li');
      li.textContent = brDate(p.date) + ': ' + num(p.value, dec) + ' ' + j.test.unit + (p.no_range ? ' (' + NO_RANGE_TEXT + ')' : (p.label ? ' (' + p.label + ')' : ''));
      list.appendChild(li);
    });
    details.hidden = j.points.length === 0;
  }

  function draw(j) {
    destroy();
    var dec = j.test.decimals;
    var pts = j.points;
    var labels = pts.map(function (p) { return brDate(p.date); });
    var band = j.band;
    var both = band && band.low !== null && band.high !== null;
    var constant = function (v) { return pts.map(function () { return v; }); };
    var datasets = [];
    if (both) {
      // Faixa sombreada: area entre o limite inferior (dataset 0) e o superior (dataset 1).
      datasets.push({ label: 'Limite inferior', data: constant(band.low), borderColor: 'rgba(47,107,95,0.35)', borderWidth: 1, pointRadius: 0, fill: false, order: 3 });
      datasets.push({ label: 'Faixa esperada', data: constant(band.high), borderColor: 'rgba(47,107,95,0.35)', borderWidth: 1, pointRadius: 0, fill: '-1', backgroundColor: BAND, order: 3 });
    } else if (band) {
      // Um so lado: linha tracejada de referencia.
      if (band.low !== null) { datasets.push({ label: 'Limite inferior', data: constant(band.low), borderColor: '#6b7a73', borderDash: [6, 4], borderWidth: 1, pointRadius: 0, fill: false, order: 3 }); }
      if (band.high !== null) { datasets.push({ label: 'Limite superior', data: constant(band.high), borderColor: '#6b7a73', borderDash: [6, 4], borderWidth: 1, pointRadius: 0, fill: false, order: 3 }); }
    }
    datasets.push({
      label: j.test.name + (j.test.unit ? ' (' + j.test.unit + ')' : ''),
      isSerie: true,
      data: pts.map(function (p) { return p.value; }),
      borderColor: NORMAL, backgroundColor: NORMAL, borderWidth: 2, tension: 0, order: 1,
      pointRadius: function (c) { var p = pts[c.dataIndex]; return p && p.class && p.class !== 'normal' ? 7 : 5; },
      pointStyle: function (c) { var p = pts[c.dataIndex]; return p && p.class && p.class !== 'normal' ? 'triangle' : 'circle'; },
      // Sem faixa: ponto cinza e vazado (nao parece normal).
      pointBackgroundColor: function (c) { var p = pts[c.dataIndex]; return p && p.no_range ? '#ffffff' : colorOf(p && p.class); },
      pointBorderColor: function (c) { var p = pts[c.dataIndex]; return p && p.no_range ? NO_RANGE : colorOf(p && p.class); },
      pointBorderWidth: function (c) { var p = pts[c.dataIndex]; return p && p.no_range ? 2 : 1; }
    });
    chart = new Chart(canvas, {
      type: 'line',
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        scales: { y: { title: { display: true, text: j.test.unit || j.test.name } } },
        plugins: {
          tooltip: {
            filter: function (item) { return item.dataset.isSerie === true; },
            callbacks: {
              label: function (c) {
                var p = pts[c.dataIndex];
                return num(p.value, dec) + ' ' + j.test.unit + (p.no_range ? ' (' + NO_RANGE_TEXT + ')' : (p.label ? ' (' + p.label + ')' : ''));
              }
            }
          }
        }
      }
    });
  }

  function load(id) {
    say('Carregando…');
    fetch(url + '?exame=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
      .then(function (j) {
        fillList(j);
        if (!j.points.length) { destroy(); say('Sem valores numéricos para este exame.'); return; }
        say('');
        draw(j);
      })
      .catch(function () { destroy(); details.hidden = true; say('Não foi possível carregar o gráfico.'); });
  }

  Array.prototype.forEach.call(buttons, function (b) {
    b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', function () {
      Array.prototype.forEach.call(buttons, function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      load(b.getAttribute('data-exame'));
    });
  });
})();
