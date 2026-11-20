<?php
/** @var array $patient @var array $values @var string[] $errors @var ?array $latest @var ?array $results @var float $ageYears @var array $factors @var ?array $current @var array $history @var bool $canEdit */
$pid = (int) $patient['id'];
$labels = \App\Energy\EnergyCalculator::FORMULAS;
$isPreset = isset($factors[$values['fator']]);
?>
<h1>Energia — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="cartao"><h2>Meta atual</h2>
<?php if ($current === null): ?><p class="ajuda">Nenhuma meta energética definida.</p><?php else: ?>
  <p><strong><?= e($current['get_kcal']) ?> kcal/dia</strong> — <?= e($labels[$current['formula']]) ?>, fator <?= e(fmt_num((float) $current['activity_factor'], 3)) ?>, TMB <?= e($current['tmb_kcal']) ?> kcal (<?= e(fmt_date(substr((string) $current['created_at'], 0, 10))) ?>)</p>
<?php endif; ?>
</section>

<?php if ($canEdit): ?>
<h2>Calcular nova meta</h2>
<p class="ajuda">Idade para as fórmulas: <?= e(fmt_num($ageYears, 2)) ?> anos.
  <?= $latest !== null ? 'Última medida em ' . e(fmt_date($latest['measured_on'])) . '.' : 'Nenhuma medida registrada; digite peso e altura.' ?></p>
<form method="get" action="<?= e(url('/pacientes/' . $pid . '/energia')) ?>">
  <label>Peso (kg) <input name="peso" value="<?= e($values['peso']) ?>" inputmode="decimal"></label>
  <label>Altura (cm) <input name="altura" value="<?= e($values['altura']) ?>" inputmode="decimal"></label>
  <button type="submit" class="btn btn-quiet">Recalcular</button>
</form>

<form method="post" action="<?= e(url('/pacientes/' . $pid . '/energia')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="peso" value="<?= e($values['peso']) ?>">
  <input type="hidden" name="altura" value="<?= e($values['altura']) ?>">
  <?php if ($results !== null): ?>
  <table class="tabela">
    <thead><tr><th>Escolha</th><th>Fórmula</th><th>TMB (kcal/dia)</th></tr></thead>
    <tbody>
    <?php foreach ($labels as $key => $label): ?>
      <tr>
        <?php if ($results[$key] === null): ?>
          <td></td><td><?= e($label) ?></td><td>Não validada para menores de 18 anos</td>
        <?php else: ?>
          <td><input type="radio" name="formula" value="<?= e($key) ?>" id="f-<?= e($key) ?>" <?= $values['formula'] === $key ? 'checked' : '' ?> required></td>
          <td><label for="f-<?= e($key) ?>"><?= e($label) ?></label></td><td><?= e($results[$key]) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p class="ajuda">Informe peso e altura válidos e clique em Recalcular para ver as fórmulas.</p>
  <?php endif; ?>

  <p><label>Fator de atividade
    <select name="fator">
      <?php foreach ($factors as $val => $label): ?>
        <option value="<?= e((string) $val) ?>" <?= $values['fator'] === (string) $val ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
      <option value="outro" <?= !$isPreset ? 'selected' : '' ?>>Outro</option>
    </select></label>
    <label>Outro valor (1,0 a 2,5) <input name="fator_outro" value="<?= e($values['fator_outro']) ?>" inputmode="decimal"></label></p>
  <p><label>Observações<br><textarea name="notas" rows="3"><?= e($values['notas']) ?></textarea></label></p>
  <?php if ($results !== null): ?><p><button type="submit" class="btn">Salvar meta energética</button></p><?php endif; ?>
</form>
<?php endif; ?>

<h2>Histórico</h2>
<?php if ($history === []): ?><p>Nenhuma meta registrada.</p><?php else: ?>
<table class="tabela">
  <thead><tr><th>Data</th><th>Fórmula</th><th>Fator</th><th>TMB</th><th>GET</th><th>Profissional</th></tr></thead>
  <tbody>
  <?php foreach ($history as $g): ?>
    <tr>
      <td><?= e(fmt_date(substr((string) $g['created_at'], 0, 10))) ?></td>
      <td><?= e($labels[$g['formula']]) ?></td>
      <td><?= e(fmt_num((float) $g['activity_factor'], 3)) ?></td>
      <td><?= e($g['tmb_kcal']) ?> kcal</td>
      <td><?= e($g['get_kcal']) ?> kcal</td>
      <td><?= e($g['professional']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
