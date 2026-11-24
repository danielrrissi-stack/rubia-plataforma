<?php
/**
 * @var array $patient @var ?array $child @var ?string $notice @var string $temp @var bool $hasPatientAccount
 */
$pid = (int) $patient['id'];
$action = url('/pacientes/' . $pid . '/acesso-crianca');
?>
<h1>Conta da criança — <?= e($patient['social_name'] ?: $patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a></p>
<p class="ajuda">Login próprio, opcional, para a criança ou o adolescente ver e marcar as próprias metas. Com ele, o paciente vê só a área de metas — nada de crescimento, plano ou exames.</p>

<?php if ($notice !== null): ?><p class="aviso aviso-ok" role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if ($temp !== ''): ?>
<section class="cartao" aria-labelledby="h-senha">
  <h2 id="h-senha">Senha provisória</h2>
  <p>Usuário: <strong><?= e($child['username'] ?? '') ?></strong></p>
  <p>Senha provisória: <code class="senha-provisoria"><?= e($temp) ?></code></p>
  <p class="ajuda">Anote agora: esta senha aparece só desta vez. No primeiro acesso, o paciente terá de criar uma senha própria.</p>
</section>
<?php endif; ?>

<?php if ($child === null): ?>
  <?php if ($hasPatientAccount): ?>
    <p>Este paciente já tem acesso próprio (conta de paciente); a conta de criança não está disponível.</p>
  <?php else: ?>
  <h2>Criar conta</h2>
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="criar">
    <div class="campo">
      <label for="usuario">Nome de usuário</label>
      <input type="text" id="usuario" name="usuario" required minlength="3" maxlength="40" pattern="[a-z0-9.]{3,40}" autocapitalize="none" spellcheck="false" autocomplete="off">
      <p class="ajuda">Letras minúsculas, números e ponto (ex.: ana.souza). A senha provisória é gerada pelo sistema.</p>
    </div>
    <button type="submit" class="btn">Criar conta</button>
  </form>
  <?php endif; ?>
<?php else: ?>
  <h2>Conta</h2>
  <dl>
    <dt>Usuário</dt><dd><?= e($child['username']) ?></dd>
    <dt>Situação</dt><dd><?= (int) $child['active'] === 1 ? 'Ativa' : 'Desativada' ?><?= (int) $child['must_change_password'] === 1 ? ' · aguardando a troca da senha provisória' : '' ?></dd>
  </dl>
  <form method="post" action="<?= e($action) ?>" class="form-inline">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="redefinir">
    <button type="submit" class="btn btn-quiet">Redefinir senha (gera nova provisória)</button>
  </form>
  <form method="post" action="<?= e($action) ?>" class="form-inline">
    <?= csrf_field() ?>
    <?php if ((int) $child['active'] === 1): ?>
      <input type="hidden" name="acao" value="desativar">
      <button type="submit" class="btn btn-quiet">Desativar conta</button>
    <?php else: ?>
      <input type="hidden" name="acao" value="reativar">
      <button type="submit" class="btn btn-quiet">Reativar conta</button>
    <?php endif; ?>
  </form>

  <h2>Sigilo</h2>
  <form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="sigilo">
    <p class="campo"><label><input type="checkbox" name="ver_marcacoes" value="1"<?= (int) $patient['guardians_see_child_checks'] === 1 ? ' checked' : '' ?>> Responsáveis veem as marcações feitas pelo próprio paciente</label></p>
    <button type="submit" class="btn">Salvar</button>
  </form>
<?php endif; ?>
