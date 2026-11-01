<?php
declare(strict_types=1);
/** Portal da família. Variáveis: $content, $title (opcional), $userName (opcional). */
$flashOk = \App\Core\Session::flash('ok');
$flashErr = \App\Core\Session::flash('erro');
?><!doctype html>
<html lang="pt-BR" data-base="<?= e(rtrim(url('/'), '/')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e(isset($title) ? $title . ' · Dra. Rubia' : 'Dra. Rubia') ?></title>
<link rel="manifest" href="<?= e(url('/manifest.json')) ?>">
<meta name="theme-color" content="#2f6b5f">
<link rel="apple-touch-icon" href="<?= e(url('/assets/icons/icon-192.png')) ?>">
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="portal">
<a class="skip" href="#conteudo">Ir para o conteúdo</a>
<header class="portal-topo">
  <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark" aria-hidden="true"></span>Dra. Rubia</a>
  <?php if (!empty($userName)): ?>
  <form method="post" action="<?= e(url('/logout')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-quiet">Sair</button>
  </form>
  <?php endif; ?>
</header>
<main id="conteudo" class="conteudo">
  <?php if ($flashOk): ?><p class="aviso aviso-ok" role="status"><?= e($flashOk) ?></p><?php endif; ?>
  <?php if ($flashErr): ?><p class="aviso aviso-erro" role="alert"><?= e($flashErr) ?></p><?php endif; ?>
  <?= $content ?>
</main>
<footer class="portal-rodape">Em caso de dúvida, fale com a equipe da Dra. Rubia.</footer>
<script src="<?= e(url('/assets/js/portal-sw.js')) ?>"></script>
</body>
</html>
