/* Graficos por faixa de classificacao (altura, peso e IMC). Chart.js local; nao altera growth-chart.js. */
(function () {
  'use strict';

  /** Intervalo (meses) entre as marcas da idade. */
  function tickStep(min, max) {
    var span = max - min;
    return span <= 24 ? 2 : (span <= 60 ? 4 : 6);
  }
  /** Ano cheio: so o ano ("5"); entre os anos: os meses completos ("4", "8"). */
  function tickLabel(months) {
    return String(months % 12 === 0 ? months / 12 : months % 12);
  }
  /** "5 anos e 4 meses" a partir dos meses (dica do ponto). */
  function ageText(months) {
    var m = Math.floor(months + 1e-6);
    var y = Math.floor(m / 12);
    var r = m % 12;
    var a = y ? y + (y === 1 ? ' ano' : ' anos') : '';
    var b = r || !y ? r + (r === 1 ? ' mês' : ' meses') : '';
    return a && b ? a + ' e ' + b : a || b;
  }
  /** Afasta rotulos empilhados (ordenados de cima para baixo) sem sair de [top, bottom]. */
  function spread(centers, heights, top, bottom) {
    var out = centers.slice();
    var i;
    for (i = 0; i < out.length; i++) {
      var lo = i === 0 ? top + heights[0] / 2 : out[i - 1] + (heights[i - 1] + heights[i]) / 2;
      if (out[i] < lo) { out[i] = lo; }
    }
    for (i = out.length - 1; i >= 0; i--) {
      var hi = i === out.length - 1 ? bottom - heights[i] / 2 : out[i + 1] - (heights[i + 1] + heights[i]) / 2;
      if (out[i] > hi) { out[i] = hi; }
    }
    return out;
  }
  /** Quebra o rotulo em ate duas linhas. */
  function wrap(label) {
    if (label.length <= 10) { return [label]; }
    var words = label.split(' ');
    if (words.length === 1) { return [label]; }
    var last = words.pop();
    return [words.join(' '), last];
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { tickStep: tickStep, tickLabel: tickLabel, ageText: ageText, spread: spread, wrap: wrap };
  }
  if (typeof window === 'undefined' || typeof document === 'undefined') { return; }
  var root = document.getElementById('growth-bands');
  if (!root || typeof Chart === 'undefined') { return; }

  var TONES = {
    ok: { bar: '#2e7d32', text: '#1f6a24', fill: 'rgba(46,125,50,0.16)' },
    atencao: { bar: '#b07a12', text: '#7d5500', fill: 'rgba(214,158,46,0.20)' },
    alerta: { bar: '#c8414b', text: '#a92732', fill: 'rgba(200,65,75,0.13)' },
    grave: { bar: '#39434f', text: '#39434f', fill: 'rgba(57,67,79,0.12)' }
  };
  var POINT = '#1d4fa8';
  var ALERT = '#b3261e';
  var LABEL_FONT = '600 12px system-ui, "Segoe UI", Roboto, Arial, sans-serif';
  var RIGHT = 104;
  var msg = root.querySelector('[data-role=msg]');
  var tabs = root.querySelectorAll('[data-faixa-idade]');
  var charts = {};
  var request = 0;

  function br(n) { return n.toLocaleString('pt-BR', { maximumFractionDigits: 2 }); }

  /** Areas coloridas, barra de categorias a direita e valor ao lado de cada ponto. */
  function plugin(def) {
    function ys(line, scales) { return line.map(function (p) { return { x: scales.x.getPixelForValue(p[0]), y: scales.y.getPixelForValue(p[1]) }; }); }
    return {
      id: 'faixas',
      beforeDatasetsDraw: function (chart) {
        var ctx = chart.ctx;
        var a = chart.chartArea;
        ctx.save();
        ctx.beginPath();
        ctx.rect(a.left, a.top, a.right - a.left, a.bottom - a.top);
        ctx.clip();
        def.zones.forEach(function (z, i) {
          var low = i === 0 ? [{ x: a.left, y: a.bottom }, { x: a.right, y: a.bottom }] : ys(def.lines[i - 1], chart.scales);
          var up = i === def.lines.length ? [{ x: a.left, y: a.top }, { x: a.right, y: a.top }] : ys(def.lines[i], chart.scales);
          ctx.beginPath();
          low.forEach(function (p, k) { if (k) { ctx.lineTo(p.x, p.y); } else { ctx.moveTo(p.x, p.y); } });
          up.slice().reverse().forEach(function (p) { ctx.lineTo(p.x, p.y); });
          ctx.closePath();
          ctx.fillStyle = TONES[z.tone].fill;
          ctx.fill();
        });
        ctx.restore();
      },
      afterDatasetsDraw: function (chart) {
        var ctx = chart.ctx;
        var a = chart.chartArea;
        var edges = [a.bottom].concat(def.lines.map(function (l) {
          return Math.min(a.bottom, Math.max(a.top, chart.scales.y.getPixelForValue(l[l.length - 1][1])));
        }), [a.top]);
        var order = def.zones.map(function (z, i) { return i; }).reverse();
        var texts = order.map(function (i) { return wrap(def.zones[i].label); });
        var pos = spread(
          order.map(function (i) { return (edges[i] + edges[i + 1]) / 2; }),
          texts.map(function (t) { return t.length * 14 + 4; }), a.top, a.bottom);
        ctx.save();
        ctx.font = LABEL_FONT;
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'left';
        order.forEach(function (i, k) {
          var tone = TONES[def.zones[i].tone];
          ctx.fillStyle = tone.bar;
          ctx.fillRect(a.right + 7, edges[i + 1] + 1, 5, Math.max(1, edges[i] - edges[i + 1] - 2));
          ctx.fillStyle = tone.text;
          texts[k].forEach(function (t, n) { ctx.fillText(t, a.right + 18, pos[k] + (n - (texts[k].length - 1) / 2) * 14); });
        });
        // valor ao lado do ponto, do mais novo para o mais antigo (pula os que ficariam sobre o seguinte)
        var ds = chart.data.datasets.length - 1;
        var lastX = Infinity;
        var data = chart.data.datasets[ds].data;
        var els = chart.getDatasetMeta(ds).data;
        ctx.textAlign = 'center';
        ctx.lineJoin = 'round';
        ctx.lineWidth = 3;
        ctx.strokeStyle = 'rgba(255,255,255,0.9)';
        for (var n = els.length - 1; n >= 0; n--) {
          var raw = data[n];
          var el = els[n];
          if (raw.y > chart.scales.y.max || raw.y < chart.scales.y.min || lastX - el.x < 36) { continue; }
          lastX = el.x;
          var lx = Math.min(a.right - 16, Math.max(a.left + 18, el.x));
          var ly = el.y - 15 < a.top + 6 ? el.y + 16 : el.y - 15;
          ctx.strokeText(br(raw.y), lx, ly);
          ctx.fillStyle = raw.bad ? ALERT : POINT;
          ctx.fillText(br(raw.y), lx, ly);
        }
        ctx.restore();
      }
    };
  }

  function draw(box, def, x) {
    var step = tickStep(x.min, x.max);
    // linha tracejada com a cor da faixa menos favoravel que ela separa
    var rank = { ok: 0, atencao: 1, alerta: 2, grave: 3 };
    var datasets = def.lines.map(function (line, i) {
      var t = rank[def.zones[i].tone] > rank[def.zones[i + 1].tone] ? def.zones[i].tone : def.zones[i + 1].tone;
      return {
        data: line.map(function (p) { return { x: p[0], y: p[1] }; }), showLine: true, pointRadius: 0, pointHitRadius: 0,
        borderWidth: 1.2, borderDash: [5, 4], borderColor: TONES[t].bar, tension: 0
      };
    });
    datasets.push({
      isPatient: true, data: def.points, showLine: true, borderColor: POINT, borderWidth: 1.5, tension: 0,
      pointRadius: function (c) { return c.raw && c.raw.bad ? 8 : (root.clientWidth > 520 ? 5 : 3); },
      pointStyle: function (c) { return c.raw && c.raw.bad ? 'triangle' : 'circle'; },
      pointBackgroundColor: function (c) { return c.raw && c.raw.bad ? ALERT : POINT; },
      pointBorderColor: function (c) { return c.raw && c.raw.bad ? ALERT : POINT; }
    });
    return new Chart(box.querySelector('canvas'), {
      type: 'scatter',
      data: { datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        layout: { padding: { right: RIGHT, top: 6 } },
        scales: {
          x: {
            type: 'linear', min: x.min, max: x.max,
            title: { display: true, text: 'Idade (meses completos e anos)' },
            grid: { display: false },
            afterBuildTicks: function (axis) {
              axis.ticks = [];
              for (var m = x.min; m <= x.max; m += step) { axis.ticks.push({ value: m }); }
            },
            ticks: {
              autoSkip: false, maxRotation: 0,
              callback: function (v) { return v % 12 === 0 || root.clientWidth > 520 ? tickLabel(v) : ''; },
              font: function (c) { return c.tick && c.tick.value % 12 === 0 ? { weight: 'bold', size: 14 } : { size: 11 }; },
              color: function (c) { return c.tick && c.tick.value % 12 === 0 ? '#1f2b27' : '#52605b'; }
            }
          },
          y: { min: def.y.min, max: def.y.max, ticks: { stepSize: def.key === 'bfa' ? 2 : 5, autoSkip: true }, grid: { color: 'rgba(82,96,91,0.14)' } }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: function (item) { return item.dataset.isPatient === true; },
            callbacks: {
              label: function (c) {
                var t = c.raw.date + ' (' + ageText(c.raw.x) + '): ' + br(c.raw.y);
                return c.raw.bad ? [t, 'verificar medida'] : t;
              }
            }
          }
        }
      },
      plugins: [plugin(def)]
    });
  }

  function render(j) {
    var outside = 0;
    var total = 0;
    Array.prototype.forEach.call(root.querySelectorAll('[data-chart]'), function (box) {
      var key = box.getAttribute('data-chart');
      var def = j.charts.filter(function (c) { return c.key === key; })[0];
      if (charts[key]) { charts[key].destroy(); delete charts[key]; }
      box.hidden = !def;
      if (!def) { return; }
      total += def.points.length;
      outside += def.points.filter(function (p) { return p.y > def.y.max || p.y < def.y.min; }).length;
      charts[key] = draw(box, def, j.x);
    });
    if (!total) {
      msg.textContent = 'Sem medidas nesta faixa de idade.';
    } else {
      msg.textContent = outside ? 'Há medida fora do gráfico: verifique os valores marcados na tabela.' : '';
    }
  }

  function load(faixa) {
    var mine = ++request;
    msg.textContent = 'Carregando…';
    fetch(root.getAttribute('data-url') + '?faixa=' + encodeURIComponent(faixa), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
      .then(function (j) { if (mine === request) { render(j); } })
      .catch(function () { if (mine === request) { msg.textContent = 'Não foi possível carregar os gráficos.'; } });
  }

  Array.prototype.forEach.call(tabs, function (b) {
    b.addEventListener('click', function () {
      Array.prototype.forEach.call(tabs, function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      load(b.getAttribute('data-faixa-idade'));
    });
  });
  load(root.getAttribute('data-faixa'));
})();
