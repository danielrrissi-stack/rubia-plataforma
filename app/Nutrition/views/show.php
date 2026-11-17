<?php
/** @var array $plan @var array $patient @var bool $canEdit @var ?string $endError @var string $motivo */
$pid = (int) $patient['id'];
$status = ['rascunho' => 'Rascunho', 'publicado' => 'Vigente', 'substituido' => 'Substituído', 'encerrado' => 'Encerrado'];
$family = false;
?>
<h1><?= e($plan['title']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid . '/plano')) ?>">Voltar ao plano alimentar</a> ·
  <?= e($patient['social_name'] ?: $patient['full_name']) ?> · <span class="selo"><?= e($status[$plan['status']]) ?></span></p>
<?php if ($plan['ended_at'] !== null): ?><p class="ajuda">Encerrado em <?= e(fmt_date(substr((string) $plan['ended_at'], 0, 10))) ?>: <?= e($plan['ended_reason']) ?></p><?php endif; ?>

<p>
  <a class="btn btn-quiet" href="<?= e(url('/planos/' . $plan['id'] . '/imprimir')) ?>">Imprimir</a>
  <?php if ($plan['mode'] === 'estruturado'): ?><a class="btn btn-quiet" href="<?= e(url('/planos/' . $plan['id'] . '/imprimir?totais=1')) ?>">Imprimir com totais</a><?php endif; ?>
  <?php if ($canEdit && $plan['status'] === 'rascunho'): ?>
    <a class="btn" href="<?= e(url('/planos/' . $plan['id'] . '/editar')) ?>">Continuar editando</a>
  <?php endif; ?>
</p>

<?php if ($canEdit && $plan['status'] === 'rascunho'): ?>
<div class="acoes-plano">
  <form class="form-inline" method="post" action="<?= e(url('/planos/' . $plan['id'] . '/publicar')) ?>"><?= csrf_field() ?><button type="submit" class="btn">Publicar</button></form>
  <form class="form-inline" method="post" action="<?= e(url('/planos/' . $plan['id'] . '/descartar')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-quiet">Descartar rascunho</button></form>
</div>
<?php endif; ?>

<section class="cartao plano-leitura"><?php require __DIR__ . '/_plan_body.php'; ?></section>

<?php if ($canEdit && $plan['status'] === 'publicado'): ?>
<section class="cartao"><h2>Ações</h2>
  <form method="post" action="<?= e(url('/planos/' . $plan['id'] . '/novo-rascunho')) ?>"><?= csrf_field() ?>
    <button type="submit" class="btn">Editar (novo rascunho a partir deste)</button></form>
  <h3>Encerrar plano</h3>
  <?php if ($endError !== null): ?><p class="aviso aviso-erro" role="alert"><?= e($endError) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/planos/' . $plan['id'] . '/encerrar')) ?>"><?= csrf_field() ?>
    <label>Motivo <input name="motivo" maxlength="255" value="<?= e($motivo) ?>" required></label>
    <button type="submit" class="btn btn-quiet">Encerrar plano</button>
  </form>
</section>
<?php endif; ?>
