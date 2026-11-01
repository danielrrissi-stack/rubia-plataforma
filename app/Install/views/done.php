<?php
/** @var string $pem */
/** @var string $warning */
/** @var string $loginUrl */
?>
<section class="instalador" style="max-width:40rem;margin:1.5rem auto">
  <h1>Instalação concluída</h1>
  <p class="aviso aviso-ok" role="status">O sistema foi instalado. Ao entrar pela primeira vez, você será levada a configurar a verificação em duas etapas.</p>
  <?php if ($warning !== ''): ?><p class="aviso aviso-erro" role="alert"><?= e($warning) ?></p><?php endif; ?>

  <h2>Chave privada de backup</h2>
  <p class="aviso aviso-erro" role="alert"><strong>Copie e salve este conteúdo em um arquivo chamado backup_private.pem, guardado fora do sistema (pendrive/cofre), junto com a senha de backup.</strong> Esta tela não será exibida novamente.</p>
  <label for="chave">Conteúdo de backup_private.pem</label>
  <textarea id="chave" readonly rows="14" style="width:100%;font-family:monospace" onfocus="this.select()"><?= e($pem) ?></textarea>
  <p class="ajuda">Mais tarde, a administradora poderá baixar esta chave novamente na página Backups.</p>

  <p><a class="btn" href="<?= e($loginUrl) ?>">Ir para o login</a></p>
</section>
