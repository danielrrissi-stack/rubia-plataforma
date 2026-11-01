<?php
/** @var array $patient @var array $guardians @var array $values @var string[] $errors @var string[] $relationships */
$pid = (int) $patient['id'];
?>
<h1>Responsáveis — <?= e($patient['full_name']) ?></h1>
<p><a href="<?= e(url('/pacientes/' . $pid)) ?>">Voltar ao paciente</a></p>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($guardians === []): ?>
  <p>Nenhum responsável vinculado.</p>
<?php else: ?>
<table class="tabela">
  <thead><tr><th>Nome</th><th>E-mail</th><th>Parentesco</th><th>Acesso</th><th>Ações</th></tr></thead>
  <tbody>
  <?php foreach ($guardians as $g): ?>
    <tr>
      <td><?= e($g['user_name']) ?></td>
      <td><?= e($g['user_email']) ?></td>
      <td><?= e($g['relationship']) ?></td>
      <td><?= (int) $g['has_password'] === 1 ? 'Ativo' : 'Convite pendente' ?></td>
      <td>
        <form method="post" action="<?= e(url('/responsaveis/' . $g['id'] . '/remover')) ?>" class="form-inline">
          <?= csrf_field() ?><button type="submit" class="btn btn-quiet">Remover</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Adicionar responsável</h2>
<form method="post" action="<?= e(url('/pacientes/' . $pid . '/responsaveis')) ?>">
  <?= csrf_field() ?>
  <div class="campo">
    <label for="nome">Nome</label>
    <input type="text" id="nome" name="nome" value="<?= e($values['nome']) ?>" maxlength="150">
  </div>
  <div class="campo">
    <label for="email">E-mail</label>
    <input type="email" id="email" name="email" value="<?= e($values['email']) ?>" maxlength="190" required>
    <p class="ajuda">Se o e-mail já pertence a um responsável, o mesmo login é reaproveitado.</p>
  </div>
  <div class="campo">
    <label for="parentesco">Parentesco</label>
    <select id="parentesco" name="parentesco" required>
      <option value="">Selecione</option>
      <?php foreach ($relationships as $rel): ?>
        <option value="<?= e($rel) ?>"<?= $values['parentesco'] === $rel ? ' selected' : '' ?>><?= e(ucfirst($rel)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn">Adicionar</button>
</form>
