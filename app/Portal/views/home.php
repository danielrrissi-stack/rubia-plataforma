<?php
/** @var array $patient @var string $first @var bool $many */
$pid = (int) $patient['id'];
?>
<h1>Olá! Bem-vindo ao acompanhamento</h1>
<p class="portal-lead">Acompanhamento de <strong><?= e($first) ?></strong></p>
<ul class="portal-lista">
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $pid . '/crescimento')) ?>">
    <span class="portal-cartao-titulo">Crescimento</span>
    <span class="portal-cartao-texto">Veja como <?= e($first) ?> está se desenvolvendo.</span>
  </a></li>
</ul>
<p><a class="btn" href="<?= e(url('/portal/p/' . $pid . '/medidas/nova')) ?>">Registrar medida</a></p>
<p class="portal-links">
  <?php if ($many): ?><a href="<?= e(url('/portal')) ?>">Trocar de pessoa</a> · <?php endif; ?>
  <a href="<?= e(url('/portal/conta')) ?>">Minha conta</a>
</p>
