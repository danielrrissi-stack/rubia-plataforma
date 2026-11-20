// Funcoes puras do plano por dias (v2) usadas pelo editor; testadas no node (tests/js/plan-days.test.js).
(function (api) {
  'use strict';
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else window.PlanDaysJs = api;
})((function () {
  'use strict';
  var NAMES = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  // Data do dia i do plano em UTC (sem fuso: "2026-11-23" + 0 e sempre segunda 23/11).
  function dayLabel(startYmd, i) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(startYmd || ''));
    if (!m) return 'Dia ' + (Number(i) + 1);
    var d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + Number(i)));
    return NAMES[d.getUTCDay()] + ' ' + pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1);
  }
  // Dia fora de +-10 % da meta (destacado na aba). Sem meta, nunca.
  function offTarget(kcal, meta) {
    if (typeof meta !== 'number' || !isFinite(meta) || meta <= 0) return false;
    return Math.abs(Number(kcal) - meta) > 0.10 * meta;
  }
  return { dayLabel: dayLabel, offTarget: offTarget };
})());
