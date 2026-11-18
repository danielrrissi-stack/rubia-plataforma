<?php
declare(strict_types=1);
/**
 * Painel clínico. Variáveis: $content, $title (opcional), $nav (opcional: [[rótulo, caminho], ...]),
 * $userName (opcional), $active (caminho ativo, opcional).
 */
if (!isset($nav)) {
    $nav = [['Pacientes', '/pacientes']];
    $uid = \App\Core\Session::get('uid');
    $navUser = is_int($uid) ? \App\Core\Container::users()->find($uid) : null;
    if ($navUser !== null && in_array($navUser['role'], ['medico', 'nutricionista', 'secretaria'], true)) {
        $nav[] = ['Agenda', '/agenda'];
    }
    if ($navUser !== null && \App\Core\Container::gate()->check($navUser, 'edit', \App\Access\Policy::NUTRICAO, null)) {
        $nav[] = ['Alimentos', '/alimentos'];
        $nav[] = ['Modelos', '/modelos-plano'];
    }
    if ($navUser !== null && in_array($navUser['role'], ['medico', 'nutricionista'], true)) {
        $nav[] = ['Biblioteca de metas', '/metas-modelo'];
    }
    if ($navUser !== null && \App\Labs\Clinician::is($navUser)) {
        $nav[] = ['Catálogo de exames', '/catalogo-exames'];
        $nav[] = ['Cartões educativos', '/cartoes'];
    }
    if ($navUser !== null && \App\Core\Container::gate()->check($navUser, 'view', \App\Access\Policy::USUARIOS, null)) {
        $nav[] = ['Equipe', '/equipe'];
    }
    if ($navUser !== null && \App\Core\Container::gate()->check($navUser, 'view', \App\Access\Policy::AUDITORIA, null)) {
        $nav[] = ['Backups', '/admin/backups'];
    }
    if ($navUser !== null && (int) ($navUser['is_admin'] ?? 0) === 1) {
        $nav[] = ['Receita (cabeçalho)', '/admin/receita'];
    }
    if ($navUser !== null && $navUser['role'] === 'medico' && (int) ($navUser['is_admin'] ?? 0) === 1) {
        $nav[] = ['Agenda (avisos)', '/admin/agenda'];
    }
}
$active = $active ?? '';
$flashOk = \App\Core\Session::flash('ok');
$flashErr = \App\Core\Session::flash('erro');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e(isset($title) ? $title . ' · Dra. Rubia' : 'Dra. Rubia') ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>">
</head>
<body class="clinico">
<a class="skip" href="#conteudo">Ir para o conteúdo</a>
<header class="topbar">
  <a class="brand" href="<?= e(url('/')) ?>"><span class="brand-mark" aria-hidden="true"></span>Dra. Rubia</a>
  <nav class="nav" aria-label="Principal">
    <?php foreach ($nav as [$label, $path]): ?>
      <a href="<?= e(url($path)) ?>"<?= $active === $path ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!empty($userName)): ?>
  <form class="topbar-user" method="post" action="<?= e(url('/logout')) ?>">
    <?= csrf_field() ?>
    <span><?= e($userName) ?></span>
    <button type="submit" class="btn btn-quiet">Sair</button>
  </form>
  <?php endif; ?>
</header>
<main id="conteudo" class="conteudo">
  <?php if ($flashOk): ?><p class="aviso aviso-ok" role="status"><?= e($flashOk) ?></p><?php endif; ?>
  <?php if ($flashErr): ?><p class="aviso aviso-erro" role="alert"><?= e($flashErr) ?></p><?php endif; ?>
  <?= $content ?>
</main>
</body>
</html>
