<?php /** @var ?string $error */ ?>
<section class="auth-card">
  <h1>Verificação em duas etapas</h1>
  <p>A verificação em duas etapas já está ativa. Para cadastrar um novo aplicativo autenticador, digite primeiro o código atual exibido no aplicativo que você usa hoje.</p>
  <p>Perdeu o celular? Peça ao administrador para usar <strong>Redefinir 2FA</strong> na tela Equipe.</p>
  <?php if ($error): ?><p class="aviso aviso-erro" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/2fa/configurar')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="codigo_atual">Código atual</label>
      <input type="text" id="codigo_atual" name="codigo_atual" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
    </div>
    <button type="submit" class="btn">Continuar</button>
  </form>
</section>
