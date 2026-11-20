<?php
/**
 * Documento do atestado (gravado em certificates.body_html; usado na previa e no PDF).
 * @var string $doctorName @var string $credentials @var string $signCredentials @var string $title
 * @var list<string> $paragraphs HTML ja escapado @var string $dateLine @var string $phone @var string $address
 */
?><main class="atestado">
<header class="atestado-cabecalho">
  <h1><?= e(mb_strtoupper($doctorName)) ?></h1>
  <?php if ($credentials !== ''): ?><p><?= e($credentials) ?></p><?php endif; ?>
</header>
<h2 class="atestado-titulo"><?= e($title) ?></h2>
<div class="atestado-corpo">
<?php foreach ($paragraphs as $p): ?>
  <p><?= $p ?></p>
<?php endforeach; ?>
</div>
<footer class="atestado-rodape">
  <p class="atestado-data"><?= e($dateLine) ?></p>
  <p class="atestado-linha">__________________________________</p>
  <p><strong><?= e($doctorName) ?></strong></p>
  <?php if ($signCredentials !== ''): ?><p><?= e($signCredentials) ?></p><?php endif; ?>
  <p>Assinatura e carimbo</p>
  <?php if ($phone !== '' || $address !== ''): ?>
  <div class="atestado-contato">
    <?php if ($phone !== ''): ?><p>Telefone: <?= e($phone) ?></p><?php endif; ?>
    <?php if ($address !== ''): ?><p>Endereço: <?= e($address) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>
</footer>
</main>
