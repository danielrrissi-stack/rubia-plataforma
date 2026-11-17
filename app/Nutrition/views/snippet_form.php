<?php
/** @var ?array $snippet @var array $values @var string[] $errors */
?>
<h1><?= $snippet === null ? 'Novo trecho' : 'Editar trecho' ?></h1>
<p><a href="<?= e(url('/trechos')) ?>">Voltar aos trechos</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" action="<?= e(url($snippet === null ? '/trechos/novo' : '/trechos/' . $snippet['id'] . '/editar')) ?>" class="cartao">
  <?= csrf_field() ?>
  <p class="campo"><label for="titulo">Título *</label>
    <input id="titulo" name="titulo" value="<?= e($values['title']) ?>" maxlength="100" required></p>
  <p class="campo"><label for="corpo">Texto *</label>
    <textarea id="corpo" name="corpo" rows="8" maxlength="5000" required><?= e($values['body']) ?></textarea></p>
  <p><button type="submit" class="btn">Salvar</button></p>
</form>
