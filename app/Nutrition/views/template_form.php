<?php
/** @var string $title_value @var string $mode @var string[] $errors */
?>
<h1>Novo modelo <?= $mode === 'estruturado' ? 'estruturado' : 'em texto' ?></h1>
<p><a href="<?= e(url('/modelos-plano')) ?>">Voltar aos modelos</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/modelos-plano/novo')) ?>" class="cartao">
  <?= csrf_field() ?>
  <input type="hidden" name="modo" value="<?= e($mode) ?>">
  <p class="campo"><label for="titulo">Título do modelo *</label>
    <input id="titulo" name="titulo" value="<?= e($title_value) ?>" maxlength="150" required data-ditado></p>
  <p class="ajuda">Depois de criar, você abre o editor para montar o conteúdo.</p>
  <p><button type="submit" class="btn">Criar e abrir o editor</button></p>
</form>
