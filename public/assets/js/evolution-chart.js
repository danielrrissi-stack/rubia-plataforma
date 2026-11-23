/*
 * Painel de evolucao (Chart.js local). Sem scripts inline: dados vem de data-url.
 * Curva de peso/escore-z, faixas de dose no mesmo eixo de datas e, ao abrir "Metabolismo", uma curva por serie.
 */
(function () {
  'use strict';

  var DAY = 86400000;

  function day(ymd) { var p = String(ymd).split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]) / DAY; }

  /** Por remedio, barras [x0, x1) em dias recortadas a [fromDay, toDay]; trecho vigente (to null) vai ate toDay. */
  function bandRows(meds, fromDay, toDay) {
    var rows = [];
    (meds || []).forEach(function (m) {
      var bars = [];
      m.segments.forEach(function (s) {
        var x0 = day(s.from);
        var x1 = (s.to === null ? toDay : day(s.to)) + 1;
        if (fromDay !== null && x1 <= fromDay) { return; }
        if (x0 > toDay) { return; }
        bars.push({ x0: fromDay !== null && x0 < fromDay ? fromDay : x0, x1: Math.min(x1, toDay + 1), label: s.dose });
      });
      if (bars.length) { rows.push({ name: m.name, bars: bars }); }
    });
    return rows;
  }

  /**
   * Faixa do eixo de datas ajustada aos dados: comeca pouco antes do primeiro dado (nunca antes do
   * inicio do periodo) e termina em toDay. Evita o vazio a esquerda no inicio do tratamento.
   */
  function fitRange(fromDay, toDay, dataDays) {
    var MIN_SPAN = 14;
    if (!dataDays.length) { return { min: toDay - 30, max: toDay }; }
    var first = Math.min.apply(null, dataDays);
    if (fromDay !== null && first < fromDay) { first = fromDay; }
    var min = first - Math.max(1, Math.round((toDay - first) * 0.05));
    if (toDay - min < MIN_SPAN) { min = toDay - MIN_SPAN; }
    if (fromDay !== null && min < fromDay) { min = fromDay; }
    return { min: min, max: toDay };
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { bandRows: bandRows, day: day, fitRange: fitRange };
  }
  if (typeof window === 'undefined' || typeof document === 'undefined') { return; }

  var root = document.getElementById('evolution-chart');
  if (!root || typeof Chart === 'undefined') { return; }
  var msg = root.querySelector('[data-role=msg]');
  var COLORS = { normal: '#2f6b5f', limitrofe: '#c9a227', alterado: '#8f3d1f', none: '#7a8a82' };
  var BAR_COLORS = ['#9cc5b8', '#e0c98a', '#c7b3d9', '#a9c7e3', '#e3b0a0'];

  function br(d) {
    var dt = new Date(d * DAY);
    function two(n) { return (n < 10 ? '0' : '') + n; }
    return two(dt.getUTCDate()) + '/' + two(dt.getUTCMonth() + 1) + '/' + dt.getUTCFullYear();
  }
  function fmt(v, dec) { return Number(v).toFixed(dec).replace('.', ','); }
  function classColor(c) {
    if (!c) { return COLORS.none; }
    if (c === 'normal') { return COLORS.normal; }
    return c.indexOf('alterado') === 0 ? COLORS.alterado : COLORS.limitrofe;
  }
  function xScale(min, max) {
    return { type: 'linear', min: min, max: max, ticks: { maxTicksLimit: 8, precision: 0, callback: function (v) { return br(v); } } };
  }

  // Escreve a dose dentro de cada barra.
  var labelPlugin = {
    id: 'doseLabels',
    afterDatasetsDraw: function (chart) {
      var ctx = chart.ctx;
      ctx.save();
      ctx.fillStyle = '#1f2b27';
      ctx.font = '12px system-ui, sans-serif';
      ctx.textBaseline = 'middle';
      chart.getDatasetMeta(0).data.forEach(function (bar, i) {
        var raw = chart.data.datasets[0].data[i];
        var w = Math.abs(bar.x - bar.base);
        if (w > ctx.measureText(raw.label).width + 8) { ctx.fillText(raw.label, Math.min(bar.x, bar.base) + 4, bar.y); }
      });
      ctx.restore();
    }
  };

  function drawCurve(j, min, max) {
    var unit = j.unit;
    var pts = j.points.map(function (p) { return { x: day(p[0]), y: p[1], fam: p[2] === 'familia' }; });
    var datasets = [{
      label: unit === 'kg' ? 'Peso (kg)' : 'Escore-z do IMC', data: pts, showLine: true, borderColor: '#1c3a31', borderWidth: 2,
      pointRadius: 5, pointBorderColor: '#1c3a31',
      pointBackgroundColor: function (c) { return c.raw && c.raw.fam ? '#ffffff' : '#1c3a31'; }
    }];
    if (j.path) {
      datasets.push({ label: 'Caminho da meta', data: j.path.map(function (p) { return { x: day(p[0]), y: p[1] }; }), showLine: true,
        borderDash: [6, 4], borderColor: '#7a8a82', pointRadius: 0, borderWidth: 2 });
    }
    new Chart(root.querySelector('[data-role=curva]'), {
      type: 'scatter', data: { datasets: datasets },
      options: {
        maintainAspectRatio: false, animation: false,
        scales: { x: xScale(min, max), y: { title: { display: true, text: unit === 'kg' ? 'kg' : 'escore-z' } } },
        plugins: { tooltip: { callbacks: { label: function (c) {
          var v = unit === 'kg' ? fmt(c.parsed.y, 1) + ' kg' : fmt(c.parsed.y, 2);
          return br(c.parsed.x) + ': ' + v + (c.raw.fam ? ' (casa)' : '');
        } } } }
      }
    });
  }

  function drawBands(j, min, max) {
    var canvas = root.querySelector('[data-role=faixas]');
    var rows = bandRows(j.meds, min, max);
    if (!rows.length) { canvas.parentNode.hidden = true; return; }
    canvas.parentNode.style.height = (40 + rows.length * 34) + 'px';
    var data = [];
    var colors = [];
    rows.forEach(function (r) {
      r.bars.forEach(function (b, i) { data.push({ x: [b.x0, b.x1], y: r.name, label: b.label }); colors.push(BAR_COLORS[i % BAR_COLORS.length]); });
    });
    new Chart(canvas, {
      type: 'bar', plugins: [labelPlugin],
      data: { labels: rows.map(function (r) { return r.name; }), datasets: [{ data: data, backgroundColor: colors, borderSkipped: false, barPercentage: 0.8 }] },
      options: {
        indexAxis: 'y', maintainAspectRatio: false, animation: false,
        scales: { x: xScale(min, max), y: { ticks: { autoSkip: false } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) {
          return c.raw.label + ': ' + br(c.raw.x[0]) + ' a ' + br(c.raw.x[1] - 1);
        } } } }
      }
    });
  }

  function drawMetabolism(j, min, max) {
    var box = document.getElementById('evolution-metabolism');
    var details = document.getElementById('evolucao-metabolismo');
    if (!box || !details) { return; }
    var done = false;
    function render() {
      if (done || !details.open) { return; }
      done = true;
      j.metabolism.forEach(function (s) {
        var wrap = document.createElement('div');
        var h = document.createElement('h3');
        h.textContent = s.label + ' (' + s.unit + ')';
        var holder = document.createElement('div');
        holder.className = 'evolucao-mini';
        var c = document.createElement('canvas');
        c.setAttribute('role', 'img');
        c.setAttribute('aria-label', 'Curva de ' + s.label);
        holder.appendChild(c);
        wrap.appendChild(h);
        wrap.appendChild(holder);
        box.appendChild(wrap);
        var main = s.points.map(function (p) { return { x: day(p[0]), y: p[1], cls: p[2] }; });
        var ds = [{ label: s.key === 'pa' ? 'Máxima' : s.label, data: main, showLine: true, borderColor: '#52605b', borderWidth: 1.5, pointRadius: 5,
          pointBackgroundColor: main.map(function (p) { return classColor(p.cls); }), pointBorderColor: main.map(function (p) { return classColor(p.cls); }) }];
        if (s.points2) {
          ds.push({ label: 'Mínima', data: s.points2.map(function (p) { return { x: day(p[0]), y: p[1] }; }), showLine: true,
            borderColor: '#7a8a82', borderDash: [4, 3], borderWidth: 1.5, pointRadius: 4, pointBackgroundColor: '#7a8a82' });
        }
        new Chart(c, {
          type: 'scatter', data: { datasets: ds },
          options: { maintainAspectRatio: false, animation: false, scales: { x: xScale(min, max) },
            plugins: { legend: { display: !!s.points2 }, tooltip: { callbacks: { label: function (t) {
              return br(t.parsed.x) + ': ' + fmt(t.parsed.y, s.unit === '%' ? 1 : 0) + ' ' + s.unit;
            } } } } }
        });
      });
    }
    details.addEventListener('toggle', render);
    render();
  }

  fetch(root.getAttribute('data-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
    .then(function (j) {
      var firsts = j.points.map(function (p) { return day(p[0]); });
      j.meds.forEach(function (m) { m.segments.forEach(function (s) { firsts.push(day(s.from)); }); });
      j.metabolism.forEach(function (s) { s.points.forEach(function (p) { firsts.push(day(p[0])); }); });
      var range = fitRange(j.from ? day(j.from) : null, day(j.to), firsts);
      var min = range.min;
      var max = range.max;
      if (!j.points.length) { msg.textContent = 'Nenhuma pesagem no período.'; }
      drawCurve(j, min, max);
      drawBands(j, min, max);
      drawMetabolism(j, min, max);
    })
    .catch(function () { msg.textContent = 'Não foi possível carregar o gráfico.'; });
})();
