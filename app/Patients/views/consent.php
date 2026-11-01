<?php
/** @var array $pending */
?>
<h1>Termo de consentimento</h1>
<p>Para continuar, leia e aceite o termo abaixo<?= count($pending) > 1 ? ' para cada paciente' : '' ?>.</p>
<?php foreach ($pending as $item): ?>
<section class="cartao">
  <?php if ($item['patient_name'] !== null): ?><h2>Paciente: <?= e($item['patient_name']) ?></h2><?php endif; ?>
  <?php /* body_html e redigido pela administracao (instalador); renderizado sem escape de proposito. */ ?>
  <div class="termo" tabindex="0"><?= $item['body_html'] ?></div>
  <form method="post" action="<?= e(url('/consentimento')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="term_version" value="<?= e($item['term_version']) ?>">
    <input type="hidden" name="patient_id" value="<?= e($item['patient_id']) ?>">
    <div class="campo">
      <label><input type="checkbox" name="aceito" value="1" required> Li e aceito</label>
    </div>
    <button type="submit" class="btn">Confirmar</button>
  </form>
</section>
<?php endforeach; ?>
