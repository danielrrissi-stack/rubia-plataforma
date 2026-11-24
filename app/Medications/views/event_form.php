<?php
/** @var array $patient @var array $values @var string[] $errors @var list<array> $prescriptions */
$pid = (int) $patient['id'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<h1>Registrar efeito — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/tratamento')) ?>">Voltar ao tratamento</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('/pacientes/' . $pid . '/efeitos/novo')) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="occurred_on">Data</label>
    <input type="date" id="occurred_on" name="occurred_on" value="<?= e($v('occurred_on')) ?>" required></div>
  <div class="campo"><label for="prescription_id">Medicamento (opcional)</label>
    <select id="prescription_id" name="prescription_id">
      <option value="">—</option>
      <?php foreach ($prescriptions as $p): ?>
        <option value="<?= e($p['id']) ?>"<?= $v('prescription_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['drug_name']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="campo"><label for="description">O que aconteceu</label>
    <textarea id="description" name="description" rows="4" maxlength="2000" required><?= e($v('description')) ?></textarea></div>
  <div class="campo"><label for="severity">Gravidade (opcional)</label>
    <select id="severity" name="severity">
      <option value="">—</option>
      <?php foreach (['leve', 'moderada', 'grave'] as $sv): ?><option value="<?= e($sv) ?>"<?= $v('severity') === $sv ? ' selected' : '' ?>><?= e($sv) ?></option><?php endforeach; ?>
    </select></div>
  <div class="campo"><label for="conduct">Conduta (opcional)</label>
    <textarea id="conduct" name="conduct" rows="3" maxlength="2000"><?= e($v('conduct')) ?></textarea></div>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url('/pacientes/' . $pid . '/tratamento')) ?>">Cancelar</a>
</form>
