<?php
/** @var ?array $user @var array $values @var string[] $errors @var array $roles */
$action = $user === null ? '/equipe/novo' : '/equipe/' . $user['id'] . '/editar';
?>
<h1><?= $user === null ? 'Novo usuário' : 'Editar usuário' ?></h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <div class="campo">
    <label for="nome">Nome</label>
    <input type="text" id="nome" name="nome" value="<?= e($values['nome']) ?>" maxlength="150" required>
  </div>
  <div class="campo">
    <label for="email">E-mail</label>
    <input type="email" id="email" name="email" value="<?= e($values['email']) ?>" maxlength="190" required>
  </div>
  <div class="campo">
    <label for="papel">Papel</label>
    <select id="papel" name="papel" required>
      <option value="">Selecione</option>
      <?php foreach ($roles as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $values['papel'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="registro">Registro profissional (CRM/CRN)</label>
    <input type="text" id="registro" name="registro" value="<?= e($values['registro']) ?>" maxlength="30">
    <p class="ajuda">Obrigatório para médico(a) e nutricionista.</p>
  </div>
  <div class="campo">
    <label><input type="checkbox" name="administrador" value="1"<?= $values['administrador'] ? ' checked' : '' ?>> Administrador (somente médico)</label>
  </div>
  <button type="submit" class="btn">Salvar</button>
  <a href="<?= e(url('/equipe')) ?>">Cancelar</a>
</form>
