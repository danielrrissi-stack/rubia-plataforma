<?php
/** @var array $patient @var string $label @var array $values @var string[] $errors @var ?array $entry @var string $action */
$pid = (int) $patient['id'];
?>
<h1><?= $entry === null ? 'Nova evolução' : 'Corrigir evolução' ?> — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/prontuario')) ?>">Voltar ao prontuário</a></p>
<?php if ($entry !== null): ?><p class="ajuda">Corrigir cria a versão <?= (int) $entry['version'] + 1 ?>; a versão atual é mantida no histórico.</p><?php endif; ?>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<p class="ajuda" id="ditado-aviso" hidden>O ditado usa o reconhecimento de voz do navegador: o áudio vai para o Google (Chrome), a Microsoft (Edge) ou a Apple (Safari). Revise o texto antes de salvar.</p>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <p><label>Data do atendimento <input name="encounter_date" value="<?= e($values['encounter_date']) ?>" placeholder="dd/mm/aaaa"></label>
     <label>CID-10 (opcional) <input name="cid10" value="<?= e($values['cid10']) ?>" maxlength="10" placeholder="E66.0"></label></p>
  <?php foreach (\App\Records\RecordController::EVOLUTION_FIELDS as $f => $text): ?>
    <p><label for="<?= e($f) ?>"><?= e($text) ?></label>
      <button type="button" class="btn btn-quiet btn-ditar" data-dictate="<?= e($f) ?>" aria-pressed="false" hidden>🎤 Ditar</button><br>
      <textarea id="<?= e($f) ?>" name="<?= e($f) ?>" rows="4" maxlength="20000"><?= e($values[$f]) ?></textarea>
      <span class="ditado-status" id="ditado-<?= e($f) ?>" aria-live="polite"></span></p>
  <?php endforeach; ?>
  <p><button type="submit" class="btn"><?= $entry === null ? 'Salvar evolução' : 'Salvar correção' ?></button></p>
</form>
<script src="<?= e(url('/assets/js/dictation.js')) ?>"></script>
