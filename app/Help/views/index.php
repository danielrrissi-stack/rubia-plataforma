<?php
/** @var string $role @var array $sections @var bool $isAdmin */
use App\Help\Manual;
?>
<article class="manual">
<h1><?= e(Manual::title($role)) ?></h1>
<p class="aviso manual-ficticio"><?= e(Manual::NOTICE) ?></p>
<p><a class="btn" href="<?= e(url(Manual::pdfPath($role))) ?>" download>Baixar PDF</a></p>

<?php if ($isAdmin): ?>
<nav class="manual-perfis" aria-label="Manuais de cada perfil">
  <p>Manuais de cada perfil:</p>
  <ul>
    <?php foreach (Manual::ROLES as $r): ?>
      <li><a href="<?= e(url('/ajuda/' . Manual::slug($r))) ?>"<?= $r === $role ? ' aria-current="page"' : '' ?>><?= e(Manual::title($r)) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php endif; ?>

<nav class="cartao manual-indice" aria-labelledby="h-indice">
  <h2 id="h-indice">Índice</h2>
  <ol>
    <?php foreach ($sections as $s): ?>
      <li><a href="#<?= e($s['id']) ?>"><?= e($s['title']) ?></a></li>
    <?php endforeach; ?>
  </ol>
</nav>

<?php foreach ($sections as $s): ?>
<section class="cartao manual-secao" id="<?= e($s['id']) ?>" aria-labelledby="h-<?= e($s['id']) ?>">
  <h2 id="h-<?= e($s['id']) ?>"><?= e($s['title']) ?><?= $s['admin'] ? ' <small>(só para administradora)</small>' : '' ?></h2>
  <?php if ($s['intro'] !== null): ?><p><?= e($s['intro']) ?></p><?php endif; ?>
  <?php foreach ($s['images'] as $img): ?>
    <figure class="manual-foto manual-foto-<?= e($img['width']) ?>">
      <img src="<?= e(url('/assets/manual/' . $img['file'])) ?>" alt="<?= e($img['caption']) ?>" loading="lazy">
      <figcaption><?= e($img['caption']) ?></figcaption>
    </figure>
  <?php endforeach; ?>
  <ol class="manual-passos">
    <?php foreach ($s['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?>
  </ol>
  <?php if ($s['tip'] !== null): ?><p class="dica"><strong>Dica:</strong> <?= e($s['tip']) ?></p><?php endif; ?>
  <p class="manual-topo"><a href="#h-indice">Voltar ao índice</a></p>
</section>
<?php endforeach; ?>
</article>
