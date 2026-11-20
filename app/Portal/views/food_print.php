<?php
/** @var array $patient @var string $first @var ?array $plan */
$pid = (int) $patient['id'];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Plano alimentar · Dra. Rubia</title>
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body class="impressao">
<p class="nao-imprimir"><button type="button" class="btn" data-print>Imprimir</button>
  <a href="<?= e(url('/portal/p/' . $pid . '/alimentacao')) ?>">Voltar</a></p>
<header class="impressao-cabecalho">
  <h1>Dra. Rubia</h1>
  <p>Plano alimentar de <?= e($first) ?></p>
<?php if ($plan !== null): ?>
  <p><?= e($plan['title']) ?><?= $plan['published_at'] !== null ? ' · Publicado em ' . e(fmt_date(substr((string) $plan['published_at'], 0, 10))) . ($plan['publisher'] !== '' ? ' por ' . e($plan['publisher']) : '') : '' ?></p>
<?php endif; ?>
</header>
<?php if ($plan === null): ?>
  <p>Nenhum plano alimentar no momento.</p>
<?php else: ?>
  <?php $family = true; require dirname(__DIR__, 2) . '/Nutrition/views/_plan_body.php'; ?>
<?php endif; ?>
<script src="<?= e(url('/assets/js/print-button.js')) ?>" defer></script>
</body>
</html>
