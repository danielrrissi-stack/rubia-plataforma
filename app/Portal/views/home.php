<?php
/** @var array $patient @var string $first @var bool $many @var bool $hasExams */
$pid = (int) $patient['id'];
?>
<h1>Olá! Bem-vindo ao acompanhamento</h1>
<p class="portal-lead">Acompanhamento de <strong><?= e($first) ?></strong></p>
<ul class="portal-lista">
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/crescimento')) ?>">
    <span class="portal-cartao-titulo">Crescimento</span>
    <span class="portal-cartao-texto">Veja como <?= e($first) ?> está se desenvolvendo.</span>
  </a></li>
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/alimentacao')) ?>">
    <span class="portal-cartao-titulo">Alimentação</span>
    <span class="portal-cartao-texto">Veja o plano alimentar de <?= e($first) ?>.</span>
  </a></li>
  <?php if ($hasExams): ?><li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/exames')) ?>">
    <span class="portal-cartao-titulo">Exames</span>
    <span class="portal-cartao-texto">Veja os exames de <?= e($first) ?> liberados pela Dra. Rubia.</span>
  </a></li><?php endif; ?>
</ul>
<p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/medidas/nova')) ?>">Registrar medida</a></p>
<p class="portal-links">
  <?php if ($many): ?><a href="<?= e(url('/portal')) ?>">Trocar de pessoa</a> · <?php endif; ?>
  <a href="<?= e(url('/portal/conta')) ?>">Minha conta</a>
</p>
