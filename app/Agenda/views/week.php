<?php
/**
 * Agenda da semana (segunda a domingo). Variaveis: as mesmas de day.php.
 */
use App\Agenda\AgendaStatus;

include __DIR__ . '/_header.php';
if ($prof !== null): ?>
<div class="agenda-semana">
  <?php foreach ($days as $slotDay): ?>
    <section class="agenda-coluna<?= $slotDay === $today ? ' agenda-hoje' : '' ?>">
      <h2><a href="<?= e(url('/agenda') . '?' . http_build_query(['prof' => $prof, 'data' => $slotDay, 'vista' => 'dia'], '', '&', PHP_QUERY_RFC3986)) ?>"><?= e(AgendaStatus::WEEKDAY_SHORT[AgendaStatus::weekday($slotDay)]) ?> <?= e(substr(fmt_date($slotDay), 0, 5)) ?></a></h2>
      <?php if ($grid[$slotDay] === []): ?>
        <p class="ajuda">Sem horários.</p>
      <?php else: ?>
        <ul class="agenda-dia">
          <?php foreach ($grid[$slotDay] as $row) { include __DIR__ . '/_slot.php'; } ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
<?php endif;
