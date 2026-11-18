<?php
/**
 * "Meus horarios": semana-modelo, duracao, endereco e horarios extras (quem edita a semana); fechamentos
 * (tambem a secretaria). Variaveis: $agendas, $prof, $professional (?array), $label (titulo), $canEditWeek,
 * $canAddExtra (lancar/remover horario extra), $hasRanges, $ranges
 * (linhas do formulario, com linhas em branco no fim), $slotMinutes, $address, $exceptions, $errors,
 * $form (formulario com erro: 'week'|'closure'|'extra'|null), $values (valores do formulario com erro).
 */
use App\Agenda\AgendaStatus;

$hidden = '<input type="hidden" name="prof" value="' . (int) $prof . '">';
$closureVals = $form === 'closure' ? $values : [];
$extraVals = $form === 'extra' ? $values : [];
$filled = array_values(array_filter($ranges, fn (array $x): bool => $x['start_time'] !== '' || $x['end_time'] !== ''));
?>
<h1><?= e($label) ?></h1>
<?php $selectAction = '/agenda/horarios'; $selectHidden = []; include __DIR__ . '/_prof_select.php'; ?>
<p><a href="<?= e(url('/agenda') . '?prof=' . (int) $prof) ?>">Ver a agenda</a></p>

<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<h2>Semana-modelo</h2>
<?php if ($canEditWeek): ?>
  <?php if (!$hasRanges): ?>
    <form method="post" action="<?= e(url('/agenda/horarios/padrao')) ?>" class="agenda-form">
      <?= csrf_field() ?><?= $hidden ?>
      <p class="ajuda">Seg a sex 07:30–11:30 e 12:30–20:00; sáb 07:30–12:30; consultas de 30 minutos.</p>
      <button type="submit" class="btn">Usar horário padrão da clínica</button>
    </form>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/agenda/horarios')) ?>" class="agenda-form">
    <?= csrf_field() ?><?= $hidden ?>
    <div class="tabela-rolagem">
      <table class="agenda-faixas">
        <thead><tr><th scope="col">Dia</th><th scope="col">Início</th><th scope="col">Fim</th></tr></thead>
        <tbody>
          <?php foreach ($ranges as $i => $x): ?>
            <tr>
              <td><select name="ranges[<?= (int) $i ?>][weekday]" aria-label="Dia da semana">
                <option value="">—</option>
                <?php foreach (AgendaStatus::WEEKDAY_LONG as $n => $label): ?>
                  <option value="<?= (int) $n ?>"<?= (string) $x['weekday'] === (string) $n ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select></td>
              <td><input type="time" name="ranges[<?= (int) $i ?>][start_time]" value="<?= e($x['start_time']) ?>" aria-label="Início"></td>
              <td><input type="time" name="ranges[<?= (int) $i ?>][end_time]" value="<?= e($x['end_time']) ?>" aria-label="Fim"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="ajuda">Para apagar uma faixa, deixe início e fim em branco. Mudanças valem para frente: consultas já marcadas não mudam.</p>
    <div class="campo">
      <label for="agenda-minutos">Duração da consulta (minutos)</label>
      <input id="agenda-minutos" type="number" name="slot_minutes" value="<?= e($slotMinutes) ?>" min="10" max="240" step="5" required>
    </div>
    <div class="campo">
      <label for="agenda-endereco">Endereço de atendimento</label>
      <input id="agenda-endereco" type="text" name="address" value="<?= e($address) ?>" maxlength="200">
      <p class="ajuda">Vai no aviso da consulta. Em branco, usa o endereço da clínica.</p>
    </div>
    <button type="submit" class="btn">Salvar horários</button>
  </form>
<?php else: ?>
  <?php if ($filled === []): ?>
    <p>Sem horários cadastrados.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($filled as $x): ?>
        <li><?= e(AgendaStatus::WEEKDAY_LONG[(int) $x['weekday']] ?? '') ?> <?= e($x['start_time']) ?>–<?= e($x['end_time']) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="ajuda">Consultas de <?= e($slotMinutes) ?> minutos. Só a profissional ou a médica administradora mudam a semana-modelo.</p>
  <?php endif; ?>
<?php endif; ?>

<h2>Fechamentos e horários extras (próximos 12 meses)</h2>
<?php if ($exceptions === []): ?>
  <p>Nenhum.</p>
<?php else: ?>
  <ul class="agenda-excecoes">
    <?php foreach ($exceptions as $x): ?>
      <li>
        <?php if ((string) $x['kind'] === 'fechado'): ?>
          <strong>Fechado</strong> <?= e(fmt_date((string) $x['start_date'])) ?><?= $x['end_date'] !== $x['start_date'] ? ' a ' . e(fmt_date((string) $x['end_date'])) : '' ?>,
          <?= $x['start_time'] !== null ? e(substr((string) $x['start_time'], 0, 5)) . '–' . e(substr((string) $x['end_time'], 0, 5)) : 'dia inteiro' ?>
          <?= $x['reason'] !== null ? ' — ' . e($x['reason']) : '' ?>
        <?php else: ?>
          <strong>Horário extra</strong> <?= e(fmt_date((string) $x['start_date'])) ?>, <?= e(substr((string) $x['start_time'], 0, 5)) ?>–<?= e(substr((string) $x['end_time'], 0, 5)) ?>
        <?php endif; ?>
        <?php if ((string) $x['kind'] === 'fechado' || $canAddExtra): ?>
          <form method="post" action="<?= e(url('/agenda/horarios/excecao/' . (int) $x['id'] . '/remover')) ?>" class="form-inline" data-confirm="Remover?"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Remover</button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2>Novo fechamento</h2>
<form method="post" action="<?= e(url('/agenda/horarios/fechamento')) ?>" class="agenda-form">
  <?= csrf_field() ?><?= $hidden ?>
  <div class="agenda-linha">
    <div class="campo"><label for="f-ini">De</label><input id="f-ini" type="date" name="start_date" value="<?= e((string) ($closureVals['start_date'] ?? '')) ?>" required></div>
    <div class="campo"><label for="f-fim">Até</label><input id="f-fim" type="date" name="end_date" value="<?= e((string) ($closureVals['end_date'] ?? '')) ?>" required></div>
    <div class="campo"><label for="f-hi">Das (opcional)</label><input id="f-hi" type="time" name="start_time" value="<?= e((string) ($closureVals['start_time'] ?? '')) ?>"></div>
    <div class="campo"><label for="f-hf">Às (opcional)</label><input id="f-hf" type="time" name="end_time" value="<?= e((string) ($closureVals['end_time'] ?? '')) ?>"></div>
  </div>
  <div class="campo"><label for="f-motivo">Motivo (opcional)</label><input id="f-motivo" type="text" name="reason" value="<?= e((string) ($closureVals['reason'] ?? '')) ?>" maxlength="120"></div>
  <p class="ajuda">Sem horário = dia inteiro. Consultas já marcadas no período não são canceladas: continuam na agenda, destacadas, para remarcar.</p>
  <button type="submit" class="btn">Lançar fechamento</button>
</form>

<?php if ($canAddExtra): ?>
<h2>Novo horário extra</h2>
<form method="post" action="<?= e(url('/agenda/horarios/extra')) ?>" class="agenda-form">
  <?= csrf_field() ?><?= $hidden ?>
  <div class="agenda-linha">
    <div class="campo"><label for="x-dia">Dia</label><input id="x-dia" type="date" name="date" value="<?= e((string) ($extraVals['date'] ?? '')) ?>" required></div>
    <div class="campo"><label for="x-hi">Das</label><input id="x-hi" type="time" name="start_time" value="<?= e((string) ($extraVals['start_time'] ?? '')) ?>" required></div>
    <div class="campo"><label for="x-hf">Às</label><input id="x-hf" type="time" name="end_time" value="<?= e((string) ($extraVals['end_time'] ?? '')) ?>" required></div>
  </div>
  <button type="submit" class="btn">Lançar horário extra</button>
</form>
<?php endif; ?>
<script src="<?= e(url('/assets/js/confirm-submit.js')) ?>" defer></script>
