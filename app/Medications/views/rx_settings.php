<?php
/** @var array<string,string> $values @var string[] $errors @var array<string,string> $labels @var array<string,int> $limits */
?>
<h1>Receita — cabeçalho</h1>
<section class="cartao" id="papel-timbrado" aria-labelledby="h-papel">
  <h2 id="h-papel">Papel timbrado em branco</h2>
  <p class="ajuda">Folha para imprimir e preencher à mão. O telefone, o endereço e a cidade vêm do formulário abaixo; nome, especialidade, CRM e RQE vêm do seu cadastro.</p>
  <?php $papelBase = '/admin/receita/papel/'; include dirname(__DIR__, 2) . '/Letterhead/views/_buttons.php'; ?>
</section>
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
