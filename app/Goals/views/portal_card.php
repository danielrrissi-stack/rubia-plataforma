<?php
/**
 * @var int $pid @var array $card @var bool $isChild
 */
?>
<article class="portal-resumo">
  <h1><span aria-hidden="true"><?= e($card['emoji']) ?></span> <?= e($card['title']) ?></h1>
  <?php foreach (preg_split("/\n\s*\n/", (string) $card['body']) ?: [] as $para): ?>
    <p><?= nl2br(e(trim($para))) ?></p>
  <?php endforeach; ?>
</article>
<p class="portal-links">
  <a href="<?= e(url('/portal/p/' . $pid . '/metas#para-ler')) ?>">&larr; Voltar às metas</a>
  <?php if (!$isChild): ?> · <a href="<?= e(url('/portal/p/' . $pid)) ?>">Início</a><?php endif; ?>
</p>
