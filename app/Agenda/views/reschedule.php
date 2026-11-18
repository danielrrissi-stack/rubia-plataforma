<?php
/**
 * Remarcar: escolhe profissional permitida e data; lista os horarios livres do dia.
 * Variaveis: $a, $agendas, $prof (?int), $date, $today, $canChange, $refusal (?string), $slots, $errors, $prev, $next.
 */
use App\Agenda\AgendaStatus;

$start = new \DateTimeImmutable((string) $a['starts_at']);
$here = static fn (array $q): string => url('/agenda/consultas/' . (int) $a['id'] . '/remarcar') . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
?>
<h1>Remarcar consulta — <?= e($a['social_name'] ?: $a['patient_name']) ?></h1>
<p>Atual: <?= e($a['professional_name']) ?>, <?= e(AgendaStatus::WEEKDAY_LONG[(int) $start->format('N')]) ?>, <?= e(fmt_date($start->format('Y-m-d'))) ?> às <?= e($start->format('H:i')) ?> — <?= e(AgendaStatus::label((string) $a['status'])) ?></p>

<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!$canChange): ?>
  <p class="aviso aviso-erro">Esta consulta não pode ser remarcada.</p>
<?php elseif ($prof === null): ?>
  <p>Nenhuma agenda disponível.</p>
<?php else: ?>
  <form method="get" action="<?= e(url('/agenda/consultas/' . (int) $a['id'] . '/remarcar')) ?>" class="agenda-filtro">
    <?php if (count($agendas) > 1): ?>
      <label for="agenda-prof">Profissional</label>
      <select id="agenda-prof" name="prof">
        <?php foreach ($agendas as $ag): ?>
          <option value="<?= (int) $ag['id'] ?>"<?= (int) $ag['id'] === $prof ? ' selected' : '' ?>><?= e($ag['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input type="hidden" name="prof" value="<?= (int) $prof ?>">
    <?php endif; ?>
    <label for="agenda-data">Data</label>
    <input id="agenda-data" type="date" name="data" value="<?= e($date) ?>" min="<?= e($today) ?>">
    <button type="submit" class="btn btn-quiet">Ver horários</button>
  </form>
  <nav class="agenda-nav" aria-label="Dia">
    <a class="btn btn-quiet" href="<?= e($here(['prof' => $prof, 'data' => $prev])) ?>">◀ Dia anterior</a>
    <a class="btn btn-quiet" href="<?= e($here(['prof' => $prof, 'data' => $next])) ?>">Próximo dia ▶</a>
  </nav>
  <h2><?= e(AgendaStatus::WEEKDAY_LONG[AgendaStatus::weekday($date)]) ?>, <?= e(fmt_date($date)) ?></h2>
  <?php if ($refusal !== null): ?>
    <p class="aviso aviso-erro" role="alert"><?= e($refusal) ?></p>
  <?php elseif ($slots === []): ?>
    <p>Nenhum horário livre neste dia.</p>
  <?php else: ?>
    <ul class="agenda-horarios-livres">
      <?php foreach ($slots as $s): ?>
        <li><form method="post" action="<?= e(url('/agenda/consultas/' . (int) $a['id'] . '/remarcar')) ?>"><?= csrf_field() ?>
          <input type="hidden" name="prof" value="<?= (int) $prof ?>">
          <input type="hidden" name="inicio" value="<?= e($date . ' ' . $s['start']) ?>">
          <button type="submit" class="btn btn-quiet"><?= e($s['start']) ?></button></form></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
<p><a href="<?= e(url('/agenda/consultas/' . (int) $a['id'])) ?>">Voltar à consulta</a></p>
