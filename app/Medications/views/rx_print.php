<?php
/** @var array $patient @var string $pdfUrl (e as variaveis de _rx_doc.php) */
$pid = (int) $patient['id'];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Receituário · Dra. Rubia</title>
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body class="impressao">
<p class="nao-imprimir"><button type="button" class="btn" data-print>Imprimir</button>
  <a class="btn" href="<?= e($pdfUrl) ?>">Baixar PDF</a>
  <a href="<?= e(url('/pacientes/' . $pid . '/receita')) ?>">Voltar</a></p>
<?php require __DIR__ . '/_rx_doc.php'; ?>
<script src="<?= e(url('/assets/js/print-button.js')) ?>" defer></script>
</body>
</html>
