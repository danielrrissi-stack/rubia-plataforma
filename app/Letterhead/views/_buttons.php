<?php
/** Botoes do papel timbrado. @var string $papelBase caminho ate o tipo, com barra no fim */
?>
<p class="acoes-plano"><?php foreach (\App\Letterhead\PaperType::TITLES as $slug => $label): ?>
  <a class="btn" href="<?= e(url($papelBase . $slug)) ?>" target="_blank" rel="noopener"><?= e($label) ?></a>
<?php endforeach; ?></p>
