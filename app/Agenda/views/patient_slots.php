<?php
/**
 * Proximos horarios livres de uma profissional para um paciente (60 dias).
 * Variaveis: $patient, $agendas, $prof (?int), $professional (?array), $refusal (?string), $slots (list<array{date,start}>).
 */
use App\Agenda\AgendaStatus;

$pid = (int) $patient['id'];
?>
<h1>Agendar — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<?php if ($prof === null): ?>
  <p>Nenhuma agenda disponível.</p>
<?php else: ?>
  <?php $selectAction = '/pacientes/' . $pid . '/agendar'; $selectHidden = []; include __DIR__ . '/_prof_select.php'; ?>
  <h2>Próximos horários livres — <?= e($professional['name'] ?? '') ?></h2>
  <?php if ($refusal !== null): ?>
    <p class="aviso aviso-erro" role="alert"><?= e($refusal) ?></p>
  <?php elseif ($slots === []): ?>
    <p>Nenhum horário livre nos próximos 60 dias.</p>
  <?php else: ?>
    <ul class="agenda-resultados">
      <?php foreach ($slots as $s): ?>
        <li><?= e(AgendaStatus::WEEKDAY_SHORT[AgendaStatus::weekday($s['date'])]) ?> <?= e(fmt_date($s['date'])) ?> às <?= e($s['start']) ?>
          <a class="btn btn-quiet" href="<?= e(url('/agenda/marcar') . '?' . http_build_query(['prof' => $prof, 'inicio' => $s['date'] . ' ' . $s['start'], 'paciente' => $pid], '', '&', PHP_QUERY_RFC3986)) ?>">Marcar</a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar à ficha</a></p>
