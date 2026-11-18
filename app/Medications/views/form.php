<?php
/**
 * @var array $patient @var ?array $rx @var array $values @var string[] $errors @var bool $canSecret @var list<string> $drugNames
 */
use App\Medications\Via;

$pid = (int) $patient['id'];
$isNew = $rx === null;
$action = $isNew ? '/pacientes/' . $pid . '/tratamento/nova' : '/prescricoes/' . (int) $rx['id'] . '/editar';
$back = $isNew ? '/pacientes/' . $pid . '/tratamento' : '/prescricoes/' . (int) $rx['id'];
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<h1><?= $isNew ? 'Nova prescrição' : 'Editar prescrição' ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url($back)) ?>">Voltar</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="drug_name">Medicamento</label>
    <input type="text" id="drug_name" name="drug_name" value="<?= e($v('drug_name')) ?>" maxlength="120" list="drug-names" required autocomplete="off">
    <datalist id="drug-names"><?php foreach ($drugNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist></div>
  <div class="campo"><label for="route">Via</label>
    <select id="route" name="route">
      <?php foreach (Via::ROUTES as $k): ?><option value="<?= e($k) ?>"<?= $v('route') === $k ? ' selected' : '' ?>><?= e(Via::LABELS[$k]) ?></option><?php endforeach; ?>
    </select></div>
  <?php if ($isNew): ?>
    <div class="campo"><label for="dose">Dose</label>
      <input type="text" id="dose" name="dose" value="<?= e($v('dose')) ?>" maxlength="60" required></div>
    <div class="campo"><label for="frequency">Frequência</label>
      <input type="text" id="frequency" name="frequency" value="<?= e($v('frequency')) ?>" maxlength="120" required></div>
    <div class="campo"><label for="instructions">Orientação (opcional)</label>
      <textarea id="instructions" name="instructions" rows="2" maxlength="1000"><?= e($v('instructions')) ?></textarea></div>
  <?php else: ?>
    <p class="ajuda">Para mudar dose, frequência ou orientação, use "Alterar dose" na prescrição: o histórico fica guardado.</p>
  <?php endif; ?>
  <div class="campo"><label for="start_date">Início</label>
    <input type="date" id="start_date" name="start_date" value="<?= e($v('start_date')) ?>" required></div>
  <div class="campo"><label for="end_date">Término (opcional)</label>
    <input type="date" id="end_date" name="end_date" value="<?= e($v('end_date')) ?>"></div>
  <div class="campo"><label for="next_review_date">Próxima avaliação (opcional)</label>
    <input type="date" id="next_review_date" name="next_review_date" value="<?= e($v('next_review_date')) ?>"></div>
  <div class="campo"><label for="notes">Observações internas (opcional)</label>
    <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= e($v('notes')) ?></textarea>
    <p class="ajuda">Só a equipe médica vê.</p></div>
  <div class="campo"><label><input type="checkbox" name="family_logs" value="1"<?= (int) ($values['family_logs'] ?? 0) === 1 ? ' checked' : '' ?>> A família pode registrar doses dadas</label></div>
  <?php if ($canSecret): ?>
    <div class="campo"><label><input type="checkbox" name="secret" value="1"<?= (int) ($values['secret'] ?? 0) === 1 ? ' checked' : '' ?>> Sigilosa</label>
      <p class="ajuda">Os responsáveis não veem esta prescrição, as doses registradas nela nem os efeitos ligados a ela.</p></div>
  <?php endif; ?>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url($back)) ?>">Cancelar</a>
</form>
