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

<?php if ($plan['mode'] === 'estruturado' && $plan['total_kcal'] !== null): ?>
  <p class="ajuda">Total do dia: <?= e(fmt_num((float) $plan['total_kcal'], 0)) ?> kcal · proteína <?= e(fmt_num($plan['total_protein_g'] === null ? null : (float) $plan['total_protein_g'])) ?> g · carboidrato <?= e(fmt_num($plan['total_carb_g'] === null ? null : (float) $plan['total_carb_g'])) ?> g · gordura <?= e(fmt_num($plan['total_fat_g'] === null ? null : (float) $plan['total_fat_g'])) ?> g (calculado na publicação)</p>
<?php endif; ?>
<section class="cartao plano-leitura"><?php require __DIR__ . '/_plan_body.php'; ?></section>

<?php if ($canEdit): ?>
<section class="cartao"><h2>Salvar como modelo</h2>
  <p class="ajuda">Cria um modelo da equipe com o conteúdo deste plano (sem dados do paciente).</p>
  <form method="post" action="<?= e(url('/planos/' . $plan['id'] . '/salvar-como-modelo')) ?>"><?= csrf_field() ?>
    <label>Título do modelo <input name="titulo" value="<?= e($plan['title']) ?>" maxlength="150" required data-ditado></label>
    <button type="submit" class="btn btn-quiet">Salvar como modelo</button>
  </form>
</section>
<?php endif; ?>

<?php if ($canEdit && $plan['status'] === 'publicado'): ?>
<section class="cartao"><h2>Ações</h2>
  <form method="post" action="<?= e(url('/planos/' . $plan['id'] . '/novo-rascunho')) ?>"><?= csrf_field() ?>
    <button type="submit" class="btn">Editar (novo rascunho a partir deste)</button></form>
  <h3>Encerrar plano</h3>
  <?php if ($endError !== null): ?><p class="aviso aviso-erro" role="alert"><?= e($endError) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/planos/' . $plan['id'] . '/encerrar')) ?>"><?= csrf_field() ?>
    <label>Motivo <input name="motivo" maxlength="255" value="<?= e($motivo) ?>" required data-ditado></label>
    <button type="submit" class="btn btn-quiet">Encerrar plano</button>
  </form>
</section>
<?php endif; ?>
