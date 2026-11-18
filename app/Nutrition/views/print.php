<?php
/** @var array $plan @var array $patient @var bool $withTotals */
use App\Nutrition\MealPlanCalculator;

// Sem o quadro de totais a impressao pode ser entregue a familia: nenhum numero de calorias (nem por opcao).
$family = !$withTotals;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($plan['title']) ?> · Dra. Rubia</title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="impressao">
<p class="nao-imprimir"><button type="button" class="btn" data-print>Imprimir</button>
  <?php if ($plan['status'] !== 'rascunho'): ?><a class="btn" href="<?= e(url('/planos/' . $plan['id'] . '/pdf')) ?>">Baixar PDF</a><?php endif; ?>
  <a href="<?= e(url('/planos/' . $plan['id'])) ?>">Voltar</a></p>
<?php require __DIR__ . '/_plan_doc.php'; ?>
<?php if ($withTotals && $plan['mode'] === 'estruturado'): $day = MealPlanCalculator::day((array) $plan['structure']); $pc = MealPlanCalculator::macroPercents($day); ?>
<section class="plano-totais"><h3>Totais do dia (Opção 1 de cada refeição)</h3>
  <table class="tabela"><tbody>
    <tr><th>Energia</th><td><?= e(fmt_num($day['kcal'], 0)) ?> kcal</td></tr>
    <tr><th>Proteínas</th><td><?= e(fmt_num($day['protein'], 1)) ?> g (<?= e(fmt_num($pc['protein'], 1)) ?>%)</td></tr>
    <tr><th>Carboidratos</th><td><?= e(fmt_num($day['carb'], 1)) ?> g (<?= e(fmt_num($pc['carb'], 1)) ?>%)</td></tr>
    <tr><th>Gorduras</th><td><?= e(fmt_num($day['fat'], 1)) ?> g (<?= e(fmt_num($pc['fat'], 1)) ?>%)</td></tr>
    <tr><th>Fibras</th><td><?= e(fmt_num($day['fiber'], 1)) ?> g</td></tr>
  </tbody></table></section>
<?php endif; ?>
<script src="<?= e(url('/assets/js/print-button.js')) ?>" defer></script>
</body>
</html>
