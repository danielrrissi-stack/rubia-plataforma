<?php
/** @var array $patient @var string $first @var ?array $plan @var ?array $week */
$pid = (int) $patient['id'];
?>
<p class="portal-links"><a href="<?= e(url('/portal/p/' . $pid)) ?>">&larr; Voltar</a></p>
<h1>Alimentação de <?= e($first) ?></h1>
<?php if ($plan === null): ?>
  <p class="aviso">Nenhum plano alimentar no momento.</p>
<?php else: ?>
  <p class="ajuda"><?= $week !== null ? 'Plano alimentar' : e($plan['title']) ?><?= $plan['published_at'] !== null ? ' · publicado em ' . e(fmt_date(substr((string) $plan['published_at'], 0, 10))) : '' ?></p>
  <?php if ($week !== null): ?>
  <nav class="portal-dias" aria-label="Dias do cardápio"><ul>
    <?php foreach ($week['nav'] as $n): ?><li><a href="#dia-<?= e($n['date']) ?>"><?= e($n['label']) ?></a></li><?php endforeach; ?>
  </ul></nav>
  <?php $dayIndexes = $week['indexes']; $dayDates = $week['dates']; endif; ?>
  <?php $family = true; require dirname(__DIR__, 2) . '/Nutrition/views/_plan_body.php'; ?>
  <?php if ($week !== null && $week['list'] !== []): $list = $week['list']; require dirname(__DIR__, 2) . '/Nutrition/views/_shopping_list.php'; endif; ?>
  <p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/alimentacao/imprimir')) ?>">Versão para imprimir</a></p>
<?php endif; ?>
