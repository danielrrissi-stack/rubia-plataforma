<?php
/** @var ?string $error */
?>
<section class="auth-card">
  <h1>Definir senha</h1>
  <?php if ($error): ?><p class="aviso aviso-erro" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="senha">Nova senha</label>
      <input type="password" id="senha" name="senha" minlength="10" autocomplete="new-password" required autofocus>
      <p class="ajuda">Mínimo de 10 caracteres.</p>
    </div>
    <div class="campo">
      <label for="confirmacao">Confirmação da senha</label>
      <input type="password" id="confirmacao" name="confirmacao" minlength="10" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn">Salvar senha</button>
  </form>
</section>
