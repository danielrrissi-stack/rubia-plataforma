<?php
/** @var string $pem */
/** @var string $warning */
/** @var string $loginUrl */
?>
<section class="instalador">
  <h1>Instalação concluída</h1>
  <p class="aviso aviso-ok" role="status">O sistema foi instalado. Ao entrar pela primeira vez, você será levada a configurar a verificação em duas etapas.</p>
  <?php if ($warning !== ''): ?><p class="aviso aviso-erro" role="alert"><?= e($warning) ?></p><?php endif; ?>

  <h2>Chave privada de backup</h2>
  <p class="aviso aviso-erro" role="alert"><strong>Copie e salve este conteúdo em um arquivo chamado backup_private.pem, guardado fora do sistema (pendrive/cofre), junto com a senha de backup.</strong> Esta tela não será exibida novamente.</p>
  <label for="chave">Conteúdo de backup_private.pem</label>
  <textarea id="chave" class="chave-pem" readonly rows="14" data-select-on-focus><?= e($pem) ?></textarea>
  <p class="ajuda">Mais tarde, a administradora poderá baixar esta chave novamente na página Backups.</p>

  <p><a class="btn" href="<?= e($loginUrl) ?>">Ir para o login</a></p>
</section>
<script src="<?= e(url('/assets/js/select-on-focus.js')) ?>"></script>
