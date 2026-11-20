<?php
/**
 * Cabecalho + corpo do plano (impressao e PDF). @var array $plan @var array $patient
 * Sem $family definido (PDF), sai a versao para a familia: sem nenhum numero de calorias.
 */
$family = $family ?? true;
$name = $patient['social_name'] ?: $patient['full_name'];
?>
<header class="impressao-cabecalho">
  <h1><?= e($plan['title']) ?></h1>
  <p><?= e($name) ?><?= $plan['published_at'] !== null ? ' · publicado em ' . e(fmt_date(substr((string) $plan['published_at'], 0, 10))) : ' · rascunho' ?></p>
</header>
<?php require __DIR__ . '/_plan_body.php'; ?>
