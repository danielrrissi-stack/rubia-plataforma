<?php
/** @var array $patient @var string $first @var ?array $plan */
$pid = (int) $patient['id'];
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>Alimentação de <?= e($first) ?></h1>
<?php if ($plan === null): ?>
  <p class="aviso">Nenhum plano alimentar no momento.</p>
<?php else: ?>
  <p class="ajuda"><?= e($plan['title']) ?><?= $plan['published_at'] !== null ? ' · publicado em ' . e(fmt_date(substr((string) $plan['published_at'], 0, 10))) : '' ?></p>
  <?php $family = true; require dirname(__DIR__, 2) . '/Nutrition/views/_plan_body.php'; ?>
  <p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/alimentacao/imprimir')) ?>">Versão para imprimir</a></p>
<?php endif; ?>
