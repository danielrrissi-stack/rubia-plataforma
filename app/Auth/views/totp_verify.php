<?php /** @var ?string $error */ ?>
<section class="auth-card">
  <h1>Verificação em duas etapas</h1>
  <p>Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
  <?php if ($error): ?><p class="aviso aviso-erro" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/2fa')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="codigo">Código</label>
      <input type="text" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
    </div>
    <button type="submit" class="btn">Verificar</button>
  </form>
</section>
