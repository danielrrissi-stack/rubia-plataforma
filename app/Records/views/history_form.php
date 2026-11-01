<?php
/** @var array $patient @var string $kind @var string $label @var array $values @var string[] $errors @var ?array $entry @var string $action */
$pid = (int) $patient['id'];
?>
<h1><?= e($label) ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/prontuario')) ?>">Voltar ao prontuário</a></p>
<?php if ($entry !== null): ?><p class="ajuda">Corrigir cria a versão <?= (int) $entry['version'] + 1 ?>; a versão atual é mantida no histórico.</p><?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
<?php if ($kind === 'historico_familiar'): ?>
  <table class="tabela">
    <thead><tr><th>Condição</th><th>Parentesco</th></tr></thead>
    <tbody>
    <?php foreach (\App\Records\RecordController::CONDITIONS as $i => $c): ?>
      <tr>
        <td><label><input type="checkbox" name="cond[<?= e($c) ?>]" value="1" <?= $values['cond'][$c] ? 'checked' : '' ?>> <?= e(ucfirst($c)) ?></label></td>
        <td>
          <?php foreach (\App\Records\RecordController::KINSHIP as $k): ?>
            <label><input type="checkbox" name="parentesco[<?= e($c) ?>][]" value="<?= e($k) ?>" <?= in_array($k, $values['parentesco'][$c], true) ? 'checked' : '' ?>> <?= e($k) ?></label>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><label>Observações<br><textarea name="observacoes" rows="4" maxlength="20000"><?= e($values['observacoes']) ?></textarea></label></p>
<?php elseif ($kind === 'gestacional'): ?>
  <p><label>Peso ao nascer (g) <input name="peso_nascer_g" value="<?= e($values['peso_nascer_g']) ?>" inputmode="numeric"></label></p>
  <p><label>Comprimento ao nascer (cm) <input name="comprimento_nascer_cm" value="<?= e($values['comprimento_nascer_cm']) ?>" inputmode="decimal"></label></p>
  <p><label>Idade gestacional (semanas) <input name="idade_gestacional_semanas" value="<?= e($values['idade_gestacional_semanas']) ?>" inputmode="numeric"></label></p>
  <p><label>Tipo de parto
    <select name="tipo_parto">
      <option value="">—</option>
      <?php foreach (['normal' => 'Normal', 'cesárea' => 'Cesárea'] as $val => $text): ?>
        <option value="<?= e($val) ?>" <?= $values['tipo_parto'] === $val ? 'selected' : '' ?>><?= e($text) ?></option>
      <?php endforeach; ?>
    </select></label></p>
  <p><label>Aleitamento materno (meses) <input name="aleitamento_materno_meses" value="<?= e($values['aleitamento_materno_meses']) ?>" inputmode="numeric"></label></p>
  <p><label><input type="checkbox" name="diabetes_gestacional" value="1" <?= $values['diabetes_gestacional'] === '1' ? 'checked' : '' ?>> Diabetes gestacional</label></p>
  <p><label>Intercorrências<br><textarea name="intercorrencias" rows="4" maxlength="20000"><?= e($values['intercorrencias']) ?></textarea></label></p>
<?php else: ?>
  <p><label for="texto"><?= e($label) ?></label><br><textarea id="texto" name="texto" rows="8" maxlength="20000" required><?= e($values['texto']) ?></textarea></p>
<?php endif; ?>
  <p><button type="submit" class="btn"><?= $entry === null ? 'Salvar' : 'Salvar correção' ?></button></p>
</form>
