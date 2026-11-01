<?php
/** @var ?string $error @var string $email */
?>
<section class="auth-card">
  <h1>Entrar</h1>
  <?php if ($error): ?><p class="aviso aviso-erro" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    </div>
    <div class="campo">
      <label for="senha">Senha</label>
      <input type="password" id="senha" name="senha" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn">Entrar</button>
  </form>
  <p class="ajuda"><a href="<?= e(url('/senha/esqueci')) ?>">Esqueci minha senha</a></p>
</section>
