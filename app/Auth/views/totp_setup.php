<?php /** @var ?string $error @var string $grouped @var string $uri @var bool $forced */ ?>
<section class="auth-card">
  <h1>Verificação em duas etapas</h1>
  <?php if ($forced): ?><p>Para acessar o sistema, ative a verificação em duas etapas.</p><?php endif; ?>
  <p>1. Instale um aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy) e leia o QR code abaixo.</p>
  <div id="totp-qr" data-otpauth="<?= e($uri) ?>" role="img" aria-label="QR code para o aplicativo autenticador"></div>
  <p>Ou digite a chave manualmente: <code class="totp-secret"><?= e($grouped) ?></code></p>
  <p>2. Digite o código de 6 dígitos exibido no aplicativo para confirmar.</p>
  <?php if ($error): ?><p class="aviso aviso-erro" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/2fa/configurar')) ?>">
    <?= csrf_field() ?>
    <div class="campo">
      <label for="codigo">Código</label>
      <input type="text" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required>
    </div>
    <button type="submit" class="btn">Ativar</button>
  </form>
</section>
<script src="<?= e(url('/assets/vendor/qrcode.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/totp-setup.js')) ?>"></script>
