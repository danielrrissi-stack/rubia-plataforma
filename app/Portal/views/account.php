<?php
/** @var string[] $errors @var bool $totpOn */
?>
<p class="portal-links"><a href="<?= e(url('/portal')) ?>">&larr; Voltar</a></p>
<h1>Minha conta</h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<h2>Trocar senha</h2>
<form method="post" action="<?= e(url('/portal/conta/senha')) ?>">
  <?= csrf_field() ?>
  <div class="campo"><label for="atual">Senha atual</label><input type="password" id="atual" name="atual" autocomplete="current-password" required></div>
  <div class="campo"><label for="nova">Nova senha</label><input type="password" id="nova" name="nova" autocomplete="new-password" minlength="10" required>
    <p class="ajuda">Pelo menos 10 caracteres.</p></div>
  <div class="campo"><label for="confirmacao">Repita a nova senha</label><input type="password" id="confirmacao" name="confirmacao" autocomplete="new-password" required></div>
  <button type="submit" class="btn">Trocar senha</button>
</form>
<h2>Verificação em duas etapas</h2>
<?php if ($totpOn): ?>
  <p><span class="selo">ativa</span> Sua conta está protegida com um código extra.</p>
<?php else: ?>
  <p>Um código extra no celular deixa sua conta mais segura.</p>
  <p><a class="btn btn-quiet" href="<?= e(url('/2fa/configurar')) ?>">Ativar verificação em duas etapas</a></p>
<?php endif; ?>
