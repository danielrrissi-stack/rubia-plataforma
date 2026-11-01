/* Curvas de crescimento (Chart.js local). Sem scripts inline: URL e modo vem de data-attributes. */
(function () {
  'use strict';
  var root = document.getElementById('growth-chart');
  if (!root || typeof Chart === 'undefined') { return; }
  var url = root.getAttribute('data-url');
  var adult = root.getAttribute('data-adult') === '1';
  var canvas = root.querySelector('canvas');
  var msg = root.querySelector('[data-role=msg]');
  var indTabs = root.querySelectorAll('[data-indicador]');
  var tipoTabs = root.querySelectorAll('[data-tipo]');
  var indicador = indTabs.length ? indTabs[0].getAttribute('data-indicador') : 'bfa';
  var tipo = 'z';
  var chart = null;
  var YLABEL = { bfa: 'IMC (kg/m²)', wfa: 'Peso (kg)', hfa: 'Altura (cm)', wflh: 'Peso (kg)', hcfa: 'Perímetro cefálico (cm)', peso: 'Peso (kg)', imc: 'IMC (kg/m²)' };
  var ALERT = '#b3261e';

  function pressed(list, attr, value) {
    Array.prototype.forEach.call(list, function (b) {
      b.setAttribute('aria-pressed', b.getAttribute(attr) === value ? 'true' : 'false');
    });
  }
  function destroy() { if (chart) { chart.destroy(); chart = null; } }
  function say(text) { msg.textContent = text || ''; }
  function brDate(ymd) { var p = String(ymd).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ymd; }

  function drawAdult(series) {
    var data = series[indicador] || [];
    destroy();
    say(data.length ? '' : 'Sem medidas para exibir.');
    chart = new Chart(canvas, {
      type: 'line',
      data: {
        labels: data.map(function (d) { return brDate(d[0]); }),
        datasets: [{ label: YLABEL[indicador], data: data.map(function (d) { return d[1]; }), borderColor: '#2f5d50', backgroundColor: '#2f5d50', pointRadius: 5, tension: 0 }]
      },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { title: { display: true, text: YLABEL[indicador] } } } }
    });
  }

  function drawChild(j) {
    destroy();
    var lines = j.reference.lines;
    var datasets = lines.map(function (l) {
      var median = l.label === '0' || l.label === 'P50';
      return {
        label: l.label, data: l.points.map(function (p) { return { x: p[0], y: p[1] }; }),
        showLine: true, pointRadius: 0, borderWidth: median ? 2 : 1, borderColor: median ? '#4a5a52' : '#9aa59e',
        backgroundColor: '#9aa59e', fill: false, tension: 0.2, order: 2
      };
    });
    datasets.push({
      label: 'Paciente',
      data: j.patient.map(function (p) { return { x: p[0], y: p[1], date: p[2], bad: p[3] === true }; }),
      showLine: true, borderColor: '#1c3a31', backgroundColor: '#1c3a31', borderWidth: 2, order: 1,
      pointRadius: function (c) { return c.raw && c.raw.bad ? 8 : 5; },
      pointStyle: function (c) { return c.raw && c.raw.bad ? 'triangle' : 'circle'; },
      pointBackgroundColor: function (c) { return c.raw && c.raw.bad ? ALERT : '#1c3a31'; }
    });
    say(j.patient.length ? '' : 'Sem medidas para este indicador.');
    chart = new Chart(canvas, {
      type: 'scatter',
      data: { datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        scales: {
          x: { type: 'linear', title: { display: true, text: j.x_unit === 'cm' ? 'Altura (cm)' : 'Idade (meses)' } },
          y: { title: { display: true, text: YLABEL[indicador] } }
        },
        plugins: {
          legend: { labels: { filter: function (i) { return true; } } },
          tooltip: {
            filter: function (item) { return item.dataset.label === 'Paciente'; },
            callbacks: {
              label: function (c) {
                var r = c.raw;
                var t = r.date + ': ' + r.y.toLocaleString('pt-BR');
                return r.bad ? [t, 'verificar medida'] : t;
              }
            }
          }
        }
      }
    });
  }

  function load() {
    var q = new URLSearchParams({ indicador: adult ? 'bfa' : indicador, tipo: tipo });
    say('Carregando…');
    fetch(url + '?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
      .then(function (j) {
        if (adult && j.series) { drawAdult(j.series); return; }
        if (!j.reference) { destroy(); say(j.message || 'Não se aplica para esta idade'); return; }
        drawChild(j);
      })
      .catch(function () { destroy(); say('Não foi possível carregar o gráfico.'); });
  }

  Array.prototype.forEach.call(indTabs, function (b) {
    b.addEventListener('click', function () { indicador = b.getAttribute('data-indicador'); pressed(indTabs, 'data-indicador', indicador); load(); });
  });
  Array.prototype.forEach.call(tipoTabs, function (b) {
    b.addEventListener('click', function () { tipo = b.getAttribute('data-tipo'); pressed(tipoTabs, 'data-tipo', tipo); load(); });
  });
  load();
})();
