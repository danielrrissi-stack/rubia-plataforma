<?php
/**
 * Textos do aviso de consulta. Variaveis: $texts (adult/child em uso), $errors, $labels, $keys (kind => chave settings),
 * $defaults, $max.
 */
?>
<h1>Agenda — textos do aviso</h1>
<p class="ajuda">Estes textos vão no botão de WhatsApp da agenda e nos e-mails de lembrete. O texto para menor de 18 anos
  (idade na data da consulta) é dirigido ao responsável. Deixe o campo vazio para usar o texto padrão.</p>
<p class="ajuda">Use os marcadores, que são trocados na hora do envio:</p>
<ul class="ajuda">
  <li><strong>{nome}</strong> — nome social do paciente, ou o primeiro nome;</li>
  <li><strong>{profissional}</strong> — nome da profissional como cadastrado;</li>
  <li><strong>{dia}</strong> — por exemplo “amanhã, quinta-feira, 19/11” (ou “hoje, …”; em outros dias, só “quinta-feira, 19/11”);</li>
  <li><strong>{hora}</strong> — horário da consulta, por exemplo “07:30”;</li>
  <li><strong>{endereço}</strong> — endereço de atendimento da profissional, ou o endereço da receita; sem nenhum dos dois, o trecho “Endereço: {endereço}.” é retirado.</li>
</ul>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/agenda')) ?>">
  <?= csrf_field() ?>
  <?php foreach ($keys as $kind => $key): ?>
    <div class="campo">
      <label for="<?= e($key) ?>"><?= e($labels[$kind]) ?></label>
      <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" rows="5" maxlength="<?= (int) $max ?>"><?= e($texts[$kind]) ?></textarea>
      <p class="ajuda">Até <?= (int) $max ?> caracteres.
        <button type="submit" class="btn btn-quiet" name="restaurar" value="<?= e($kind) ?>">Restaurar texto padrão</button></p>
    </div>
  <?php endforeach; ?>
  <button type="submit" class="btn">Salvar</button>
</form>
