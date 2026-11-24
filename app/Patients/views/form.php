<?php
/** @var ?array $patient @var array $values @var string[] $errors @var array $doctors @var ?int $defaultDoctor */
$action = $patient === null ? '/pacientes/novo' : '/pacientes/' . $patient['id'] . '/editar';
$selDoctor = $values['profissional'] !== '' ? (int) $values['profissional'] : (int) $defaultDoctor;
$field = static function (string $id, string $label, array $values, string $type = 'text', bool $required = false, int $max = 0, string $help = ''): void {
    echo '<div class="campo"><label for="' . e($id) . '">' . e($label) . '</label>'
        . '<input type="' . e($type) . '" id="' . e($id) . '" name="' . e($id) . '" value="' . e($values[$id]) . '"'
        . ($max > 0 ? ' maxlength="' . $max . '"' : '') . ($required ? ' required' : '') . '>'
        . ($help !== '' ? '<p class="ajuda">' . e($help) . '</p>' : '') . '</div>';
};
?>
<h1><?= $patient === null ? 'Novo paciente' : 'Editar paciente' ?></h1>
<?php if ($errors !== []): ?>
  <div class="aviso aviso-erro" role="alert">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url($action)) ?>">
  <?= csrf_field() ?>
  <?php $field('nome', 'Nome completo', $values, 'text', true, 150); ?>
  <?php $field('nome_social', 'Nome social (opcional)', $values, 'text', false, 150); ?>
  <?php $field('data_nascimento', 'Data de nascimento', $values, 'text', true, 10, 'Formato dd/mm/aaaa.'); ?>
  <div class="campo">
    <label for="sexo">Sexo biológico — usado nas curvas de crescimento</label>
    <select id="sexo" name="sexo" required>
      <option value="">Selecione</option>
      <option value="F"<?= $values['sexo'] === 'F' ? ' selected' : '' ?>>Feminino</option>
      <option value="M"<?= $values['sexo'] === 'M' ? ' selected' : '' ?>>Masculino</option>
    </select>
  </div>
  <?php $field('cpf', 'CPF (opcional)', $values, 'text', false, 14); ?>
  <?php $field('cns', 'CNS — Cartão Nacional de Saúde (opcional)', $values, 'text', false, 20); ?>
  <?php $field('telefone', 'Telefone', $values, 'text', false, 30); ?>
  <?php $field('email', 'E-mail (opcional)', $values, 'email', false, 190); ?>
  <div class="campo">
    <label for="endereco">Endereço (opcional)</label>
    <textarea id="endereco" name="endereco" rows="2"><?= e($values['endereco']) ?></textarea>
  </div>
  <div class="campo">
    <label for="observacoes">Observações administrativas (opcional)</label>
    <textarea id="observacoes" name="observacoes" rows="3"><?= e($values['observacoes']) ?></textarea>
  </div>
  <div class="campo">
    <label for="profissional">Profissional responsável</label>
    <select id="profissional" name="profissional" required>
      <?php foreach ($doctors as $d): ?>
        <option value="<?= e($d['id']) ?>"<?= (int) $d['id'] === $selDoctor ? ' selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn">Salvar</button>
  <a href="<?= e(url($patient === null ? '/pacientes' : '/pacientes/' . $patient['id'])) ?>">Cancelar</a>
</form>
