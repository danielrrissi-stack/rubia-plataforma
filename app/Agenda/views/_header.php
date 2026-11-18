<?php
/**
 * Cabecalho da agenda (dia e semana): titulo, seletor, navegacao e fechamentos do periodo.
 * Variaveis: $agendas, $prof, $professional, $date, $today, $view, $prev, $next, $closures.
 */
$agendaUrl = static fn (array $q): string => url('/agenda') . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
$other = $view === 'semana' ? 'dia' : 'semana';
?>
<h1>Agenda<?= $professional !== null ? ' — ' . e($professional['name']) : '' ?></h1>
<?php if ($prof === null): ?>
  <p>Nenhuma agenda disponível.</p>
<?php else: ?>
<?php $selectAction = '/agenda'; $selectHidden = ['data' => $date, 'vista' => $view]; include __DIR__ . '/_prof_select.php'; ?>
<nav class="agenda-nav" aria-label="Período">
  <a class="btn btn-quiet" href="<?= e($agendaUrl(['prof' => $prof, 'data' => $prev, 'vista' => $view])) ?>">◀ <?= $view === 'semana' ? 'Semana anterior' : 'Dia anterior' ?></a>
  <a class="btn btn-quiet" href="<?= e($agendaUrl(['prof' => $prof, 'data' => $today, 'vista' => $view])) ?>">Hoje</a>
  <a class="btn btn-quiet" href="<?= e($agendaUrl(['prof' => $prof, 'data' => $next, 'vista' => $view])) ?>"><?= $view === 'semana' ? 'Próxima semana' : 'Próximo dia' ?> ▶</a>
  <a class="btn btn-quiet" href="<?= e($agendaUrl(['prof' => $prof, 'data' => $date, 'vista' => $other])) ?>">Ver <?= e($other) ?></a>
  <form method="get" action="<?= e(url('/agenda')) ?>" class="form-inline">
    <input type="hidden" name="prof" value="<?= (int) $prof ?>"><input type="hidden" name="vista" value="<?= e($view) ?>">
    <label class="sr-only" for="agenda-data">Data</label>
    <input id="agenda-data" type="date" name="data" value="<?= e($date) ?>">
    <button type="submit" class="btn btn-quiet">Ir</button>
  </form>
  <a class="btn btn-quiet" href="<?= e(url('/agenda/horarios') . '?prof=' . (int) $prof) ?>">Horários</a>
</nav>
<?php foreach ($closures as $c): ?>
  <p class="aviso aviso-atencao">Fechado <?= e(fmt_date((string) $c['start_date'])) ?><?= $c['end_date'] !== $c['start_date'] ? ' a ' . e(fmt_date((string) $c['end_date'])) : '' ?><?= $c['start_time'] !== null ? ', ' . e(substr((string) $c['start_time'], 0, 5)) . '–' . e(substr((string) $c['end_time'], 0, 5)) : ' (dia inteiro)' ?><?= $c['reason'] !== null ? ' — ' . e($c['reason']) : '' ?></p>
<?php endforeach; ?>
<?php endif;
