<?php
/** @var ?string $error */
?>
<section class="auth-card">
  <h1>Crie a sua senha</h1>
  <p>Você entrou com uma senha provisória. Antes de continuar, escolha uma senha nova só sua.</p>
  <?php if ($error): ?><p class="aviso aviso-erro" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/senha/trocar')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="nova">Nova senha</label>
      <input type="password" id="nova" name="nova" minlength="10" autocomplete="new-password" required autofocus>
      <p class="ajuda">Pelo menos 10 caracteres.</p>
    </div>
    <div class="campo">
      <label for="confirmacao">Repita a nova senha</label>
      <input type="password" id="confirmacao" name="confirmacao" minlength="10" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn">Salvar senha</button>
  </form>
</section>
