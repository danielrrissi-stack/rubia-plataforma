<?php
/**
 * @var array $patient @var ?array $collection @var array $values @var string[] $errors
 * @var array<string,list<array>> $groups @var ?string $filesAgain
 */
$pid = (int) $patient['id'];
$isNew = $collection === null;
$action = $isNew ? '/pacientes/' . $pid . '/exames/nova' : '/exames/' . $collection['id'] . '/editar';
$back = $isNew ? '/pacientes/' . $pid . '/exames' : '/exames/' . $collection['id'];
$labels = \App\Labs\LabController::GROUPS;
?>
<h1><?= $isNew ? 'Nova coleta' : 'Editar coleta' ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($filesAgain !== null): ?><p class="aviso aviso-erro" role="alert"><?= e($filesAgain) ?></p><?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>"<?= $isNew ? ' enctype="multipart/form-data"' : '' ?>>
  <?= csrf_field() ?>
  <div class="campo">
    <label for="coletado_em">Data da coleta</label>
    <input type="date" id="coletado_em" name="coletado_em" value="<?= e($values['coletado_em']) ?>" max="<?= e(date('Y-m-d')) ?>" required>
  </div>
  <div class="campo">
    <label for="laboratorio">Laboratório (opcional)</label>
    <input type="text" id="laboratorio" name="laboratorio" value="<?= e($values['laboratorio']) ?>" maxlength="120">
  </div>
  <div class="campo">
    <label for="observacao">Observação (opcional)</label>
    <textarea id="observacao" name="observacao" rows="2"><?= e($values['observacao']) ?></textarea>
  </div>

  <p class="ajuda">Deixe em branco o que não veio no laudo. Use vírgula ou ponto para decimais; resultados como "&lt; 5" ou "indetectável" são aceitos como texto.</p>
  <?php foreach ($groups as $g => $tests): ?>
    <fieldset class="lab-grupo">
      <legend><?= e($labels[$g]) ?></legend>
      <?php foreach ($tests as $t): $tid = (int) $t['id']; $fid = 'valor_' . $tid; ?>
        <div class="campo lab-campo">
          <label for="<?= e($fid) ?>"><?= e($t['name']) ?><?php if ((int) $t['active'] !== 1): ?> <span class="ajuda">(exame desativado no catálogo)</span><?php endif; ?></label>
          <input type="text" inputmode="decimal" id="<?= e($fid) ?>" name="valor[<?= e($tid) ?>]" value="<?= e($values['valor'][$tid] ?? '') ?>" maxlength="60">
          <span class="lab-unidade"><?= e($t['unit']) ?></span>
        </div>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>

  <?php if ($isNew): ?>
    <div class="campo">
      <label for="laudos">Laudos (opcional)</label>
      <input type="file" id="laudos" name="laudos[]" multiple accept="application/pdf,image/jpeg,image/png">
      <p class="ajuda">PDF, JPG ou PNG, até 10 MB cada.</p>
    </div>
  <?php endif; ?>
  <button type="submit" class="btn">Salvar</button>
  <a class="btn btn-quiet" href="<?= e(url($back)) ?>">Cancelar</a>
</form>
