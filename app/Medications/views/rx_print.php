<?php
/** @var array $patient @var array<string,list<array>> $groups @var array<string,string> $head @var string $doctorName @var string $register @var string $dateText */
use App\Medications\Via;

$pid = (int) $patient['id'];
$name = $patient['social_name'] ?: $patient['full_name'];
$n = 0;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Receituário · Dra. Rubia</title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="impressao">
<p class="nao-imprimir"><button type="button" class="btn" data-print>Imprimir</button>
  <a href="<?= e(url('/pacientes/' . $pid . '/receita')) ?>">Voltar</a></p>
<main class="receita">
<header class="receita-cabecalho">
  <?php if ($head['rx_clinic_name'] !== ''): ?><h1><?= e($head['rx_clinic_name']) ?></h1><?php endif; ?>
  <?php if ($head['rx_address'] !== ''): ?><p><?= e($head['rx_address']) ?></p><?php endif; ?>
  <?php if ($head['rx_phone'] !== ''): ?><p><?= e($head['rx_phone']) ?></p><?php endif; ?>
</header>
<h2 class="receita-titulo">Receituário</h2>
<p class="receita-paciente">Paciente: <?= e($name) ?></p>
<?php foreach ($groups as $route => $list): ?>
  <section class="receita-grupo">
    <h3><?= e(Via::RX_HEADINGS[$route] ?? $route) ?></h3>
    <?php foreach ($list as $p): $n++; $c = $p['current']; ?>
      <div class="receita-item">
        <p class="receita-nome"><?= $n ?>. <?= e($p['drug_name']) ?> — <?= e($c['dose']) ?></p>
        <p class="receita-freq"><?= e($c['frequency']) ?></p>
        <?php if (trim((string) ($c['instructions'] ?? '')) !== ''): ?><p class="receita-orient"><?= nl2br(e($c['instructions'])) ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
<footer class="receita-rodape">
  <p><?= e(($head['rx_city'] !== '' ? $head['rx_city'] . ', ' : '') . $dateText) ?></p>
  <p class="receita-assinatura">______________________________</p>
  <p><?= e($doctorName) ?></p>
  <?php if ($register !== ''): ?><p>CRM <?= e($register) ?></p><?php endif; ?>
</footer>
</main>
<script src="<?= e(url('/assets/js/print-button.js')) ?>" defer></script>
</body>
</html>
