<?php
/** @var array $items */
?>
<h1>Olá! Bem-vindo ao acompanhamento</h1>
<p class="portal-lead">Quem você quer acompanhar agora?</p>
<?php if ($items === []): ?>
  <p class="aviso aviso-erro">Ainda não há ninguém ligado à sua conta. Fale com a equipe da Dra. Rubia.</p>
<?php else: ?>
<ul class="portal-lista">
  <?php foreach ($items as $it): ?>
  <li><a class="portal-cartao" href="<?= e(url('/portal/p/' . $it['id'])) ?>">
    <span class="portal-cartao-titulo"><?= e($it['first']) ?></span>
    <span class="portal-cartao-texto"><?= e($it['age']) ?></span>
  </a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
