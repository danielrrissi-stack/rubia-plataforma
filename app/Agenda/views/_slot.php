<?php
/**
 * Uma linha da grade (dayView decorado). Variaveis: $row, $prof (int), $slotDay ('Y-m-d').
 * Estados: livre (link Marcar), passado (livre que ja passou), marcado (consulta; riscada = cancelada/remarcada,
 * nao ocupa o horario). `hidden` = nutricionista vendo paciente sem liberacao: sem nome, link nem WhatsApp.
 */
use App\Agenda\AgendaStatus;

$hora = e($row['start']);
if ($row['state'] === 'livre'): ?>
  <li class="agenda-slot agenda-livre"><span class="agenda-hora"><?= $hora ?></span> <span class="ajuda">livre</span>
    <a class="btn btn-quiet" href="<?= e(url('/agenda/marcar') . '?' . http_build_query(['prof' => $prof, 'inicio' => $slotDay . ' ' . $row['start']], '', '&', PHP_QUERY_RFC3986)) ?>">Marcar</a></li>
<?php elseif ($row['state'] === 'passado'): ?>
  <li class="agenda-slot agenda-passado"><span class="agenda-hora"><?= $hora ?></span> <span class="ajuda">livre (já passou)</span></li>
<?php else:
    $a = $row['appointment'];
    $flags = $row['flags'];
    $riscada = in_array('riscada', $flags, true);
    $destaque = !$riscada && (in_array('em_fechamento', $flags, true) || in_array('fora_do_horario', $flags, true));
    $status = (string) $a['status']; ?>
  <li class="agenda-slot agenda-marcado<?= $riscada ? ' agenda-riscada' : '' ?><?= $destaque ? ' agenda-destaque' : '' ?>">
    <span class="agenda-hora"><?= $hora ?>–<?= e($row['end']) ?></span>
    <?php if ($row['hidden']): ?>
      <span class="agenda-paciente">Paciente sem liberação</span>
      <span class="selo"><?= e(AgendaStatus::label($status)) ?></span>
    <?php else: ?>
      <a class="agenda-paciente" href="<?= e(url('/agenda/consultas/' . (int) $a['id'])) ?>"><?= e($a['social_name'] ?: $a['patient_name']) ?></a>
      <span class="ajuda"><?= e(\App\Growth\Age::label((string) $a['birth_date'], $slotDay)) ?> · <?= e(AgendaStatus::kindLabel((string) $a['kind'])) ?></span>
      <span class="selo"><?= e(AgendaStatus::label($status)) ?></span>
      <?php if (!$riscada && in_array('em_fechamento', $flags, true)): ?><span class="selo selo-alerta">em período fechado</span><?php endif; ?>
      <?php if (!$riscada && in_array('fora_do_horario', $flags, true)): ?><span class="selo selo-alerta">fora do horário atual</span><?php endif; ?>
      <?php if (!$riscada && $row['unreleased']): ?><span class="selo selo-alerta">sem liberação para a nutricionista</span><?php endif; ?>
      <?php if (AgendaStatus::remindable($status)): ?>
        <span class="agenda-acoes"><?php include __DIR__ . '/_whatsapp.php'; ?>
          <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . (int) $a['patient_id'])) ?>">Abrir ficha</a></span>
      <?php endif; ?>
    <?php endif; ?>
  </li>
<?php endif;
