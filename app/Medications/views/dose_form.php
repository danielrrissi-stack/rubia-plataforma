<?php
/** @var array $patient @var array $rx @var array $values @var string[] $errors */
$rid = (int) $rx['id'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<h1>Alterar dose — <?= e($rx['drug_name']) ?></h1>
<p><a href="<?= e(url('/prescricoes/' . $rid)) ?>">Voltar à prescrição</a> · <?= e($patient['social_name'] ?: $patient['full_name']) ?></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<p class="ajuda">A dose anterior continua no histórico, com a data em que valeu.</p>
<form method="post" action="<?= e(url('/prescricoes/' . $rid . '/dose')) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="effective_date">Vale a partir de</label>
    <input type="date" id="effective_date" name="effective_date" value="<?= e($v('effective_date')) ?>" required></div>
  <div class="campo"><label for="dose">Dose</label>
    <input type="text" id="dose" name="dose" value="<?= e($v('dose')) ?>" maxlength="60" required></div>
  <div class="campo"><label for="frequency">Frequência</label>
    <input type="text" id="frequency" name="frequency" value="<?= e($v('frequency')) ?>" maxlength="120" required></div>
  <div class="campo"><label for="instructions">Orientação (opcional)</label>
    <textarea id="instructions" name="instructions" rows="2" maxlength="1000"><?= e($v('instructions')) ?></textarea></div>
  <div class="campo"><label for="reason">Motivo da mudança (opcional)</label>
    <input type="text" id="reason" name="reason" value="<?= e($v('reason')) ?>" maxlength="200"></div>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url('/prescricoes/' . $rid)) ?>">Cancelar</a>
</form>
