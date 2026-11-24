<?php
/** @var bool $sent */
?>
<section class="auth-card">
  <h1>Esqueci minha senha</h1>
  <?php if ($sent): ?>
    <p class="aviso aviso-ok" role="status">Se o e-mail estiver cadastrado, enviaremos um link para redefinir a senha.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/senha/esqueci')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" autocomplete="username" required autofocus>
    </div>
    <button type="submit" class="btn">Enviar link</button>
  </form>
  <p class="ajuda"><a href="<?= e(url('/login')) ?>">Voltar para o login</a></p>
</section>
