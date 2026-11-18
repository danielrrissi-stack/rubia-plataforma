<?php
/**
 * Detalhes e acoes de uma consulta. Variaveis: $a (AppointmentRepository::find), $today, $canConfirm, $canFinish,
 * $canUndo, $canChange, $evolution, $unreleased, $createdBy (?array).
 */
use App\Agenda\AgendaStatus;

$start = new \DateTimeImmutable((string) $a['starts_at']);
$day = $start->format('Y-m-d');
$pid = (int) $a['patient_id'];
$name = $a['social_name'] ?: $a['patient_name'];
$status = (string) $a['status'];
$action = static fn (string $suffix): string => url('/agenda/consultas/' . (int) $a['id'] . $suffix);
?>
<h1>Consulta — <?= e($name) ?></h1>
<dl class="agenda-detalhes">
  <dt>Paciente</dt>
  <dd><?= e($name) ?><?= $a['social_name'] ? ' <span class="ajuda">(' . e($a['patient_name']) . ')</span>' : '' ?> — <?= e(\App\Growth\Age::label((string) $a['birth_date'], $day)) ?></dd>
  <dt>Profissional</dt>
  <dd><?= e($a['professional_name']) ?></dd>
  <dt>Data</dt>
  <dd><?= e(AgendaStatus::WEEKDAY_LONG[(int) $start->format('N')]) ?>, <?= e(fmt_date($day)) ?>, <?= e($start->format('H:i')) ?>–<?= e(substr((string) $a['ends_at'], 11, 5)) ?></dd>
  <dt>Tipo</dt>
  <dd><?= e(AgendaStatus::kindLabel((string) $a['kind'])) ?></dd>
  <dt>Situação</dt>
  <dd><span class="selo"><?= e(AgendaStatus::label($status)) ?></span></dd>
  <?php if ($a['note'] !== null && $a['note'] !== ''): ?>
    <dt>Observação</dt>
    <dd><?= e($a['note']) ?></dd>
  <?php endif; ?>
  <?php if ($status === 'cancelada' && $a['cancel_reason'] !== null): ?>
    <dt>Motivo do cancelamento</dt>
    <dd><?= e($a['cancel_reason']) ?></dd>
  <?php endif; ?>
  <?php if ($createdBy !== null): ?>
    <dt>Marcada por</dt>
    <dd><?= e($createdBy['name']) ?> em <?= e(fmt_date((string) $a['created_at'])) ?></dd>
  <?php endif; ?>
</dl>
<?php if ($a['rescheduled_from_id'] !== null): ?>
  <p><a href="<?= e(url('/agenda/consultas/' . (int) $a['rescheduled_from_id'])) ?>">Ver a consulta anterior (remarcada)</a></p>
<?php endif; ?>
<?php if ($a['rescheduled_to_id'] !== null): ?>
  <p><a href="<?= e(url('/agenda/consultas/' . (int) $a['rescheduled_to_id'])) ?>">Ver a nova consulta</a></p>
<?php endif; ?>
<?php if ($unreleased): ?>
  <p class="aviso aviso-atencao">Este paciente não está liberado para a nutricionista. Remarque com outra profissional ou peça à médica para liberar.</p>
<?php endif; ?>

<?php if (AgendaStatus::remindable($status)): ?>
  <div class="agenda-acoes"><?php include __DIR__ . '/_whatsapp.php'; ?></div>
<?php endif; ?>

<div class="agenda-acoes">
  <?php if ($canConfirm): ?>
    <form method="post" action="<?= e($action('/situacao')) ?>" class="form-inline"><?= csrf_field() ?><input type="hidden" name="situacao" value="confirmada"><button type="submit" class="btn">Confirmar</button></form>
  <?php endif; ?>
  <?php if ($canFinish): ?>
    <form method="post" action="<?= e($action('/situacao')) ?>" class="form-inline"><?= csrf_field() ?><input type="hidden" name="situacao" value="realizada"><button type="submit" class="btn">Realizada</button></form>
    <form method="post" action="<?= e($action('/situacao')) ?>" class="form-inline" data-confirm="Marcar que o paciente faltou?"><?= csrf_field() ?><input type="hidden" name="situacao" value="faltou"><button type="submit" class="btn btn-quiet">Faltou</button></form>
  <?php endif; ?>
  <?php if ($canUndo): ?>
    <form method="post" action="<?= e($action('/situacao')) ?>" class="form-inline"><?= csrf_field() ?><input type="hidden" name="situacao" value="agendada"><button type="submit" class="btn btn-quiet">Desfazer (voltar para Agendada)</button></form>
  <?php endif; ?>
  <?php if ($canChange): ?>
    <a class="btn btn-quiet" href="<?= e($action('/remarcar')) ?>">Remarcar</a>
  <?php endif; ?>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid)) ?>">Abrir ficha</a>
  <?php if ($evolution): ?>
    <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/prontuario/evolucao')) ?>">Registrar evolução</a>
  <?php endif; ?>
</div>

<?php if ($canChange): ?>
  <h2>Cancelar consulta</h2>
  <form method="post" action="<?= e($action('/cancelar')) ?>" class="agenda-form" data-confirm="Cancelar esta consulta? O horário fica livre.">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="agenda-motivo">Motivo</label>
      <input id="agenda-motivo" type="text" name="motivo" maxlength="255" required>
      <p class="ajuda">Só a equipe vê o motivo.</p>
    </div>
    <button type="submit" class="btn btn-quiet">Cancelar consulta</button>
  </form>
<?php endif; ?>

<p><a href="<?= e(url('/agenda') . '?' . http_build_query(['prof' => (int) $a['professional_id'], 'data' => $day], '', '&', PHP_QUERY_RFC3986)) ?>">Voltar à agenda</a></p>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
