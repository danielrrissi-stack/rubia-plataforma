<?php
/**
 * Manual em PDF (Dompdf). Variaveis: $role, $sections, $date (d/m/Y), $images (file => data URI).
 * @var string $role @var array $sections @var string $date @var array<string,string> $images
 */
use App\Help\Manual;
?>
<div class="manual-capa">
  <p class="manual-marca">Dra. Rubia — plataforma de acompanhamento</p>
  <h1><?= e(Manual::title($role)) ?></h1>
  <p>Versão de <?= e($date) ?></p>
  <p class="manual-aviso"><?= e(Manual::NOTICE) ?></p>
  <h2>Índice</h2>
  <ol>
    <?php foreach ($sections as $s): ?>
      <li><?= e($s['title']) ?><?= $s['admin'] ? ' (só para administradora)' : '' ?></li>
    <?php endforeach; ?>
  </ol>
</div>
<?php foreach ($sections as $i => $s): ?>
<div class="manual-secao">
  <h2><?= ($i + 1) . '. ' . e($s['title']) ?><?= $s['admin'] ? ' (só para administradora)' : '' ?></h2>
  <?php if ($s['intro'] !== null): ?><p><?= e($s['intro']) ?></p><?php endif; ?>
  <ol>
    <?php foreach ($s['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?>
  </ol>
  <?php if ($s['tip'] !== null): ?><p class="manual-dica"><strong>Dica:</strong> <?= e($s['tip']) ?></p><?php endif; ?>
  <?php foreach ($s['images'] as $img): ?>
    <?php if (isset($images[$img['file']])): ?>
    <div class="manual-foto manual-foto-<?= e($img['width']) ?>">
      <img src="<?= e($images[$img['file']]) ?>" alt="">
      <p class="ajuda"><?= e($img['caption']) ?></p>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
