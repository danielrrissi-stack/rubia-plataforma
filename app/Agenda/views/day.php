<?php
/**
 * Agenda do dia. Variaveis: $agendas, $prof, $professional, $date, $today, $view, $days, $grid, $closures, $prev, $next.
 */
use App\Agenda\AgendaStatus;

include __DIR__ . '/_header.php';
if ($prof !== null):
    $slotDay = $date; ?>
<h2><?= e(AgendaStatus::WEEKDAY_LONG[AgendaStatus::weekday($date)]) ?>, <?= e(fmt_date($date)) ?><?= $date === $today ? ' (hoje)' : '' ?></h2>
<?php if ($grid[$date] === []): ?>
  <p>Nenhum horário neste dia.</p>
<?php else: ?>
  <ul class="agenda-dia">
    <?php foreach ($grid[$date] as $row) { include __DIR__ . '/_slot.php'; } ?>
  </ul>
<?php endif; ?>
<?php endif;
