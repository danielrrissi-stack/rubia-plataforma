<?php
/** @var array<string,string> $values @var string[] $errors @var array<string,string> $labels @var array<string,int> $limits */
?>
<h1>Receita — cabeçalho</h1>
<p class="ajuda">Estes dados aparecem no topo (e a cidade no rodapé) da receita impressa. Campos vazios são omitidos.</p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/receita')) ?>">
  <?= csrf_field() ?>
  <?php foreach ($labels as $key => $label): ?>
    <div class="campo"><label for="<?= e($key) ?>"><?= e($label) ?></label>
      <input type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($values[$key] ?? '') ?>" maxlength="<?= (int) $limits[$key] ?>"></div>
  <?php endforeach; ?>
  <button type="submit" class="btn">Salvar</button>
</form>
